<?php

declare(strict_types=1);

namespace App\Modules\Api\Health;

use App\Modules\Api\Support\Provenance;
use App\Modules\Connector\Models\ImmowareConnection;
use App\Modules\Sync\Models\SyncState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Aggregierte Health-Checks: database, queue, immoware. Jeder Check liefert status ok, degraded oder down
 * plus Details. Details werden nur an Aufrufer mit Scope admin ausgegeben.
 */
final class HealthService
{
    public const string OK = 'ok';

    public const string DEGRADED = 'degraded';

    public const string DOWN = 'down';

    public function __construct(private readonly Provenance $provenance) {}

    /**
     * @return array{status: string, checks: array<string, array{status: string, details: array<string, mixed>}>}
     */
    public function aggregate(): array
    {
        $checks = [
            'database' => $this->database(),
            'queue' => $this->queue(),
            'immoware' => $this->immoware(),
        ];

        $status = self::OK;

        foreach ($checks as $check) {
            if ($check['status'] === self::DOWN) {
                $status = self::DOWN;
                break;
            }

            if ($check['status'] === self::DEGRADED) {
                $status = self::DEGRADED;
            }
        }

        return ['status' => $status, 'checks' => $checks];
    }

    /**
     * @return array{status: string, details: array<string, mixed>}
     */
    public function database(): array
    {
        $started = microtime(true);

        try {
            DB::connection()->getPdo();
            DB::select('select 1');
            $migrated = Schema::hasTable('migrations');

            return [
                'status' => $migrated ? self::OK : self::DEGRADED,
                'details' => [
                    'driver' => DB::connection()->getDriverName(),
                    'latency_ms' => (int) round((microtime(true) - $started) * 1000),
                    'migrations_table' => $migrated,
                ],
            ];
        } catch (Throwable $e) {
            return ['status' => self::DOWN, 'details' => ['error_class' => $e::class]];
        }
    }

    /**
     * @return array{status: string, details: array<string, mixed>}
     */
    public function queue(): array
    {
        try {
            $depth = Schema::hasTable('jobs') ? (int) DB::table('jobs')->count() : 0;
            $oldest = Schema::hasTable('jobs') ? DB::table('jobs')->min('created_at') : null;
            $failed = Schema::hasTable('failed_jobs') ? (int) DB::table('failed_jobs')->count() : 0;
            $lastFailed = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->max('failed_at') : null;
            $dlqOpen = Schema::hasTable('dlq_items') ? (int) DB::table('dlq_items')->whereNull('replayed_at')->count() : 0;

            $oldestAge = null;

            if ($oldest !== null) {
                $oldestAge = is_numeric($oldest)
                    ? max(0, time() - (int) $oldest)
                    : (int) CarbonImmutable::parse((string) $oldest)->diffInSeconds(CarbonImmutable::now(), true);
            }

            $warning = (int) config('hub.api.health.queue_depth_warning', 1000);
            $status = $depth > $warning || $dlqOpen > 0 ? self::DEGRADED : self::OK;

            return [
                'status' => $status,
                'details' => [
                    'connection' => (string) config('queue.default'),
                    'depth' => $depth,
                    'oldest_job_age_seconds' => $oldestAge,
                    'failed_jobs' => $failed,
                    'last_failed_at' => is_string($lastFailed) ? $lastFailed : null,
                    'dlq_open' => $dlqOpen,
                ],
            ];
        } catch (Throwable $e) {
            return ['status' => self::DOWN, 'details' => ['error_class' => $e::class]];
        }
    }

    /**
     * Letzter erfolgreicher Sync je Entität auf aktiven Connections. Stale bedeutet down (503).
     *
     * @return array{status: string, details: array<string, mixed>}
     */
    public function immoware(): array
    {
        try {
            $connections = ImmowareConnection::query()->withoutGlobalScopes()->oldest('id')
                ->get(['id', 'name', 'connector_type', 'status', 'degraded_reason', 'last_health_ok', 'last_health_at']);

            $now = CarbonImmutable::now();
            $status = self::OK;
            $list = [];
            $active = 0;

            foreach ($connections as $connection) {
                $connStatus = (string) $connection->getAttribute('status');
                $entities = [];

                if ($connStatus === 'active') {
                    $active++;
                    $states = SyncState::query()
                        ->where('connection_id', $connection->getKey())
                        ->where('scope', 'collection')
                        ->get()
                        ->filter(static fn (SyncState $state): bool => $state->getAttribute('entity_type') !== null);

                    if ($states->isEmpty()) {
                        $status = self::DOWN;
                    }

                    foreach ($states as $state) {
                        $entityType = (string) $state->getAttribute('entity_type');
                        $last = $state->getAttribute('last_success_at');
                        $last = $last instanceof \DateTimeInterface ? CarbonImmutable::instance($last) : null;
                        $age = $last !== null ? (int) $last->diffInSeconds($now, true) : null;
                        $threshold = $this->provenance->thresholdSeconds($entityType);
                        $stale = $age === null || $age > $threshold;

                        if ($stale) {
                            $status = self::DOWN;
                        }

                        $entities[] = [
                            'entity_type' => $entityType,
                            'last_success_at' => $last?->utc()->toIso8601ZuluString('millisecond'),
                            'data_age_seconds' => $age,
                            'threshold_seconds' => $threshold,
                            'stale' => $stale,
                        ];
                    }

                    if ($connection->getAttribute('last_health_ok') === false && $status === self::OK) {
                        $status = self::DEGRADED;
                    }
                } elseif ($connStatus === 'degraded' && $status === self::OK) {
                    $status = self::DEGRADED;
                }

                $list[] = [
                    'connection_id' => (int) $connection->getKey(),
                    'connector_type' => $connection->getAttribute('connector_type'),
                    'status' => $connStatus,
                    'degraded_reason' => $connection->getAttribute('degraded_reason'),
                    'last_health_ok' => $connection->getAttribute('last_health_ok'),
                    'entities' => $entities,
                ];
            }

            return [
                'status' => $status,
                'details' => [
                    'connections_total' => $connections->count(),
                    'connections_active' => $active,
                    'connections' => $list,
                ],
            ];
        } catch (Throwable $e) {
            return ['status' => self::DOWN, 'details' => ['error_class' => $e::class]];
        }
    }

    public function httpStatus(string $status): int
    {
        return $status === self::DOWN ? 503 : 200;
    }
}
