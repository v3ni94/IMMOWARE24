<?php

declare(strict_types=1);

namespace App\Modules\Sync\Services;

use App\Core\DTO\SyncResult;
use App\Core\Enums\SyncMode;
use App\Core\Enums\SyncStatus;
use App\Core\Support\CorrelationId;
use App\Core\Support\SecretMasker;
use App\Modules\Sync\Models\SyncEvent;
use App\Modules\Sync\Models\SyncRun;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Protokoll je Lauf: Start, Ende, Dauer, processed/created/updated/deleted/failed, Fehler.
 */
final class SyncRunService
{
    /** @var array<string, int> */
    private const array EMPTY_COUNTERS = [
        'processed' => 0, 'created' => 0, 'updated' => 0, 'deleted' => 0, 'failed' => 0, 'requests' => 0, 'errors' => 0,
    ];

    public function __construct(
        private readonly CorrelationId $correlationId,
        private readonly SecretMasker $masker,
    ) {}

    /**
     * @param  array<string, mixed>|null  $cursorBefore
     */
    public function start(
        int $connectionId,
        string $entityType,
        SyncMode $mode,
        string $triggerSource = 'schedule',
        ?array $cursorBefore = null,
        ?int $startedBy = null,
        ?string $runType = null,
        ?bool $healthOkBefore = null,
    ): SyncRun {
        $run = new SyncRun;
        $run->forceFill([
            'connection_id' => $connectionId,
            'entity_type' => $entityType,
            'correlation_id' => $this->correlationId->current(),
            'run_type' => $runType ?? ($mode === SyncMode::Full ? SyncRun::TYPE_FULL : SyncRun::TYPE_INCREMENTAL),
            'mode' => $mode,
            'trigger_source' => $triggerSource,
            'phase' => SyncRun::PHASE_FETCH,
            'status' => SyncStatus::Running,
            'started_at' => CarbonImmutable::now(),
            'health_ok_before' => $healthOkBefore,
            'counters' => self::EMPTY_COUNTERS,
            'cursor_before' => $cursorBefore,
            'started_by' => $startedBy,
            'chunks' => 0,
        ]);
        $run->save();

        return $run;
    }

    public function skipped(int $connectionId, string $entityType, SyncMode $mode, string $reason, string $triggerSource = 'schedule'): SyncRun
    {
        $now = CarbonImmutable::now();
        $run = new SyncRun;
        $run->forceFill([
            'connection_id' => $connectionId,
            'entity_type' => $entityType,
            'correlation_id' => $this->correlationId->current(),
            'run_type' => $mode === SyncMode::Full ? SyncRun::TYPE_FULL : SyncRun::TYPE_INCREMENTAL,
            'mode' => $mode,
            'trigger_source' => $triggerSource,
            'phase' => SyncRun::PHASE_DONE,
            'status' => SyncStatus::Skipped,
            'started_at' => $now,
            'finished_at' => $now,
            'duration_ms' => 0,
            'counters' => self::EMPTY_COUNTERS,
            'error_summary' => $reason,
        ]);
        $run->save();

        return $run;
    }

    /**
     * Zählt das Ergebnis eines Chunks zum Lauf hinzu.
     */
    public function accumulate(SyncRun $run, SyncResult $result): SyncRun
    {
        $counters = array_merge(self::EMPTY_COUNTERS, (array) $run->getAttribute('counters'));
        $counters['processed'] += $result->processed;
        $counters['created'] += $result->created;
        $counters['updated'] += $result->updated;
        $counters['deleted'] += $result->deleted;
        $counters['failed'] += $result->failed;
        $counters['requests'] += 1;
        $counters['errors'] += count($result->errors);

        $run->forceFill([
            'counters' => $counters,
            'chunks' => ((int) $run->getAttribute('chunks')) + 1,
            'cursor_after' => $result->cursor !== null ? ['cursor' => $result->cursor] : null,
        ]);
        $run->save();

        return $run;
    }

    public function finish(SyncRun $run, ?string $cursorAfter = null): SyncRun
    {
        $counters = (array) $run->getAttribute('counters');
        $processed = (int) ($counters['processed'] ?? 0);
        $failed = (int) ($counters['failed'] ?? 0);

        $status = SyncStatus::Succeeded;

        if ($failed > 0 && $failed >= $processed && $processed > 0) {
            $status = SyncStatus::Failed;
        }

        return $this->close($run, $status, SyncRun::PHASE_DONE, null, $cursorAfter);
    }

    public function fail(SyncRun $run, Throwable $exception): SyncRun
    {
        return $this->close($run, SyncStatus::Failed, SyncRun::PHASE_FAILED, $this->describe($exception));
    }

    public function abort(SyncRun $run, string $reason): SyncRun
    {
        return $this->close($run, SyncStatus::Aborted, SyncRun::PHASE_ABORTED, $reason);
    }

    /**
     * Setzt den Lauf nach einem Fehler in einem Retry fort.
     */
    public function resume(SyncRun $run): SyncRun
    {
        $run->forceFill(['status' => SyncStatus::Running, 'phase' => SyncRun::PHASE_FETCH, 'finished_at' => null, 'duration_ms' => null]);
        $run->save();

        return $run;
    }

    public function event(SyncRun $run, string $entityType, int $entityId, string $action, string $detectedBy, ?string $oldChecksum = null, ?string $newChecksum = null, ?int $payloadId = null): SyncEvent
    {
        $event = new SyncEvent;
        $event->forceFill([
            'sync_run_id' => $run->getKey(),
            'connection_id' => $run->getAttribute('connection_id'),
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => $action,
            'detected_by' => $detectedBy,
            'old_checksum' => $oldChecksum,
            'new_checksum' => $newChecksum,
            'payload_id' => $payloadId,
            'occurred_at' => CarbonImmutable::now(),
        ]);
        $event->save();

        return $event;
    }

    public function describe(Throwable $exception): string
    {
        return $this->masker->maskString(sprintf('%s: %s', $exception::class, $exception->getMessage()));
    }

    private function close(SyncRun $run, SyncStatus $status, string $phase, ?string $error, ?string $cursorAfter = null): SyncRun
    {
        $now = CarbonImmutable::now();
        $startedAt = $run->getAttribute('started_at');
        $duration = $startedAt instanceof CarbonImmutable ? max(0, $startedAt->diffInMilliseconds($now, false)) : 0;

        $run->forceFill([
            'status' => $status,
            'phase' => $phase,
            'finished_at' => $now,
            'duration_ms' => (int) $duration,
            'error_summary' => $error !== null ? mb_substr($error, 0, 4000) : $run->getAttribute('error_summary'),
            'cursor_after' => $cursorAfter !== null ? ['cursor' => $cursorAfter] : ($status === SyncStatus::Succeeded ? null : $run->getAttribute('cursor_after')),
        ]);
        $run->save();

        return $run;
    }
}
