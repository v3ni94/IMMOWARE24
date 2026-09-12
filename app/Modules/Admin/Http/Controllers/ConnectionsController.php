<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Core\Enums\Role;
use App\Modules\Admin\Http\Controllers\Concerns\ResolvesOrganizationConnections;
use App\Modules\Admin\Http\Requests\ConnectionRequest;
use App\Modules\Connector\Models\ImmowareConnection;
use App\Modules\Connector\Models\ImmowareTechnicalUser;
use App\Modules\Connector\Probe\ProbeService;
use App\Modules\Security\Services\PepperedHasher;
use App\Modules\Sync\Models\SyncRun;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Immoware-Verbindung: Liste, Detail, Anlage, Bearbeitung, Probe, Statuswechsel.
 * Alle Aktionen erfordern connections.manage. Die Schreibfreigabe (write_enabled) wird hier nur
 * angezeigt, nie gesetzt (Vier-Augen-Prinzip, docs/immoware/05-write-capabilities.md).
 */
final class ConnectionsController extends AdminController
{
    use ResolvesOrganizationConnections;

    /** @var array<string, string> */
    public const array CONNECTOR_TYPES = [
        'webdav_documents' => 'WebDAV Dokumente (Lesen)',
        'webdav_inbox' => 'WebDAV Posteingang (Schreibpfad, create-only)',
        'carddav_contacts' => 'CardDAV Kontakte (Lesen)',
        'caldav_calendar' => 'CalDAV Kalender (Lesen)',
        'file_import' => 'Dateiimport (CSV, DATEV, CAMT.053)',
        'rest_api_slot' => 'REST-API-Slot (WAITING_FOR_VENDOR_ACCESS)',
    ];

    /** @var array<string, string> */
    public const array STATUS_LABELS = [
        'active' => 'aktiv',
        'paused' => 'pausiert',
        'degraded' => 'degraded',
        'error' => 'Fehler',
    ];

    // Obergrenze der Wartezeit zwischen den beiden PROPFIND der ETag-Prüfung im Web-Request.
    private const int PROBE_MAX_DELAY_SECONDS = 5;

    public function __construct(
        private readonly ProbeService $probe,
        private readonly PepperedHasher $hasher,
    ) {}

    public function index(Request $request): View
    {
        $this->requirePermission('connections.manage');

        $query = ImmowareConnection::query()->with('technicalUser')->orderBy('name');

        $status = (string) $request->query('status', '');
        $type = (string) $request->query('connector_type', '');

        if ($status !== '' && array_key_exists($status, self::STATUS_LABELS)) {
            $query->where('status', $status);
        }

        if ($type !== '' && array_key_exists($type, self::CONNECTOR_TYPES)) {
            $query->where('connector_type', $type);
        }

        return view('admin::connections.index', [
            'connections' => $query->paginate($this->perPage())->withQueryString(),
            'filters' => ['status' => $status, 'connector_type' => $type],
            'statusLabels' => self::STATUS_LABELS,
            'connectorTypes' => self::CONNECTOR_TYPES,
        ]);
    }

    public function show(int $id): View
    {
        $this->requirePermission('connections.manage');

        $connection = $this->findConnection($id);
        $connection->load(['technicalUser', 'pairedReadConnection', 'writeEnabledBy', 'writeConfirmedBy']);

        $probeResult = (array) ($connection->getAttribute('probe_result') ?? []);

        $recentRuns = SyncRun::query()
            ->where('connection_id', $connection->getKey())
            ->orderByDesc('started_at')
            ->limit(5)
            ->get();

        return view('admin::connections.show', [
            'connection' => $connection,
            'probeResult' => $probeResult,
            'probeChecks' => $this->probeChecks($probeResult),
            'strategy' => [
                'strategy' => $probeResult['strategy'] ?? null,
                'etag_stable' => array_key_exists('etag_stable', $probeResult) ? (bool) $probeResult['etag_stable'] : null,
                'sync_token_supported' => array_key_exists('sync_token_supported', $probeResult) ? (bool) $probeResult['sync_token_supported'] : null,
                'ran_at' => isset($probeResult['ran_at']) ? CarbonImmutable::parse((string) $probeResult['ran_at']) : null,
            ],
            'recentRuns' => $recentRuns,
            'statusLabels' => self::STATUS_LABELS,
            'connectorTypes' => self::CONNECTOR_TYPES,
            'canClearDegraded' => $this->currentUser(request())->hasRole(Role::Owner),
        ]);
    }

    public function create(): View
    {
        $this->requirePermission('connections.manage');

        return view('admin::connections.create', [
            'connection' => null,
            'technicalUsers' => $this->technicalUserOptions(),
            'connectorTypes' => self::CONNECTOR_TYPES,
        ]);
    }

    public function store(ConnectionRequest $request): RedirectResponse
    {
        $this->requirePermission('connections.manage');

        $data = $request->validated();
        $this->validateTechnicalUser($data);

        $connection = new ImmowareConnection;
        $connection->forceFill($this->attributesFrom($data, null));
        $connection->setAttribute('organization_id', $this->currentUser($request)->getAttribute('organization_id'));
        $connection->setAttribute('status', 'paused');
        $connection->setAttribute('write_enabled', false);
        $connection->save();

        $this->audit('connections.created', $connection, [], $this->auditable($connection), (int) $connection->getKey());

        return $this->redirectWithStatus('admin.connections.show', 'Connection angelegt. Status pausiert, bitte zunächst die Immoware-Schnittstelle prüfen.', ['id' => $connection->getKey()]);
    }

    public function edit(int $id): View
    {
        $this->requirePermission('connections.manage');

        return view('admin::connections.edit', [
            'connection' => $this->findConnection($id),
            'technicalUsers' => $this->technicalUserOptions(),
            'connectorTypes' => self::CONNECTOR_TYPES,
        ]);
    }

    public function update(ConnectionRequest $request, int $id): RedirectResponse
    {
        $this->requirePermission('connections.manage');

        $connection = $this->findConnection($id);
        $data = $request->validated();
        $this->validateTechnicalUser($data);

        $before = $this->auditable($connection);
        $connection->forceFill($this->attributesFrom($data, $connection));
        $connection->save();

        $this->audit('connections.updated', $connection, $before, $this->auditable($connection), (int) $connection->getKey());

        return $this->redirectWithStatus('admin.connections.show', 'Connection gespeichert.', ['id' => $connection->getKey()]);
    }

    /**
     * Button "Immoware-Schnittstelle prüfen": führt die Probe synchron aus und persistiert das Ergebnis.
     */
    public function probe(Request $request, int $id): RedirectResponse
    {
        $this->requirePermission('connections.manage');

        $connection = $this->findConnection($id);
        $user = $this->currentUser($request);
        $delay = min(self::PROBE_MAX_DELAY_SECONDS, max(0, (int) config('hub.connector.probe.etag_delay_seconds', 60)));

        try {
            $report = $this->probe->run($connection, (int) $user->getKey(), $delay);
        } catch (Throwable $exception) {
            $this->audit('connections.probe_failed', $connection, [], ['error' => $exception::class], (int) $connection->getKey());

            return $this->redirectWithWarning('admin.connections.show', 'Die Probe konnte nicht ausgeführt werden: '.$exception::class, ['id' => $connection->getKey()]);
        }

        $this->audit('connections.probed', $connection, [], [
            'ok' => $report->result->ok,
            'strategy' => $report->strategy?->value,
            'etag_stable' => (bool) ($report->facts['etag_stable'] ?? false),
            'sync_token_supported' => (bool) ($report->facts['sync_token_supported'] ?? false),
            'etag_delay_seconds' => $delay,
        ], (int) $connection->getKey());

        $message = $report->result->ok
            ? 'Probe bestanden. Strategie: '.($report->strategy !== null ? $report->strategy->value : 'keine').'.'
            : 'Probe nicht bestanden. Einzelergebnisse siehe unten.';

        return $report->result->ok
            ? $this->redirectWithStatus('admin.connections.show', $message, ['id' => $connection->getKey()])
            : $this->redirectWithWarning('admin.connections.show', $message, ['id' => $connection->getKey()]);
    }

    /**
     * Statuswechsel mit Bestätigung: activate (paused -> active), pause (active/degraded -> paused),
     * clear_degraded (degraded -> active, nur Owner).
     */
    public function status(Request $request, int $id): RedirectResponse
    {
        $this->requirePermission('connections.manage');
        $this->requireConfirmation($request);

        $connection = $this->findConnection($id);
        $user = $this->currentUser($request);
        $transition = (string) $request->input('transition', '');
        $current = (string) $connection->getAttribute('status');
        $before = ['status' => $current, 'degraded_reason' => $connection->getAttribute('degraded_reason')];

        switch ($transition) {
            case 'activate':
                if ($current !== 'paused') {
                    return $this->redirectWithWarning('admin.connections.show', 'Nur pausierte Connections können aktiviert werden.', ['id' => $id]);
                }

                $connection->forceFill(['status' => 'active']);
                break;

            case 'pause':
                if (! in_array($current, ['active', 'degraded', 'error'], true)) {
                    return $this->redirectWithWarning('admin.connections.show', 'Die Connection ist bereits pausiert.', ['id' => $id]);
                }

                $connection->forceFill(['status' => 'paused']);
                break;

            case 'clear_degraded':
                if ($current !== 'degraded') {
                    return $this->redirectWithWarning('admin.connections.show', 'Die Connection ist nicht degraded.', ['id' => $id]);
                }

                if (! $user->hasRole(Role::Owner)) {
                    abort(403, 'Degraded darf nur die Geschäftsführung (Owner) zurücksetzen.');
                }

                $connection->forceFill(['status' => 'active', 'degraded_reason' => null, 'degraded_cleared_by' => $user->getKey()]);
                break;

            default:
                abort(422, 'Unbekannter Statuswechsel.');
        }

        $connection->save();

        $this->audit('connections.status_changed', $connection, $before, [
            'status' => $connection->getAttribute('status'),
            'transition' => $transition,
            'note' => (string) $request->input('note', ''),
        ], (int) $connection->getKey());

        return $this->redirectWithStatus('admin.connections.show', 'Status geändert: '.(self::STATUS_LABELS[(string) $connection->getAttribute('status')] ?? $connection->getAttribute('status')).'.', ['id' => $id]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributesFrom(array $data, ?ImmowareConnection $existing): array
    {
        $baseUrl = isset($data['base_url']) && $data['base_url'] !== '' ? rtrim((string) $data['base_url'], ' ') : null;

        $attributes = [
            'name' => (string) $data['name'],
            'connector_type' => (string) $data['connector_type'],
            'purpose' => (string) $data['purpose'],
            'base_url' => $baseUrl,
            'base_url_hash' => $baseUrl !== null ? $this->hasher->hash('base_url:'.$baseUrl) : null,
            'technical_user_id' => isset($data['technical_user_id']) && $data['technical_user_id'] !== '' ? (int) $data['technical_user_id'] : null,
            'poll_interval_seconds' => (int) $data['poll_interval_seconds'],
            'rate_limit_rps' => round((float) $data['rate_limit_rps'], 2),
            'allowed_write_prefix' => isset($data['allowed_write_prefix']) && $data['allowed_write_prefix'] !== '' ? (string) $data['allowed_write_prefix'] : null,
        ];

        // Zugangsdaten direkt an der Connection: Passwort nur setzen, wenn eingegeben (Schreibfeld).
        $credentials = is_array($existing?->getAttribute('credentials')) ? $existing->getAttribute('credentials') : [];
        $username = isset($data['username']) ? trim((string) $data['username']) : '';
        $password = isset($data['password']) ? (string) $data['password'] : '';

        if ($username !== '') {
            $credentials['username'] = $username;
        } elseif ($existing === null) {
            unset($credentials['username']);
        }

        if ($password !== '') {
            $credentials['password'] = $password;
        }

        $attributes['credentials'] = $credentials === [] ? null : $credentials;

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function validateTechnicalUser(array $data): void
    {
        if (! isset($data['technical_user_id']) || $data['technical_user_id'] === '') {
            return;
        }

        if (! ImmowareTechnicalUser::query()->whereKey((int) $data['technical_user_id'])->exists()) {
            throw ValidationException::withMessages(['technical_user_id' => 'Der technische Nutzer gehört nicht zu diesem Mandanten.']);
        }
    }

    /**
     * @return array<int, string>
     */
    private function technicalUserOptions(): array
    {
        $options = [];

        foreach (ImmowareTechnicalUser::query()->oldest('label')->lazy(200)->take(200) as $user) {
            $options[(int) $user->getKey()] = $user->getAttribute('label').' ('.$user->getAttribute('username').')';
        }

        return $options;
    }

    /**
     * Felder für den Auditeintrag, ohne Secrets und ohne die Freigabe-URL im Klartext.
     *
     * @return array<string, mixed>
     */
    private function auditable(ImmowareConnection $connection): array
    {
        $credentials = $connection->getAttribute('credentials');

        return [
            'name' => $connection->getAttribute('name'),
            'connector_type' => $connection->getAttribute('connector_type'),
            'purpose' => $connection->getAttribute('purpose'),
            'base_url_hash' => $connection->getAttribute('base_url_hash'),
            'technical_user_id' => $connection->getAttribute('technical_user_id'),
            'credentials_username' => is_array($credentials) ? ($credentials['username'] ?? null) : null,
            'poll_interval_seconds' => $connection->getAttribute('poll_interval_seconds'),
            'rate_limit_rps' => $connection->getAttribute('rate_limit_rps'),
            'status' => $connection->getAttribute('status'),
        ];
    }

    /**
     * Prüfliste aus probe_result mit Symbol je Status (✓ ✗ ⚠ ?).
     *
     * @param  array<string, mixed>  $probeResult
     * @return array<int, array{key: string, label: string, symbol: string, status: string, message: string, latency_ms: int|null}>
     */
    private function probeChecks(array $probeResult): array
    {
        $checks = [];

        foreach ((array) ($probeResult['checks'] ?? []) as $key => $check) {
            $check = (array) $check;
            $status = (string) ($check['status'] ?? 'unknown');

            $checks[] = [
                'key' => (string) $key,
                'label' => ProbeService::label((string) $key),
                'symbol' => match ($status) {
                    'ok' => '✓',
                    'failed' => '✗',
                    'disabled', 'skipped' => '⚠',
                    default => '?',
                },
                'status' => $status,
                'message' => (string) ($check['message'] ?? ''),
                'latency_ms' => isset($check['latency_ms']) ? (int) $check['latency_ms'] : null,
            ];
        }

        return $checks;
    }
}
