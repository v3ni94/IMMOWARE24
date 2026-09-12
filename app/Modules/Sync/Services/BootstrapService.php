<?php

declare(strict_types=1);

namespace App\Modules\Sync\Services;

use App\Core\DTO\SyncRequest;
use App\Core\Enums\SyncMode;
use App\Modules\Connector\Models\ImmowareConnection;
use App\Modules\Sync\Models\SyncRun;
use App\Modules\Sync\Support\ConnectorResolver;
use InvalidArgumentException;
use Throwable;

/**
 * Erstimport in Stufen 1, 10, 100, 1000, alle (07-sync-strategy.md Abschnitt 8). Jede Stufe ist ein
 * eigener sync_run mit trigger_source = manual. Der Aufstieg stoppt, wenn die Fehlerquote die Schwelle überschreitet.
 */
final class BootstrapService
{
    public function __construct(
        private readonly ConnectorResolver $connectors,
        private readonly SyncRunService $runs,
        private readonly SyncStateService $states,
    ) {}

    /**
     * @param  array<int, int|null>|null  $stages  null-Element bedeutet "alle"
     * @return array{completed: bool, aborted_at_stage: int|string|null, stages: array<int, array<string, mixed>>}
     */
    public function run(ImmowareConnection $connection, string $entityType, ?array $stages = null, ?float $maxErrorRate = null, ?int $startedBy = null, ?callable $onStage = null): array
    {
        $stages ??= (array) config('hub.sync.bootstrap.stages', [1, 10, 100, 1000, null]);
        $maxErrorRate ??= (float) config('hub.sync.bootstrap.max_error_rate', 0.05);
        $connectionId = (int) $connection->getKey();
        $connector = $this->connectors->resolve($connection);
        $state = $this->states->forEntity($connectionId, $entityType);
        $report = ['completed' => true, 'aborted_at_stage' => null, 'stages' => []];

        foreach ($stages as $stage) {
            $limit = $stage === null ? (int) config('hub.sync.chunks.limit', 500) : max(1, (int) $stage);
            $run = $this->runs->start($connectionId, $entityType, SyncMode::Full, 'manual', null, $startedBy, SyncRun::TYPE_BOOTSTRAP, $connection->getAttribute('last_health_ok'));
            $cursor = null;
            $processed = 0;
            $failed = 0;
            $error = null;

            try {
                do {
                    $result = $connector->pull(new SyncRequest($connectionId, $entityType, SyncMode::Full, null, $cursor, $limit, (int) $run->getKey()));
                    $this->runs->accumulate($run, $result);
                    $processed += $result->processed;
                    $failed += $result->failed;

                    if ($result->cursor !== null && $result->cursor === $cursor) {
                        // Schutz gegen Endlosschleife bei verletztem Cursor-Vertrag (Änderungsvermerk 12.09.2026).
                        throw new \RuntimeException(sprintf('Adapter lieferte unveränderten Cursor "%s", Stufe abgebrochen.', mb_substr($cursor, 0, 80)));
                    }

                    $cursor = $result->cursor;
                    // Stufen mit Limit verarbeiten genau einen Chunk, "alle" folgt dem Cursor bis zum Ende.
                } while ($stage === null && $cursor !== null);

                $this->runs->finish($run);
            } catch (Throwable $exception) {
                $this->runs->fail($run, $exception);
                $error = $this->runs->describe($exception);
                $failed = max($failed, 1);
                $processed = max($processed, 1);
            }

            $rate = $processed > 0 ? $failed / $processed : 0.0;
            $entry = [
                'stage' => $stage ?? 'alle',
                'limit' => $limit,
                'run_id' => $run->getKey(),
                'processed' => $processed,
                'failed' => $failed,
                'error_rate' => round($rate, 4),
                'error' => $error,
                'passed' => $error === null && $rate <= $maxErrorRate,
            ];
            $report['stages'][] = $entry;

            if ($onStage !== null) {
                $onStage($entry);
            }

            if (! $entry['passed']) {
                $report['completed'] = false;
                $report['aborted_at_stage'] = $stage ?? 'alle';

                return $report;
            }

            if ($stage === null) {
                $this->states->commitSuccess($state, $run, null);
            }
        }

        return $report;
    }

    /**
     * @param  string  $csv  z. B. "1,10,100"
     * @return array<int, int|null>
     */
    public static function parseStages(string $csv): array
    {
        $stages = [];

        foreach (explode(',', $csv) as $part) {
            $part = trim($part);

            if ($part === '') {
                continue;
            }

            if (in_array(strtolower($part), ['alle', 'all', '*'], true)) {
                $stages[] = null;

                continue;
            }

            if (! ctype_digit($part) || (int) $part < 1) {
                throw new InvalidArgumentException(sprintf('Ungültige Stufe "%s".', $part));
            }

            $stages[] = (int) $part;
        }

        return $stages;
    }
}
