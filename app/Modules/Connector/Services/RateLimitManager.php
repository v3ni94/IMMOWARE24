<?php

declare(strict_types=1);

namespace App\Modules\Connector\Services;

use App\Core\Contracts\RateLimiterInterface;
use App\Core\Exceptions\RateLimitedException;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Sleep;

/**
 * Zentraler Token-Bucket je Connection und Kanal auf dem Cache (Redis in Produktion).
 * Schlüsselformat: siehe keyFor(). Konfigurierbare rps und Concurrency, Drosselung auf die Hälfte
 * für throttle_seconds bei 429, Timeout, 5xx oder Latenzanstieg (docs/immoware/06-rate-limits.md 4.1).
 */
final class RateLimitManager implements RateLimiterInterface
{
    private const string PREFIX = 'hub:ratelimit:';

    /** @var array<int, string> */
    public const array METRICS = ['429_count', 'throttle_count', 'timeout_count', '5xx_count', 'latency_spike_count', 'acquired_count', 'rejected_count'];

    /**
     * @param  array<string, mixed>  $config  Inhalt von config('hub.connector.rate_limit')
     */
    public function __construct(
        private readonly CacheRepository $cache,
        private readonly array $config,
    ) {}

    public static function keyFor(int $connectionId, string $channel = 'read'): string
    {
        return sprintf('conn:%d:%s', $connectionId, strtolower($channel));
    }

    public function configure(string $key, float $rps, int $concurrency): void
    {
        $this->cache->put(self::PREFIX.$key.':config', [
            'rps' => max(0.01, $rps),
            'concurrency' => max(1, $concurrency),
        ], now()->addDay());
    }

    public function acquire(string $key, int $maxWaitMs = 5000): void
    {
        $deadline = $this->nowMs() + $maxWaitMs;

        while (true) {
            if ($this->tryAcquire($key)) {
                return;
            }

            $remainingMs = $deadline - $this->nowMs();

            if ($remainingMs <= 0) {
                $this->increment($key, 'rejected_count');

                throw new RateLimitedException(
                    sprintf('Rate Limit für "%s" erreicht, kein Slot innerhalb von %d ms.', $key, $maxWaitMs),
                    (int) ceil(1 / $this->effectiveRps($key)),
                );
            }

            Sleep::for(min(50, $remainingMs))->milliseconds();
        }
    }

    public function tryAcquire(string $key): bool
    {
        return (bool) $this->withLock($key, function () use ($key): bool {
            $bucket = $this->refilledBucket($key);
            $concurrency = $this->settings($key)['concurrency'];
            $inFlight = $this->inFlight($key);

            if ($bucket['tokens'] < 1.0 || $inFlight >= $concurrency) {
                $this->storeBucket($key, $bucket);

                return false;
            }

            $bucket['tokens'] -= 1.0;
            $this->storeBucket($key, $bucket);
            $this->cache->put(self::PREFIX.$key.':inflight', $inFlight + 1, $this->slotTtl());
            $this->increment($key, 'acquired_count');

            return true;
        });
    }

    /**
     * Gibt einen belegten Concurrency-Slot zurück. Muss nach jedem Request aufgerufen werden.
     */
    public function release(string $key): void
    {
        $this->withLock($key, function () use ($key): void {
            $inFlight = $this->inFlight($key);
            $this->cache->put(self::PREFIX.$key.':inflight', max(0, $inFlight - 1), $this->slotTtl());
        });
    }

    public function remaining(string $key): int
    {
        $bucket = $this->refilledBucket($key);
        $slots = max(0, $this->settings($key)['concurrency'] - $this->inFlight($key));

        return (int) min(floor($bucket['tokens']), $slots);
    }

    public function reset(string $key): void
    {
        foreach (['bucket', 'inflight', 'throttle', 'latency', 'config'] as $suffix) {
            $this->cache->forget(self::PREFIX.$key.':'.$suffix);
        }

        foreach (self::METRICS as $metric) {
            $this->cache->forget(self::PREFIX.$key.':metric:'.$metric);
        }
    }

    /**
     * Auswertung einer Serverantwort. 429, Timeout, 5xx und Latenzanstieg halbieren die Rate.
     */
    public function reportResponse(string $key, ?int $status, ?int $durationMs = null, bool $timedOut = false): void
    {
        if ($timedOut) {
            $this->increment($key, 'timeout_count');
            $this->throttle($key, 'timeout');

            return;
        }

        if ($status === 429 || $status === 503) {
            $this->increment($key, '429_count');
            $this->throttle($key, (string) $status);

            return;
        }

        if ($status !== null && $status >= 500) {
            $this->increment($key, '5xx_count');
            $this->throttle($key, (string) $status);

            return;
        }

        if ($durationMs !== null) {
            $this->trackLatency($key, $durationMs);
        }
    }

    public function isThrottled(string $key): bool
    {
        return $this->cache->has(self::PREFIX.$key.':throttle');
    }

    public function effectiveRps(string $key): float
    {
        $rps = $this->settings($key)['rps'];

        if ($this->isThrottled($key)) {
            $rps = max((float) ($this->config['min_rps'] ?? 0.25), $rps / 2);
        }

        return $rps;
    }

    /**
     * @return array<string, int|float|bool>
     */
    public function metrics(string $key): array
    {
        $metrics = [];

        foreach (self::METRICS as $metric) {
            $metrics[$metric] = (int) $this->cache->get(self::PREFIX.$key.':metric:'.$metric, 0);
        }

        $metrics['throttled'] = $this->isThrottled($key);
        $metrics['effective_rps'] = $this->effectiveRps($key);
        $metrics['in_flight'] = $this->inFlight($key);

        return $metrics;
    }

    private function throttle(string $key, string $reason): void
    {
        $seconds = (int) ($this->config['throttle_seconds'] ?? 600);
        $this->cache->put(self::PREFIX.$key.':throttle', ['reason' => $reason, 'since' => CarbonImmutable::now()->toIso8601String()], $seconds);
        $this->increment($key, 'throttle_count');
    }

    private function trackLatency(string $key, int $durationMs): void
    {
        $latencyKey = self::PREFIX.$key.':latency';
        /** @var array{ema: float, samples: int} $state */
        $state = $this->cache->get($latencyKey, ['ema' => 0.0, 'samples' => 0]);

        $threshold = (int) ($this->config['latency_threshold_ms'] ?? 5000);
        $factor = (float) ($this->config['latency_spike_factor'] ?? 3.0);

        $spike = $durationMs >= $threshold
            && ($state['samples'] < 3 || $durationMs >= $state['ema'] * $factor);

        if ($spike) {
            $this->increment($key, 'latency_spike_count');
            $this->throttle($key, 'latency');
        }

        $alpha = 0.2;
        $state['ema'] = $state['samples'] === 0 ? (float) $durationMs : ($alpha * $durationMs + (1 - $alpha) * $state['ema']);
        $state['samples']++;

        $this->cache->put($latencyKey, $state, now()->addHour());
    }

    /**
     * @return array{rps: float, concurrency: int}
     */
    private function settings(string $key): array
    {
        /** @var array{rps: float, concurrency: int}|null $stored */
        $stored = $this->cache->get(self::PREFIX.$key.':config');

        return $stored ?? [
            'rps' => (float) ($this->config['default_rps'] ?? 2.0),
            'concurrency' => (int) ($this->config['default_concurrency'] ?? 2),
        ];
    }

    /**
     * @return array{tokens: float, updated_ms: int}
     */
    private function refilledBucket(string $key): array
    {
        $rps = $this->effectiveRps($key);
        $capacity = max(1.0, ceil($rps));
        $now = $this->nowMs();

        /** @var array{tokens: float, updated_ms: int}|null $bucket */
        $bucket = $this->cache->get(self::PREFIX.$key.':bucket');

        if ($bucket === null) {
            return ['tokens' => $capacity, 'updated_ms' => $now];
        }

        $elapsedSeconds = max(0, $now - $bucket['updated_ms']) / 1000;
        $bucket['tokens'] = min($capacity, $bucket['tokens'] + $elapsedSeconds * $rps);
        $bucket['updated_ms'] = $now;

        return $bucket;
    }

    /**
     * @param  array{tokens: float, updated_ms: int}  $bucket
     */
    private function storeBucket(string $key, array $bucket): void
    {
        $this->cache->put(self::PREFIX.$key.':bucket', $bucket, now()->addHour());
    }

    private function inFlight(string $key): int
    {
        return (int) $this->cache->get(self::PREFIX.$key.':inflight', 0);
    }

    private function increment(string $key, string $metric): void
    {
        $metricKey = self::PREFIX.$key.':metric:'.$metric;

        if (! $this->cache->has($metricKey)) {
            $this->cache->put($metricKey, 0, (int) ($this->config['metrics_ttl_seconds'] ?? 86400));
        }

        $this->cache->increment($metricKey);
    }

    private function slotTtl(): int
    {
        return (int) ($this->config['slot_ttl_seconds'] ?? 300);
    }

    private function nowMs(): int
    {
        return (int) CarbonImmutable::now()->getPreciseTimestamp(3);
    }

    private function withLock(string $key, callable $callback): mixed
    {
        $store = $this->cache->getStore();

        if ($store instanceof LockProvider) {
            return $store->lock(self::PREFIX.$key.':lock', 5)->block(5, $callback);
        }

        return $callback();
    }
}
