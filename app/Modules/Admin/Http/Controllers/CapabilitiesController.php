<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Admin\Http\Controllers\Concerns\ResolvesOrganizationConnections;
use App\Modules\Connector\Models\Capability;
use App\Modules\Connector\Models\ImmowareConnection;
use App\Modules\Connector\Services\CapabilityRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Capabilities je Connection, ausschließlich lesend. Aktivierbarkeit ergibt sich aus Belegstatus
 * (verified oder tested), Config-Flag und hard_locked; hard_locked Sperren sind nie aufhebbar.
 */
final class CapabilitiesController extends AdminController
{
    use ResolvesOrganizationConnections;

    /** @var array<string, string> */
    public const array HARD_LOCK_REASONS = [
        'documents.delete' => 'DELETE per WebDAV ist hart gesperrt. Immoware24 ist Master, der Hub löscht nie remote (CLAUDE.md Regel 2, Flag IMMOWARE_WRITE_WEBDAV_DELETE_ENABLED lässt die Anwendung bei true abbrechen).',
        'documents.move' => 'MOVE per WebDAV ist hart gesperrt. Ein Verschieben im DMS erfolgt nur manuell in Immoware24 (Flag IMMOWARE_WRITE_WEBDAV_MOVE_ENABLED).',
        'contacts.write' => 'Schreiben per CardDAV ist hart gesperrt. Kontaktänderungen laufen über Änderungsvorschläge (proposed_change) und manuelle Umsetzung in Immoware24 (Flag IMMOWARE_WRITE_CARDDAV_ENABLED).',
        'calendar.write' => 'Schreiben per CalDAV ist hart gesperrt. Termine werden nur gelesen (Flag IMMOWARE_WRITE_CALDAV_ENABLED).',
    ];

    /** @var array<string, string> */
    public const array STATUS_LABELS = [
        'verified' => 'verifiziert',
        'documented' => 'dokumentiert',
        'tested' => 'getestet (Probe)',
        'assumed' => 'vermutet',
        'unavailable' => 'nicht verfügbar',
        'waiting_for_vendor_access' => 'WAITING_FOR_VENDOR_ACCESS',
    ];

    public function __construct(private readonly CapabilityRegistry $registry) {}

    public function index(Request $request): View
    {
        $query = ImmowareConnection::query()->orderBy('name');
        $connectionId = (int) $request->query('connection_id', '0');

        if ($connectionId > 0) {
            $query->whereKey($connectionId);
        }

        $connections = $query->paginate(10)->withQueryString();
        $tables = [];

        foreach ($connections as $connection) {
            $tables[(int) $connection->getKey()] = $this->tableFor($connection);
        }

        return view('admin::capabilities.index', [
            'connections' => $connections,
            'tables' => $tables,
            'connectionOptions' => $this->connectionOptions(),
            'selectedConnection' => $connectionId,
            'hardLocks' => $this->hardLocks(),
            'statusLabels' => self::STATUS_LABELS,
        ]);
    }

    /**
     * @return array<int, array{key: string, status: string|null, status_label: string, source: string, tested_at: \DateTimeInterface|null, tested_by: string|null, activatable: bool, available: bool, enabled: bool, hard_locked: bool, config_allowed: bool, connector: string|null, reason: string|null}>
     */
    private function tableFor(ImmowareConnection $connection): array
    {
        $connectionId = (int) $connection->getKey();
        $this->registry->refresh($connectionId);
        $all = $this->registry->all();

        $rows = Capability::query()
            ->with('testedBy')
            ->where('connection_id', $connectionId)
            ->get()
            ->keyBy('capability_key');

        $result = [];

        foreach ($all as $key => $entry) {
            /** @var Capability|null $row */
            $row = $rows->get($key);
            $testedAt = $row?->getAttribute('tested_at');
            $hardLocked = (bool) $entry['hard_locked'];

            $source = match (true) {
                $hardLocked => 'hard_locked',
                $row !== null && $testedAt !== null => 'probe',
                default => 'config',
            };

            $status = $entry['status'];

            $result[] = [
                'key' => (string) $key,
                'status' => $status,
                'status_label' => $status !== null ? (self::STATUS_LABELS[$status] ?? $status) : 'kein Eintrag (Config-Standard: vermutet)',
                'source' => $source,
                'tested_at' => $testedAt instanceof \DateTimeInterface ? $testedAt : null,
                'tested_by' => $row?->testedBy?->getAttribute('name'),
                'activatable' => ! $hardLocked && $status !== null && in_array($status, CapabilityRegistry::ACTIVATABLE_STATUSES, true) && (bool) $entry['config_allowed'],
                'available' => (bool) $entry['available'],
                'enabled' => (bool) $entry['enabled'],
                'hard_locked' => $hardLocked,
                'config_allowed' => (bool) $entry['config_allowed'],
                'connector' => $this->registry->connectorFor((string) $key),
                'reason' => $hardLocked ? (self::HARD_LOCK_REASONS[$key] ?? 'Hart gesperrt durch Konfiguration.') : null,
            ];
        }

        return $result;
    }

    /**
     * @return array<int, array{key: string, reason: string, flags: array<int, string>}>
     */
    private function hardLocks(): array
    {
        $locks = [];
        $definitions = (array) config('hub.connector.capabilities', []);

        foreach ($this->registry->keys() as $key) {
            if (! $this->registry->isHardLocked($key)) {
                continue;
            }

            $definition = is_array($definitions[$key] ?? null) ? $definitions[$key] : [];
            $flags = $definition['config_flag'] ?? [];

            $locks[] = [
                'key' => $key,
                'reason' => self::HARD_LOCK_REASONS[$key] ?? 'Hart gesperrt durch Konfiguration.',
                'flags' => array_map('strval', (array) $flags),
            ];
        }

        return $locks;
    }
}
