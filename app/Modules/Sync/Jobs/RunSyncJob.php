<?php

declare(strict_types=1);

namespace App\Modules\Sync\Jobs;

use App\Core\Contracts\WebhookDispatcherInterface;
use App\Core\DTO\SyncRequest;
use App\Core\DTO\SyncResult;
use App\Core\Enums\SyncMode;
use App\Core\Enums\SyncStatus;
use App\Core\Exceptions\RateLimitedException;
use App\Core\Support\CorrelationId;
use App\Modules\Connector\Models\ImmowareConnection;
use App\Modules\Sync\Jobs\Concerns\SyncJobRetries;
use App\Modules\Sync\Models\SyncRun;
use App\Modules\Sync\Services\DlqService;
use App\Modules\Sync\Services\SyncMetrics;
use App\Modules\Sync\Services\SyncRunService;
use App\Modules\Sync\Services\SyncStateService;
use App\Modules\Sync\Support\ConnectorResolver;
use App\Modules\Sync\Support\SyncLockManager;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Orchestrator: führt pull() des Adapters in Chunks mit Cursor aus. Nach max_per_run Chunks plant er sich
 * mit dem nächsten Cursor selbst erneut ein (kein Endloslauf). Jeder Lauf (Full wie Incremental) hält den
 * SyncLockManager-Lock je Connection und Entität über alle Chunks; ein zweiter Lauf derselben Quelle wird als
 * skipped protokolliert. Der Lock wird je Chunk verlängert; Lock-Verlust beendet den Lauf als aborted ohne
 * Cursor-Commit (07-sync-strategy.md Abschnitt 5). Bei einer transienten Exception bleibt der Lock für den Retry
 * bestehen (Owner-Token aus der Job-UUID); erst failed() gibt ihn frei. Änderungsvermerk 12.09.2026.
 */
class RunSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SyncJobRetries;

    /** @var array<int, string> */
    public const array RUNNABLE_CONNECTION_STATUSES = ['active', 'degraded'];

    /** Owner-Token des in diesem Versuch gehaltenen Locks (nicht serialisiert). */
    private ?string $activeLockOwner = null;

    public function __construct(
        public readonly int $connectionId,
        public readonly string $entityType,
        public readonly SyncMode $mode = SyncMode::Incremental,
        public readonly ?string $cursor = null,
        public readonly ?int $runId = null,
        public readonly ?string $lockOwner = null,
        public readonly ?string $correlationId = null,
        public readonly string $triggerSource = 'schedule',
        public readonly ?int $limit = null,
        public readonly bool $singleRecord = false,
        public readonly ?int $startedBy = null,
    ) {
        $this->applyRetryConfig();
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public static function fromDlqArguments(array $arguments): self
    {
        $mode = $arguments['mode'] ?? SyncMode::Incremental->value;

        return new self(
            connectionId: (int) ($arguments['connectionId'] ?? 0),
            entityType: (string) ($arguments['entityType'] ?? ''),
            mode: $mode instanceof SyncMode ? $mode : (SyncMode::tryFrom((string) $mode) ?? SyncMode::Incremental),
            cursor: isset($arguments['cursor']) ? (string) $arguments['cursor'] : null,
            correlationId: isset($arguments['correlationId']) ? (string) $arguments['correlationId'] : null,
            triggerSource: 'recovery',
            limit: isset($arguments['limit']) ? (int) $arguments['limit'] : null,
            singleRecord: (bool) ($arguments['singleRecord'] ?? false),
        );
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping(SyncLockManager::key($this->connectionId, $this->entityType)))
                ->releaseAfter(60)
                ->expireAfter((int) config('hub.sync.locks.overlap_ttl_seconds', 3600)),
        ];
    }

    public function handle(
        ConnectorResolver $connectors,
        SyncRunService $runs,
        SyncStateService $states,
        SyncLockManager $locks,
        SyncMetrics $metrics,
        DlqService $dlq,
        CorrelationId $correlation,
    ): void {
        if ($this->correlationId !== null) {
            $correlation->set($this->correlationId);
        }

        /** @var ImmowareConnection|null $connection */
        $connection = ImmowareConnection::query()->withoutGlobalScopes()->find($this->connectionId);

        if ($connection === null) {
            Log::warning('RunSyncJob: Connection nicht gefunden.', ['connection_id' => $this->connectionId]);

            return;
        }

        if (! in_array((string) $connection->getAttribute('status'), self::RUNNABLE_CONNECTION_STATUSES, true)) {
            $runs->skipped($this->connectionId, $this->entityType, $this->mode, sprintf('Connection im Status %s, kein Lauf.', (string) $connection->getAttribute('status')), $this->triggerSource);

            return;
        }

        // Lock je Connection und Adapter für jeden Modus. Der Owner ist je Queue-Job deterministisch, damit ein Retry
        // seinen eigenen Lock wieder aufnimmt; Fortsetzungsjobs tragen den Owner im Payload.
        $lockOwner = $this->lockOwner ?? SyncLockManager::ownerForJob($this->job?->uuid());

        if (! $locks->acquireOrResume($this->connectionId, $this->entityType, $lockOwner)) {
            $reason = $this->lockOwner !== null
                ? 'Fortsetzung übersprungen: Sync-Lock inzwischen von einem anderen Lauf gehalten.'
                : sprintf('%s übersprungen: Sync-Lock der Connection und Entität wird von einem anderen Lauf gehalten.', $this->mode === SyncMode::Full ? 'Full Sync' : 'Lauf');
            $runs->skipped($this->connectionId, $this->entityType, $this->mode, $reason, $this->triggerSource);
            $metrics->increment(SyncMetrics::SKIPPED_LOCKED, 1, $this->labels());
            Log::info('RunSyncJob: Lauf übersprungen, Lock gehalten.', [...$this->labels(), 'run_id' => $this->runId]);

            return;
        }

        $this->activeLockOwner = $lockOwner;

        $state = $states->forEntity($this->connectionId, $this->entityType);
        $run = $this->runId !== null ? SyncRun::query()->find($this->runId) : null;

        if ($run instanceof SyncRun) {
            $runs->resume($run);
        } else {
            $cursorBefore = $this->cursor ?? ($this->mode === SyncMode::Full ? null : $states->cursor($state));
            $run = $runs->start(
                $this->connectionId,
                $this->entityType,
                $this->mode,
                $this->triggerSource,
                $cursorBefore !== null ? ['cursor' => $cursorBefore] : null,
                $this->startedBy,
                $this->singleRecord ? SyncRun::TYPE_REPLAY : null,
                $connection->getAttribute('last_health_ok'),
            );
        }

        $cursor = $this->cursor ?? ($this->mode === SyncMode::Full && $this->runId === null ? null : $states->cursor($state));
        $since = $state->getAttribute('last_success_at');
        $since = $since instanceof CarbonImmutable ? $since : null;
        $limit = $this->limit ?? (int) config('hub.sync.chunks.limit', 500);
        $maxChunks = $this->singleRecord ? 1 : max(1, (int) config('hub.sync.chunks.max_per_run', 20));
        $started = hrtime(true);

        try {
            $connector = $connectors->resolve($connection);
            $chunks = 0;

            while ($chunks < $maxChunks) {
                // Heartbeat: Lock je Chunk verlängern. Verlust bedeutet phase aborted, kein Cursor-Commit (07 Abschnitt 5).
                if (! $locks->renew($this->connectionId, $this->entityType, $lockOwner)) {
                    $runs->abort($run, 'Sync-Lock während des Laufs verloren (TTL abgelaufen oder Cache nicht erreichbar). Kein Cursor-Commit.');
                    $states->recordFailure($state, $run, 'Lock verloren, Lauf abgebrochen.');
                    $metrics->increment(SyncMetrics::SYNC_ERRORS, 1, $this->labels());
                    Log::error('RunSyncJob: Lock verloren, Lauf abgebrochen.', [...$this->labels(), 'run_id' => $run->getKey()]);

                    return;
                }

                $request = new SyncRequest($this->connectionId, $this->entityType, $this->mode, $since, $cursor, $limit, (int) $run->getKey());
                $result = $connector->pull($request);
                $chunks++;

                $runs->accumulate($run, $result);
                $this->recordMetrics($metrics, $result);
                $this->recordRecordFailures($dlq, $result);

                if ($result->cursor !== null && $result->cursor === $cursor) {
                    // Cursor-Vertrag verletzt: Ein Adapter, der denselben Cursor zurückgibt, würde die Collection
                    // endlos neu enumerieren (Änderungsvermerk 12.09.2026). Lauf abbrechen, Eintrag in die DLQ.
                    $exception = new \RuntimeException(sprintf('Adapter lieferte unveränderten Cursor "%s", Chunk-Schleife abgebrochen.', mb_substr($cursor, 0, 80)));
                    $runs->fail($run, $exception);
                    $states->recordFailure($state, $run, $runs->describe($exception));
                    $dlq->store(static::class, $this->dlqArguments(), $exception, $this->connectionId, $this->entityType, (string) $this->queue);
                    $metrics->increment(SyncMetrics::SYNC_ERRORS, 1, $this->labels());
                    $locks->release($this->connectionId, $this->entityType, $lockOwner);
                    Log::error('RunSyncJob: unveränderter Cursor, Lauf abgebrochen.', [...$this->labels(), 'run_id' => $run->getKey()]);

                    return;
                }

                $cursor = $result->cursor;

                if ($cursor === null || $this->singleRecord) {
                    break;
                }
            }

            if ($cursor !== null && ! $this->singleRecord) {
                $this->dispatchNext($cursor, (int) $run->getKey(), $lockOwner, $correlation->current());
                Log::info('RunSyncJob: Chunk-Grenze erreicht, Fortsetzung eingeplant.', [...$this->labels(), 'run_id' => $run->getKey(), 'chunks' => $chunks]);

                return;
            }

            $run = $runs->finish($run, null);

            if ($run->getAttribute('status') === SyncStatus::Succeeded) {
                $states->commitSuccess($state, $run, null);
            } else {
                // Alle Datensätze fehlgeschlagen: kein frischer Datenstand, stale_since bleibt bestehen (04-runbook.md).
                $states->recordFailure($state, $run, (string) ($run->getAttribute('error_summary') ?? 'Lauf ohne erfolgreich verarbeitete Datensätze.'));
            }

            $metrics->observeDuration((int) ((hrtime(true) - $started) / 1_000_000), $this->labels());
            $locks->release($this->connectionId, $this->entityType, $lockOwner);
        } catch (Throwable $exception) {
            if ($exception instanceof RateLimitedException) {
                $metrics->increment(SyncMetrics::RATE_LIMITED, 1, $this->labels());
            }

            $metrics->increment(SyncMetrics::SYNC_ERRORS, 1, $this->labels());
            $states->recordFailure($state, $run, $runs->describe($exception));
            $run->forceFill(['error_summary' => $runs->describe($exception)]);
            $run->save();

            if ($this->job === null) {
                // Synchrone Ausführung (DLQ-Replay über dispatchNow, --sync): kein Worker ruft failed() auf. Der Lauf wird
                // hier geschlossen (sync.failed einmalig) und der Lock freigegeben, sonst bliebe der Lauf running und der
                // Lock bis zur TTL belegt. Den DLQ-Eintrag verantwortet der Aufrufer (DlqService::replay bzw. failed()).
                $runs->fail($run, $exception);
                $this->notifyFailure($run, (int) $connection->getAttribute('organization_id'), $runs->describe($exception));
                $locks->release($this->connectionId, $this->entityType, $lockOwner);
                $this->activeLockOwner = null;

                throw $exception;
            }

            // Endgültiges Scheitern (fail(), sync.failed, DLQ) behandelt ausschließlich failed(), damit je Lauf genau
            // ein Ereignis entsteht. Der Lock bleibt für den Retry bestehen: derselbe Job nimmt ihn über seinen
            // deterministischen Owner-Token wieder auf; ein fremder Lauf wird bis dahin als skipped protokolliert.
            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $exception ??= new \RuntimeException('Job ohne Exception fehlgeschlagen.');

        /** @var DlqService $dlq */
        $dlq = app(DlqService::class);
        $dlq->store(static::class, $this->dlqArguments(), $exception, $this->connectionId, $this->entityType, (string) $this->queue);

        $owner = $this->activeLockOwner ?? $this->lockOwner ?? ($this->job !== null ? SyncLockManager::ownerForJob($this->job->uuid()) : null);
        app(SyncLockManager::class)->release($this->connectionId, $this->entityType, $owner);

        // Nur der eigene Lauf wird geschlossen: über runId (Fortsetzungsjobs) oder, beim Erstjob ohne runId,
        // der jüngste noch laufende Lauf dieser Connection, Entität, Modus und Auslösequelle. Fremde Läufe
        // (anderer Modus oder Auslöser) bleiben unberührt.
        $run = $this->runId !== null
            ? SyncRun::query()->find($this->runId)
            : ($this->cursor === null
                ? SyncRun::query()
                    ->where('connection_id', $this->connectionId)
                    ->where('entity_type', $this->entityType)
                    ->where('mode', $this->mode->value)
                    ->where('trigger_source', $this->triggerSource)
                    ->where('status', SyncStatus::Running->value)
                    ->orderByDesc('id')
                    ->first()
                : null);

        if ($run instanceof SyncRun) {
            app(SyncRunService::class)->fail($run, $exception);

            $connection = ImmowareConnection::query()->withoutGlobalScopes()->find($this->connectionId);

            if ($connection !== null) {
                $this->notifyFailure($run, (int) $connection->getAttribute('organization_id'), app(SyncRunService::class)->describe($exception));
            }
        }
    }

    /**
     * Webhook-Ereignis sync.failed (Outbox). Fehler beim Ablegen dürfen die Fehlerbehandlung nicht überdecken.
     */
    private function notifyFailure(SyncRun $run, int $organizationId, string $summary): void
    {
        try {
            app(WebhookDispatcherInterface::class)->dispatch('sync.failed', [
                'id' => (int) $run->getKey(),
                'type' => 'sync_run',
                'href' => '/api/v1/sync/status',
                'connection_id' => $this->connectionId,
                'entity_type' => $this->entityType,
                'mode' => $this->mode->value,
                'error_summary' => $summary,
            ], $organizationId);
        } catch (Throwable $e) {
            Log::warning('sync.failed konnte nicht in die Webhook-Outbox gelegt werden.', ['error' => $e->getMessage()]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function dlqArguments(): array
    {
        return [
            'connectionId' => $this->connectionId,
            'entityType' => $this->entityType,
            'mode' => $this->mode->value,
            'cursor' => $this->cursor,
            'correlationId' => $this->correlationId,
            'limit' => $this->limit,
            'singleRecord' => $this->singleRecord,
        ];
    }

    /**
     * Folgejob mit nächstem Cursor. Wrapper-Klassen überschreiben diese Methode.
     */
    protected function next(?string $cursor, int $runId, ?string $lockOwner, string $correlationId): self
    {
        return new self($this->connectionId, $this->entityType, $this->mode, $cursor, $runId, $lockOwner, $correlationId, $this->triggerSource, $this->limit, false, $this->startedBy);
    }

    private function dispatchNext(?string $cursor, int $runId, ?string $lockOwner, string $correlationId): void
    {
        dispatch($this->next($cursor, $runId, $lockOwner, $correlationId));
    }

    private function recordMetrics(SyncMetrics $metrics, SyncResult $result): void
    {
        $labels = $this->labels();
        $metrics->increment(SyncMetrics::REMOTE_REQUESTS, 1, $labels);
        $metrics->increment(SyncMetrics::SYNC_RECORDS, $result->processed, $labels);

        if ($result->failed > 0) {
            $metrics->increment(SyncMetrics::SYNC_ERRORS, $result->failed, $labels);
        }
    }

    private function recordRecordFailures(DlqService $dlq, SyncResult $result): void
    {
        foreach ($result->errors as $error) {
            if (! is_array($error) || ! isset($error['external_id'])) {
                continue;
            }

            $dlq->storeRecordFailure(static::class, [
                'connectionId' => $this->connectionId,
                'entityType' => $this->entityType,
                'mode' => $this->mode->value,
                'cursor' => isset($error['cursor']) ? (string) $error['cursor'] : null,
                'correlationId' => $this->correlationId,
                'limit' => 1,
                'singleRecord' => true,
            ], $error, $this->connectionId, $this->entityType);
        }
    }

    /**
     * @return array<string, scalar>
     */
    private function labels(): array
    {
        return ['connection' => $this->connectionId, 'entity' => $this->entityType, 'mode' => $this->mode->value];
    }
}
