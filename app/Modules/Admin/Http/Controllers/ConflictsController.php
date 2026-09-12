<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Admin\Http\Controllers\Concerns\ResolvesOrganizationConnections;
use App\Modules\Admin\Http\Requests\ConflictResolveRequest;
use App\Modules\Connector\Models\ImmowareConnection;
use App\Modules\Sync\Enums\ConflictResolution;
use App\Modules\Sync\Models\Conflict;
use App\Modules\Sync\Services\ConflictService;
use App\Modules\Sync\Services\ExternalPayloadArchiver;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Konfliktqueue: Liste mit Filtern, Detail (lokaler Stand gegen Remote-Nutzlast), Auflösung
 * lokal, remote oder manuell mit Begründung (ConflictService, auditiert). Auflösen erfordert conflicts.resolve.
 */
final class ConflictsController extends AdminController
{
    use ResolvesOrganizationConnections;

    /** @var array<string, string> */
    public const array STATUS_LABELS = [
        'open' => 'offen',
        'in_progress' => 'in Bearbeitung',
        'resolved_keep_local' => 'aufgelöst, lokal behalten',
        'resolved_keep_remote' => 'aufgelöst, Immoware24 übernommen',
        'resolved_manual' => 'aufgelöst, manuell',
        'resolved' => 'aufgelöst',
    ];

    public function __construct(
        private readonly ConflictService $conflicts,
        private readonly ExternalPayloadArchiver $payloads,
    ) {}

    public function index(Request $request): View
    {
        $filters = [
            'status' => (string) $request->query('status', 'open'),
            'entity_type' => (string) $request->query('entity_type', ''),
            'conflict_type' => (string) $request->query('conflict_type', ''),
            'connection_id' => (int) $request->query('connection_id', '0'),
        ];

        $query = Conflict::query()
            ->whereIn('connection_id', $this->connectionIdsQuery())
            ->orderByDesc('created_at');

        if ($filters['status'] === 'open') {
            $query->whereIn('status', Conflict::OPEN_STATUSES);
        } elseif ($filters['status'] === 'resolved') {
            $query->whereNotIn('status', Conflict::OPEN_STATUSES);
        } elseif ($filters['status'] !== '' && $filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        }

        if ($filters['entity_type'] !== '') {
            $query->where('entity_type', mb_substr($filters['entity_type'], 0, 40));
        }

        if ($filters['conflict_type'] !== '') {
            $query->where('conflict_type', mb_substr($filters['conflict_type'], 0, 40));
        }

        if ($filters['connection_id'] > 0) {
            $query->where('connection_id', $filters['connection_id']);
        }

        $conflicts = $query->paginate($this->perPage())->withQueryString();

        return view('admin::conflicts.index', [
            'conflicts' => $conflicts,
            'filters' => $filters,
            'connectionOptions' => $this->connectionOptions(),
            'connectionNames' => $this->namesById(ImmowareConnection::query()->whereKey($conflicts->getCollection()->pluck('connection_id')->unique()->all())->get()),
            'statusLabels' => self::STATUS_LABELS,
            'canResolve' => $this->allows('conflicts.resolve'),
        ]);
    }

    public function show(int $id): View
    {
        /** @var Conflict $conflict */
        $conflict = Conflict::query()
            ->whereIn('connection_id', $this->connectionIdsQuery())
            ->with(['connection', 'syncRun', 'remotePayload', 'assignedTo', 'resolvedBy'])
            ->findOrFail($id);

        $remote = null;
        $remotePayload = $conflict->remotePayload;

        if ($remotePayload !== null) {
            $remote = $this->payloads->contents($remotePayload);
        }

        return view('admin::conflicts.show', [
            'conflict' => $conflict,
            'local' => $conflict->getAttribute('local_snapshot_json'),
            'remote' => $remote,
            'remotePayload' => $remotePayload,
            'proposed' => $conflict->getAttribute('proposed_change_json'),
            'isOpen' => in_array((string) $conflict->getAttribute('status'), Conflict::OPEN_STATUSES, true),
            'statusLabels' => self::STATUS_LABELS,
            'canResolve' => $this->allows('conflicts.resolve'),
        ]);
    }

    public function resolve(ConflictResolveRequest $request, int $id): RedirectResponse
    {
        $this->requirePermission('conflicts.resolve');
        $this->requireConfirmation($request);

        /** @var Conflict $conflict */
        $conflict = Conflict::query()->whereIn('connection_id', $this->connectionIdsQuery())->findOrFail($id);
        $data = $request->validated();
        $user = $this->currentUser($request);
        $before = ['status' => $conflict->getAttribute('status')];

        try {
            $resolved = $this->conflicts->resolve((int) $conflict->getKey(), ConflictResolution::from((string) $data['resolution']), $user, (string) $data['note']);
        } catch (InvalidArgumentException $exception) {
            return $this->redirectWithWarning('admin.conflicts.show', $exception->getMessage(), ['id' => $id]);
        }

        $this->audit('conflicts.resolved', $resolved, $before, [
            'status' => $resolved->getAttribute('status'),
            'resolution' => $data['resolution'],
            'note' => $data['note'],
        ], (int) $resolved->getAttribute('connection_id'));

        $hint = match ((string) $data['resolution']) {
            'remote' => ' Der Stand aus Immoware24 gilt; der Spiegel wird mit dem nächsten Sync angeglichen.',
            'local' => ' Der lokale Stand bleibt. Immoware24 wird nicht verändert; bei Bedarf einen Änderungsvorschlag anlegen.',
            default => ' Manuelle Auflösung protokolliert.',
        };

        return $this->redirectWithStatus('admin.conflicts.show', 'Konflikt aufgelöst.'.$hint, ['id' => $id]);
    }

    private function allows(string $permission): bool
    {
        /** @var Gate $gate */
        $gate = app(Gate::class);

        return $gate->allows($permission);
    }
}
