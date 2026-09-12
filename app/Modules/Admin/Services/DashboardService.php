<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Modules\Connector\Models\ImmowareConnection;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Documents\Models\Document;
use App\Modules\Estate\Models\Contract;
use App\Modules\Estate\Models\Property;
use App\Modules\Estate\Models\Unit;
use App\Modules\Sync\Enums\DlqStatus;
use App\Modules\Sync\Models\Conflict;
use App\Modules\Sync\Models\DlqItem;
use App\Modules\Sync\Models\SyncRun;
use App\Modules\Sync\Models\SyncState;
use App\Modules\Sync\Services\DataAgeService;
use App\Modules\Sync\Services\SyncMetrics;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kennzahlen des Dashboards. Ausschließlich lesend, alle Zähler über count() auf gescopten Queries
 * (Mandant über BelongsToOrganization, Soft Deletes über HasExternalIdentity ausgeschlossen).
 */
final class DashboardService
{
    /** @var array<int, string> */
    private const array DLQ_OPEN_STATUSES = [DlqStatus::Open->value, DlqStatus::Retrying->value];

    public function __construct(
        private readonly DataAgeService $dataAge,
        private readonly SyncMetrics $metrics,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $connections = ImmowareConnection::query()
            ->get(['id', 'name', 'connector_type', 'purpose', 'status', 'degraded_reason', 'write_enabled', 'last_health_ok', 'last_health_at', 'last_probe_at', 'poll_interval_seconds'])
            ->sortBy([['purpose', 'asc'], ['name', 'asc']])
            ->values();

        $connectionIds = $connections->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();

        return [
            'connections' => $this->connectionRows($connections->all(), $connectionIds),
            'counters' => $this->counters(),
            'issues' => $this->issues($connectionIds),
            'stale' => $this->staleHints($connections->all()),
            'metrics' => $this->metricSummary(),
            'recent_failures' => $this->recentFailures($connectionIds),
            'generated_at' => CarbonImmutable::now(),
        ];
    }

    /**
     * @param  array<int, ImmowareConnection>  $connections
     * @param  array<int, int>  $connectionIds
     * @return array<int, array<string, mixed>>
     */
    private function connectionRows(array $connections, array $connectionIds): array
    {
        $lastSuccess = $connectionIds === [] ? [] : SyncState::query()
            ->collections()
            ->whereIn('connection_id', $connectionIds)
            ->whereNotNull('last_success_at')
            ->groupBy('connection_id')
            ->pluck(DB::raw('max(last_success_at) as last_success_at'), 'connection_id')
            ->all();

        $lastRun = $connectionIds === [] ? [] : SyncRun::query()
            ->whereIn('connection_id', $connectionIds)
            ->where('status', 'succeeded')
            ->groupBy('connection_id')
            ->pluck(DB::raw('max(finished_at) as finished_at'), 'connection_id')
            ->all();

        $rows = [];

        foreach ($connections as $connection) {
            $id = (int) $connection->getKey();
            $success = $lastSuccess[$id] ?? $lastRun[$id] ?? null;

            $rows[] = [
                'id' => $id,
                'name' => (string) $connection->getAttribute('name'),
                'connector_type' => (string) $connection->getAttribute('connector_type'),
                'purpose' => (string) $connection->getAttribute('purpose'),
                'status' => (string) ($connection->getAttribute('status') ?? 'unknown'),
                'badge' => self::statusBadge((string) ($connection->getAttribute('status') ?? 'unknown')),
                'degraded_reason' => $connection->getAttribute('degraded_reason'),
                'write_enabled' => (bool) $connection->getAttribute('write_enabled'),
                'last_health_ok' => $connection->getAttribute('last_health_ok'),
                'last_health_at' => $connection->getAttribute('last_health_at'),
                'last_probe_at' => $connection->getAttribute('last_probe_at'),
                'last_success_at' => $success !== null ? CarbonImmutable::parse((string) $success) : null,
            ];
        }

        return $rows;
    }

    /**
     * Abbildung des Connection-Status auf die Statusbadges des Layouts.
     */
    public static function statusBadge(string $status): string
    {
        return match ($status) {
            'active' => 'ok',
            'paused' => 'disabled',
            'degraded' => 'warn',
            'error' => 'fail',
            default => 'unknown',
        };
    }

    /**
     * @return array<string, int>
     */
    private function counters(): array
    {
        return [
            'properties' => Property::query()->count(),
            'units' => Unit::query()->count(),
            'contacts' => Contact::query()->count(),
            'contracts' => Contract::query()->count(),
            'documents' => Document::query()->count(),
        ];
    }

    /**
     * Offene Fehler (DLQ) und offene Konflikte, begrenzt auf die Connections des Mandanten sowie
     * Einträge ohne Connection-Bezug.
     *
     * @param  array<int, int>  $connectionIds
     * @return array<string, int>
     */
    private function issues(array $connectionIds): array
    {
        $scope = static function (Builder $query) use ($connectionIds): void {
            $query->whereNull('connection_id');

            if ($connectionIds !== []) {
                $query->orWhereIn('connection_id', $connectionIds);
            }
        };

        return [
            'dlq_open' => DlqItem::query()->where($scope)->whereIn('status', self::DLQ_OPEN_STATUSES)->count(),
            'conflicts_open' => Conflict::query()->where($scope)->whereIn('status', Conflict::OPEN_STATUSES)->count(),
        ];
    }

    /**
     * Stale-Hinweise je aktiver Connection und Entität aus dem DataAgeService.
     *
     * @param  array<int, ImmowareConnection>  $connections
     * @return array<int, array<string, mixed>>
     */
    private function staleHints(array $connections): array
    {
        $hints = [];

        foreach ($connections as $connection) {
            if ($connection->getAttribute('status') !== 'active') {
                continue;
            }

            foreach ($this->dataAge->forConnection((int) $connection->getKey()) as $age) {
                if (! ($age['stale'] ?? false)) {
                    continue;
                }

                $hints[] = [
                    'connection' => (string) $connection->getAttribute('name'),
                    'entity_type' => (string) $age['entity_type'],
                    'last_success_at' => $age['last_success_at'],
                    'age_seconds' => $age['age_seconds'],
                    'threshold_seconds' => $age['threshold_seconds'],
                ];
            }
        }

        return $hints;
    }

    /**
     * Kennzahlen aus dem SyncMetrics-Cache. Die Queue-Tiefe wird ergänzend aus der jobs-Tabelle
     * gelesen, falls das Gauge queue_depth noch nicht gesetzt wurde.
     *
     * @return array<string, int|float>
     */
    private function metricSummary(): array
    {
        $summary = $this->metrics->summary();

        if (($summary[SyncMetrics::QUEUE_DEPTH] ?? 0) === 0 && Schema::hasTable('jobs')) {
            $summary[SyncMetrics::QUEUE_DEPTH] = (int) DB::table('jobs')->count();
        }

        if (($summary[SyncMetrics::FAILED_JOBS] ?? 0) === 0 && Schema::hasTable('failed_jobs')) {
            $summary[SyncMetrics::FAILED_JOBS] = (int) DB::table('failed_jobs')->count();
        }

        return $summary;
    }

    /**
     * @param  array<int, int>  $connectionIds
     * @return array<int, SyncRun>
     */
    private function recentFailures(array $connectionIds): array
    {
        if ($connectionIds === []) {
            return [];
        }

        $limit = max(1, (int) config('hub.admin.dashboard.recent_failures', 5));

        // lazy() mit Seitengröße = Limit liest genau eine Seite, take() beendet den Generator danach.
        return SyncRun::query()
            ->where(static fn (Builder $query) => $query->whereIn('connection_id', $connectionIds)->whereIn('status', ['failed', 'aborted']))
            ->latest('started_at')
            ->lazy($limit)
            ->take($limit)
            ->values()
            ->all();
    }
}
