<?php

declare(strict_types=1);

namespace App\Modules\Lexware\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Sleep;

/**
 * Zentraler Token-Bucket je Lexware-Zugang auf dem Cache (Redis in Produktion), Standard 2 Anfragen je Sekunde
 * (Snippet, vor Produktivbetrieb am Original prüfen; Konfiguration hub.lexware.rate_limit_rps). Eigener Bucket, damit
 * das Modul den Hub-RateLimitManager nicht mit Immoware-Connections vermischt.
 */
final class LexwareRateLimiter
{
    private const string PREFIX = 'hub:lexware:ratelimit:';

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly float $rps = 2.0,
    ) {}

    public function acquire(string $key, int $maxWaitMs = 5000): bool
    {
        $deadline = $this->nowMs() + $maxWaitMs;

        while (true) {
            if ($this->tryAcquire($key)) {
                return true;
            }

            $remaining = $deadline - $this->nowMs();

            if ($remaining <= 0) {
                return false;
            }

            Sleep::for(min(100, $remaining))->milliseconds();
        }
    }

    public function tryAcquire(string $key): bool
    {
        return (bool) $this->withLock($key, function () use ($key): bool {
            $rps = max(0.1, $this->rps);
            $capacity = max(1.0, ceil($rps));
            $now = $this->nowMs();
            /** @var array{tokens: float, updated_ms: int}|null $bucket */
            $bucket = $this->cache->get(self::PREFIX.$key);
            $bucket ??= ['tokens' => $capacity, 'updated_ms' => $now];
            $bucket['tokens'] = min($capacity, $bucket['tokens'] + max(0, $now - $bucket['updated_ms']) / 1000 * $rps);
            $bucket['updated_ms'] = $now;

            if ($bucket['tokens'] < 1.0) {
                $this->cache->put(self::PREFIX.$key, $bucket, now()->addHour());

                return false;
            }

            $bucket['tokens'] -= 1.0;
            $this->cache->put(self::PREFIX.$key, $bucket, now()->addHour());

            return true;
        });
    }

    /**
     * Serverseitige Drosselung (429 mit Retry-After): Bucket für die angegebene Zeit leeren.
     */
    public function penalize(string $key, int $seconds): void
    {
        $this->cache->put(self::PREFIX.$key, ['tokens' => -1.0 * max(0, $seconds) * max(0.1, $this->rps), 'updated_ms' => $this->nowMs()], now()->addHour());
    }

    public function reset(string $key): void
    {
        $this->cache->forget(self::PREFIX.$key);
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
