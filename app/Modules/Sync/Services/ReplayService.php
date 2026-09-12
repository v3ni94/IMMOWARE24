<?php

declare(strict_types=1);

namespace App\Modules\Sync\Services;

use App\Core\Contracts\AuditLoggerInterface;
use App\Core\Enums\AuditSource;
use App\Core\Support\CorrelationId;
use App\Modules\Sync\Models\ExternalPayload;
use App\Modules\Sync\Services\Replay\PayloadReplayerInterface;
use App\Modules\Sync\Services\Replay\ReplayOutcome;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Wiederaufbau von Spiegeldatensätzen aus external_payloads (hub:replay --from=payload).
 * Nutzlasten werden chronologisch (aufsteigende ID) und chunked verarbeitet; jede Nutzlast läuft über den
 * Replayer ihres payload_type und damit über die vorhandenen Mirror-Services. Pseudonymisierte Nutzlasten
 * (pseudonymized_at gesetzt) sind nicht mehr wiederherstellbar und werden gezählt, nicht verarbeitet.
 */
final class ReplayService
{
    /** @var array<string, PayloadReplayerInterface> entity_type => Replayer */
    private array $replayers = [];

    /**
     * @param  iterable<PayloadReplayerInterface>  $replayers
     */
    public function __construct(
        iterable $replayers,
        private readonly ExternalPayloadArchiver $archiver,
        private readonly AuditLoggerInterface $audit,
        private readonly CorrelationId $correlationId,
    ) {
        foreach ($replayers as $replayer) {
            $this->replayers[$replayer->entityType()] = $replayer;
        }
    }

    /**
     * @return array<int, string>
     */
    public function supportedEntities(): array
    {
        return array_keys($this->replayers);
    }

    public function replayerFor(string $entity): PayloadReplayerInterface
    {
        if (! isset($this->replayers[$entity])) {
            throw new InvalidArgumentException(sprintf('Für die Entität "%s" gibt es keinen Replay aus Nutzlasten. Unterstützt: %s.', $entity, implode(', ', $this->supportedEntities())));
        }

        return $this->replayers[$entity];
    }

    /**
     * Anzahl der Nutzlasten, die ein Lauf mit denselben Parametern verarbeiten würde.
     */
    public function count(string $entity, ?string $externalId, ?int $connectionId, bool $latestOnly): int
    {
        return $this->query($this->replayerFor($entity), $externalId, $connectionId, $latestOnly)->count();
    }

    /**
     * @param  callable(ExternalPayload, ?ReplayOutcome, ?Throwable): void|null  $progress  Rückruf je Nutzlast
     * @return array{payloads: int, created: int, updated: int, unchanged: int, failed: int, skipped_pseudonymized: int, skipped_missing: int, dry_run: bool}
     */
    public function replay(string $entity, ?string $externalId = null, ?int $connectionId = null, bool $dryRun = false, bool $latestOnly = false, int $chunkSize = 200, ?callable $progress = null): array
    {
        $replayer = $this->replayerFor($entity);
        $stats = ['payloads' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'failed' => 0, 'skipped_pseudonymized' => 0, 'skipped_missing' => 0, 'dry_run' => $dryRun];

        Log::withContext(['correlation_id' => $this->correlationId->current(), 'replay_entity' => $entity]);

        try {
            $this->query($replayer, $externalId, $connectionId, $latestOnly)
                ->lazyById(max(1, $chunkSize))
                ->each(function (ExternalPayload $payload) use ($replayer, $dryRun, $progress, &$stats): void {
                    $stats['payloads']++;

                    if ($payload->getAttribute('pseudonymized_at') !== null) {
                        $stats['skipped_pseudonymized']++;
                        $this->notify($progress, $payload, null, null);

                        return;
                    }

                    $content = $this->archiver->contents($payload);

                    if ($content === null) {
                        $stats['skipped_missing']++;
                        $this->notify($progress, $payload, null, null);

                        return;
                    }

                    if ($dryRun) {
                        $this->notify($progress, $payload, new ReplayOutcome, null);

                        return;
                    }

                    try {
                        $outcome = DB::transaction(static fn (): ReplayOutcome => $replayer->replay($payload, $content));
                        $stats['created'] += $outcome->created;
                        $stats['updated'] += $outcome->updated;
                        $stats['unchanged'] += $outcome->unchanged;
                        $this->notify($progress, $payload, $outcome, null);
                    } catch (Throwable $e) {
                        $stats['failed']++;
                        Log::warning('Replay: Nutzlast konnte nicht verarbeitet werden.', ['payload_id' => (int) $payload->getKey(), 'error' => $e->getMessage()]);
                        $this->notify($progress, $payload, null, $e);
                    }
                });
        } finally {
            if (! $dryRun) {
                $replayer->finish();
            }
        }

        if (! $dryRun) {
            $this->audit->log('sync.replay', null, [], [
                'entity' => $entity,
                'external_id_given' => $externalId !== null,
                'connection_id' => $connectionId,
                'latest_only' => $latestOnly,
                ...array_diff_key($stats, ['dry_run' => true]),
            ], AuditSource::System->value, $this->correlationId->current());
        }

        return $stats;
    }

    /**
     * @return Builder<ExternalPayload>
     */
    private function query(PayloadReplayerInterface $replayer, ?string $externalId, ?int $connectionId, bool $latestOnly): Builder
    {
        $query = ExternalPayload::query()->where('payload_type', $replayer->payloadType());

        if ($externalId !== null) {
            $query->where('external_id_hash', hash('sha256', $externalId));
        }

        if ($connectionId !== null) {
            $query->where('connection_id', $connectionId);
        }

        if ($latestOnly) {
            $latest = ExternalPayload::query()
                ->selectRaw('MAX(id)')
                ->where('payload_type', $replayer->payloadType())
                ->whereNotNull('external_id_hash')
                ->groupBy('connection_id', 'external_id_hash');

            if ($connectionId !== null) {
                $latest->where('connection_id', $connectionId);
            }

            $query->whereIn('id', $latest->toBase());
        }

        return $query;
    }

    /**
     * @param  callable(ExternalPayload, ?ReplayOutcome, ?Throwable): void|null  $progress
     */
    private function notify(?callable $progress, ExternalPayload $payload, ?ReplayOutcome $outcome, ?Throwable $error): void
    {
        if ($progress !== null) {
            $progress($payload, $outcome, $error);
        }
    }
}
