<?php

declare(strict_types=1);

namespace App\Modules\Sync\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Leichtgewichtige Zähler und Dauern im Cache. Export als Array für /health und Admin.
 * Kennzahlen: sync_duration, sync_records, sync_errors, remote_requests, 429_count, queue_depth, failed_jobs.
 */
final class SyncMetrics
{
    public const string SYNC_DURATION = 'sync_duration';

    public const string SYNC_RECORDS = 'sync_records';

    public const string SYNC_ERRORS = 'sync_errors';

    public const string REMOTE_REQUESTS = 'remote_requests';

    public const string RATE_LIMITED = '429_count';

    public const string QUEUE_DEPTH = 'queue_depth';

    public const string FAILED_JOBS = 'failed_jobs';

    public const string SKIPPED_LOCKED = 'skipped_locked';

    /** @var array<int, string> */
    public const array KNOWN = [
        self::SYNC_DURATION, self::SYNC_RECORDS, self::SYNC_ERRORS, self::REMOTE_REQUESTS,
        self::RATE_LIMITED, self::QUEUE_DEPTH, self::FAILED_JOBS, self::SKIPPED_LOCKED,
    ];

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly string $prefix = 'hub:sync:metrics',
        private readonly int $ttlSeconds = 604800,
    ) {}

    /**
     * @param  array<string, scalar>  $labels
     */
    public function increment(string $metric, int $by = 1, array $labels = []): void
    {
        $key = $this->key($metric, $labels);
        $this->register($metric, $labels);

        if (! $this->cache->has($key)) {
            $this->cache->put($key, 0, $this->ttlSeconds);
        }

        $this->cache->increment($key, $by);
    }

    /**
     * @param  array<string, scalar>  $labels
     */
    public function gauge(string $metric, int|float $value, array $labels = []): void
    {
        $this->register($metric, $labels);
        $this->cache->put($this->key($metric, $labels), $value, $this->ttlSeconds);
    }

    /**
     * Dauer in Millisekunden: Summe, Anzahl und Maximum je Label-Satz.
     *
     * @param  array<string, scalar>  $labels
     */
    public function observeDuration(int $milliseconds, array $labels = []): void
    {
        $this->increment(self::SYNC_DURATION.'_sum_ms', $milliseconds, $labels);
        $this->increment(self::SYNC_DURATION.'_count', 1, $labels);

        $maxKey = $this->key(self::SYNC_DURATION.'_max_ms', $labels);
        $this->register(self::SYNC_DURATION.'_max_ms', $labels);
        $current = (int) $this->cache->get($maxKey, 0);

        if ($milliseconds > $current) {
            $this->cache->put($maxKey, $milliseconds, $this->ttlSeconds);
        }
    }

    /**
     * @param  array<string, scalar>  $labels
     */
    public function value(string $metric, array $labels = []): int|float
    {
        $value = $this->cache->get($this->key($metric, $labels), 0);

        return is_numeric($value) ? $value + 0 : 0;
    }

    /**
     * @return array<string, array<int, array{labels: array<string, scalar>, value: int|float}>>
     */
    public function export(): array
    {
        $registry = $this->registry();
        $result = [];

        foreach ($registry as $entry) {
            $result[$entry['metric']][] = [
                'labels' => $entry['labels'],
                'value' => $this->value($entry['metric'], $entry['labels']),
            ];
        }

        ksort($result);

        return $result;
    }

    /**
     * Kompakte Zusammenfassung ohne Labels für /health.
     *
     * @return array<string, int|float>
     */
    public function summary(): array
    {
        $summary = array_fill_keys(self::KNOWN, 0);

        foreach ($this->registry() as $entry) {
            $metric = $entry['metric'];

            if (str_starts_with($metric, self::SYNC_DURATION.'_')) {
                $metric = self::SYNC_DURATION.substr($metric, strlen(self::SYNC_DURATION));
            }

            $summary[$metric] = ($summary[$metric] ?? 0) + $this->value($entry['metric'], $entry['labels']);
        }

        return $summary;
    }

    public function reset(): void
    {
        foreach ($this->registry() as $entry) {
            $this->cache->forget($this->key($entry['metric'], $entry['labels']));
        }

        $this->cache->forget($this->prefix.':registry');
    }

    /**
     * @param  array<string, scalar>  $labels
     */
    private function key(string $metric, array $labels): string
    {
        ksort($labels);
        $suffix = $labels === [] ? '' : ':'.http_build_query($labels, '', ',');

        return $this->prefix.':'.$metric.$suffix;
    }

    /**
     * @param  array<string, scalar>  $labels
     */
    private function register(string $metric, array $labels): void
    {
        ksort($labels);
        $registry = $this->registry();
        $id = $this->key($metric, $labels);

        if (isset($registry[$id])) {
            return;
        }

        $registry[$id] = ['metric' => $metric, 'labels' => $labels];
        $this->cache->put($this->prefix.':registry', $registry, $this->ttlSeconds);
    }

    /**
     * @return array<string, array{metric: string, labels: array<string, scalar>}>
     */
    private function registry(): array
    {
        $registry = $this->cache->get($this->prefix.':registry', []);

        return is_array($registry) ? $registry : [];
    }
}
