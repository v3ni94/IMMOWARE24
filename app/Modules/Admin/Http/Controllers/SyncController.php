<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Core\Enums\SyncMode;
use App\Core\Enums\SyncStatus;
use App\Modules\Admin\Http\Controllers\Concerns\ResolvesOrganizationConnections;
use App\Modules\Admin\Http\Requests\BootstrapRequest;
use App\Modules\Admin\Http\Requests\SyncStartRequest;
use App\Modules\Connector\Models\ImmowareConnection;
use App\Modules\Security\Models\AuditLog;
use App\Modules\Sync\Enums\SyncEntity;
use App\Modules\Sync\Jobs\RunSyncJob;
use App\Modules\Sync\Models\SyncEvent;
use App\Modules\Sync\Models\SyncRun;
use App\Modules\Sync\Services\BootstrapService;
use App\Modules\Sync\Services\DataAgeService;
use App\Modules\Sync\Services\SyncDispatcher;
use App\Modules\Sync\Support\SyncLockManager;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Sync-Monitor: Stand je Connection und Entität, manuelle Läufe (RunSyncJob über SyncDispatcher),
 * Bootstrap-Assistent in Stufen, Historie der sync_runs mit sync_events.
 * Lesen für alle Admin-Rollen, Starten von Läufen erfordert sync.run.
 */
final class SyncController extends AdminController
{
    use ResolvesOrganizationConnections;

    public function __construct(
        private readonly SyncDispatcher $dispatcher,
        private readonly SyncLockManager $locks,
        private readonly DataAgeService $dataAge,
        private readonly BootstrapService $bootstrap,
    ) {}

    public function index(Request $request): View
    {
        $connections = ImmowareConnection::query()
            ->where('purpose', 'read')
            ->orderBy('name')
            ->paginate(10)
            ->withQueryString();

        $rows = [];

        foreach ($connections as $connection) {
            foreach ($this->entitiesFor($connection) as $entity) {
                $rows[] = $this->statusRow($connection, $entity);
            }
        }

        return view('admin::sync.index', [
            'connections' => $connections,
            'rows' => $rows,
            'stages' => BootstrapRequest::STAGES,
            'canRun' => $this->allows('sync.run'),
        ]);
    }

    public function start(SyncStartRequest $request): RedirectResponse
    {
        $this->requirePermission('sync.run');

        $data = $request->validated();
        $connection = $this->findConnection((int) $data['connection_id']);
        $entity = SyncEntity::from((string) $data['entity_type']);
        $mode = $data['mode'] === 'full' ? SyncMode::Full : SyncMode::Incremental;

        if ($mode === SyncMode::Full) {
            $this->requireConfirmation($request);
        }

        if ($this->connectionEntityMismatch($connection, $entity) !== null) {
            return $this->redirectWithWarning('admin.sync.index', 'Die Entität passt nicht zum Adapter der Connection.');
        }

        if (! in_array((string) $connection->getAttribute('status'), RunSyncJob::RUNNABLE_CONNECTION_STATUSES, true)) {
            return $this->redirectWithWarning('admin.sync.index', 'Die Connection ist pausiert. Ein Lauf würde als übersprungen protokolliert.');
        }

        if ($mode === SyncMode::Full && $this->locks->isLocked((int) $connection->getKey(), $entity->value)) {
            return $this->redirectWithWarning('admin.sync.index', 'Für diese Connection und Entität läuft bereits ein Full Sync (Lock gehalten).');
        }

        $user = $this->currentUser($request);
        $this->dispatcher->dispatch((int) $connection->getKey(), $entity, $mode, 'manual', (int) $user->getKey());

        $this->audit('sync.started', $connection, [], [
            'entity_type' => $entity->value,
            'mode' => $mode->value,
        ], (int) $connection->getKey());

        return $this->redirectWithStatus('admin.sync.index', sprintf('%s Sync für %s (%s) eingeplant.', $mode === SyncMode::Full ? 'Full' : 'Incremental', $connection->getAttribute('name'), $entity->label()));
    }

    /**
     * Bootstrap-Assistent: Stufen 1, 10, 100, 1000 laufen synchron (jeweils ein Chunk, ein sync_run),
     * "alle" wird als Full Sync über die Queue eingeplant.
     */
    public function bootstrap(BootstrapRequest $request): RedirectResponse
    {
        $this->requirePermission('sync.run');
        $this->requireConfirmation($request);

        $data = $request->validated();
        $connection = $this->findConnection((int) $data['connection_id']);
        $entity = SyncEntity::from((string) $data['entity_type']);
        $stage = (string) $data['stage'];
        $user = $this->currentUser($request);

        if ($this->connectionEntityMismatch($connection, $entity) !== null) {
            return $this->redirectWithWarning('admin.sync.index', 'Die Entität passt nicht zum Adapter der Connection.');
        }

        if (! in_array((string) $connection->getAttribute('status'), RunSyncJob::RUNNABLE_CONNECTION_STATUSES, true)) {
            return $this->redirectWithWarning('admin.sync.index', 'Die Connection ist pausiert. Bitte zuerst aktivieren.');
        }

        if ($stage === 'alle') {
            if ($this->locks->isLocked((int) $connection->getKey(), $entity->value)) {
                return $this->redirectWithWarning('admin.sync.index', 'Für diese Connection und Entität läuft bereits ein Full Sync (Lock gehalten).');
            }

            $this->dispatcher->dispatch((int) $connection->getKey(), $entity, SyncMode::Full, 'manual', (int) $user->getKey());
            $this->audit('sync.bootstrap_stage', $connection, [], ['entity_type' => $entity->value, 'stage' => 'alle', 'dispatched' => true], (int) $connection->getKey());

            return $this->redirectWithStatus('admin.sync.index', 'Stufe alle als Full Sync eingeplant. Fortschritt in der Historie.');
        }

        try {
            $report = $this->bootstrap->run($connection, $entity->value, [(int) $stage], null, (int) $user->getKey());
        } catch (Throwable $exception) {
            $this->audit('sync.bootstrap_stage', $connection, [], ['entity_type' => $entity->value, 'stage' => $stage, 'error' => $exception::class], (int) $connection->getKey());

            return $this->redirectWithWarning('admin.sync.index', 'Bootstrap-Stufe '.$stage.' konnte nicht ausgeführt werden: '.$exception::class);
        }

        $entry = $report['stages'][0] ?? [];
        $processed = (int) ($entry['processed'] ?? 0);
        $failed = (int) ($entry['failed'] ?? 0);
        // Eine Stufe ohne verarbeiteten Datensatz, aber mit Fehlern, gilt nicht als bestanden (BootstrapService rechnet die Quote nur bei processed > 0).
        $passed = (bool) ($entry['passed'] ?? false) && ! ($processed === 0 && $failed > 0);

        $this->audit('sync.bootstrap_stage', $connection, [], [
            'entity_type' => $entity->value,
            'stage' => $stage,
            'run_id' => $entry['run_id'] ?? null,
            'processed' => $processed,
            'failed' => $failed,
            'passed' => $passed,
        ], (int) $connection->getKey());

        $message = sprintf(
            'Bootstrap-Stufe %s: %s verarbeitet, %s Fehler, Fehlerquote %s %%.',
            $stage,
            number_format((int) ($entry['processed'] ?? 0), 0, ',', '.'),
            number_format((int) ($entry['failed'] ?? 0), 0, ',', '.'),
            number_format(((float) ($entry['error_rate'] ?? 0)) * 100, 2, ',', '.'),
        );

        return $passed
            ? $this->redirectWithStatus('admin.sync.index', $message.' Stufe bestanden, nächste Stufe freigegeben.')
            : $this->redirectWithWarning('admin.sync.index', $message.' Stufe nicht bestanden. Ursache in der Historie prüfen, bevor die nächste Stufe gestartet wird.');
    }

    public function runs(Request $request): View
    {
        $query = SyncRun::query()
            ->whereIn('connection_id', $this->connectionIdsQuery())
            ->orderByDesc('started_at');

        $filters = [
            'connection_id' => (int) $request->query('connection_id', '0'),
            'entity_type' => (string) $request->query('entity_type', ''),
            'status' => (string) $request->query('status', ''),
            'run_type' => (string) $request->query('run_type', ''),
        ];

        if ($filters['connection_id'] > 0) {
            $query->where('connection_id', $filters['connection_id']);
        }

        if (in_array($filters['entity_type'], SyncEntity::values(), true)) {
            $query->where('entity_type', $filters['entity_type']);
        }

        if (SyncStatus::tryFrom($filters['status']) !== null) {
            $query->where('status', $filters['status']);
        }

        if (in_array($filters['run_type'], [SyncRun::TYPE_INCREMENTAL, SyncRun::TYPE_FULL, SyncRun::TYPE_BOOTSTRAP, SyncRun::TYPE_REPLAY], true)) {
            $query->where('run_type', $filters['run_type']);
        }

        $runs = $query->paginate($this->perPage())->withQueryString();

        return view('admin::sync.runs', [
            'runs' => $runs,
            'filters' => $filters,
            'connectionOptions' => $this->connectionOptions(),
            'connectionNames' => $this->namesById(ImmowareConnection::query()->whereKey($runs->getCollection()->pluck('connection_id')->unique()->all())->get()),
            'statuses' => array_map(static fn (SyncStatus $s): string => $s->value, SyncStatus::cases()),
            'runTypes' => [SyncRun::TYPE_INCREMENTAL, SyncRun::TYPE_FULL, SyncRun::TYPE_BOOTSTRAP, SyncRun::TYPE_REPLAY],
        ]);
    }

    public function showRun(Request $request, int $id): View
    {
        /** @var SyncRun $run */
        $run = SyncRun::query()
            ->whereIn('connection_id', $this->connectionIdsQuery())
            ->with(['connection', 'startedBy'])
            ->findOrFail($id);

        $events = SyncEvent::query()
            ->where('sync_run_id', $run->getKey())
            ->orderByDesc('occurred_at')
            ->paginate($this->perPage())
            ->withQueryString();

        $actionCounts = SyncEvent::query()
            ->where('sync_run_id', $run->getKey())
            ->selectRaw('action, count(*) as total')
            ->groupBy('action')
            ->pluck('total', 'action')
            ->all();

        return view('admin::sync.run', [
            'run' => $run,
            'events' => $events,
            'actionCounts' => $actionCounts,
            'counters' => array_merge(['processed' => 0, 'created' => 0, 'updated' => 0, 'deleted' => 0, 'failed' => 0, 'requests' => 0, 'errors' => 0], (array) $run->getAttribute('counters')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function statusRow(ImmowareConnection $connection, SyncEntity $entity): array
    {
        $connectionId = (int) $connection->getKey();

        /** @var SyncRun|null $lastRun */
        $lastRun = SyncRun::query()
            ->where('connection_id', $connectionId)
            ->where('entity_type', $entity->value)
            ->orderByDesc('started_at')
            ->first();

        $running = SyncRun::query()
            ->where('connection_id', $connectionId)
            ->where('entity_type', $entity->value)
            ->where('status', SyncStatus::Running->value)
            ->exists();

        $bootstrapRuns = SyncRun::query()
            ->where('connection_id', $connectionId)
            ->where('entity_type', $entity->value)
            ->where('run_type', SyncRun::TYPE_BOOTSTRAP)
            ->latest('started_at')
            ->lazy(10)
            ->take(10)
            ->collect();

        $age = $this->dataAge->for($connectionId, $entity->value);
        $counters = array_merge(['processed' => 0, 'created' => 0, 'updated' => 0, 'deleted' => 0, 'failed' => 0], (array) ($lastRun?->getAttribute('counters') ?? []));

        return [
            'connection' => $connection,
            'entity' => $entity,
            'last_run' => $lastRun,
            'counters' => $counters,
            'running' => $running,
            'locked' => $this->locks->isLocked($connectionId, $entity->value),
            'runnable' => in_array((string) $connection->getAttribute('status'), RunSyncJob::RUNNABLE_CONNECTION_STATUSES, true),
            'age' => $age,
            'age_label' => $age['age_seconds'] !== null ? $this->humanAge((int) $age['age_seconds']) : 'noch kein erfolgreicher Lauf',
            'last_success_at' => $age['last_success_at'] !== null ? CarbonImmutable::parse((string) $age['last_success_at']) : null,
            'bootstrap' => $this->bootstrapProgress($bootstrapRuns),
        ];
    }

    /**
     * Fortschritt des Bootstrap: Stufe je Lauf aus dem Auditeintrag admin.sync.bootstrap_stage (run_id),
     * Läufe ohne Auditzuordnung (z. B. per hub:sync:bootstrap) erscheinen mit Stufe "unbekannt".
     *
     * @param  Collection<int, SyncRun>  $runs
     * @return array<int, array{stage: string, run: SyncRun, passed: bool}>
     */
    private function bootstrapProgress(Collection $runs): array
    {
        if ($runs->isEmpty()) {
            return [];
        }

        $stageByRun = [];
        $connectionIds = $runs->pluck('connection_id')->unique()->all();
        $audits = AuditLog::query()
            ->where(static fn (Builder $query) => $query->where('action', 'admin.sync.bootstrap_stage')->whereIn('connection_id', $connectionIds))
            ->where('entity_type', class_basename(ImmowareConnection::class))
            ->latest('id')
            ->lazy(200)
            ->take(200);

        foreach ($audits as $audit) {
            $after = (array) $audit->getAttribute('after_json');

            if (isset($after['run_id'], $after['stage'])) {
                $stageByRun[(int) $after['run_id']] ??= (string) $after['stage'];
            }
        }

        $progress = [];

        foreach ($runs as $run) {
            $counters = (array) $run->getAttribute('counters');
            $progress[] = [
                'stage' => $stageByRun[(int) $run->getKey()] ?? 'unbekannt',
                'run' => $run,
                'passed' => $run->status === SyncStatus::Succeeded && (int) ($counters['failed'] ?? 0) === 0,
            ];
        }

        return $progress;
    }

    private function connectionEntityMismatch(ImmowareConnection $connection, SyncEntity $entity): ?string
    {
        foreach ($this->entitiesFor($connection) as $candidate) {
            if ($candidate === $entity) {
                return null;
            }
        }

        return 'mismatch';
    }

    private function humanAge(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds.' Sekunden';
        }

        if ($seconds < 3600) {
            return number_format(intdiv($seconds, 60), 0, ',', '.').' Minuten';
        }

        if ($seconds < 86400) {
            return number_format(intdiv($seconds, 3600), 0, ',', '.').' Stunden';
        }

        return number_format(intdiv($seconds, 86400), 0, ',', '.').' Tage';
    }

    private function allows(string $permission): bool
    {
        /** @var Gate $gate */
        $gate = app(Gate::class);

        return $gate->allows($permission);
    }
}
