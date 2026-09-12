<?php

declare(strict_types=1);

namespace App\Modules\Sync\Support;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;
use InvalidArgumentException;

/**
 * Heartbeat-Dateien für die Container-Healthchecks (docs/operations/03-monitoring.md Abschnitt 5, Änderungsvermerk
 * 12.09.2026): worker (geschrieben vom WorkerHeartbeatJob auf der Queue high) und scheduler (geschrieben vom
 * Kommando hub:worker:heartbeat, das schedule:work minütlich ausführt). Inhalt ist der UTC-Zeitstempel des letzten
 * Lebenszeichens; der Healthcheck prüft nur dessen Alter. Zusätzlich liegt der Zeitstempel im Cache-Schlüssel
 * hub:heartbeat:<name>, damit /health/queue ihn ohne Dateizugriff lesen kann.
 */
final class Heartbeat
{
    public const string WORKER = 'worker';

    public const string SCHEDULER = 'scheduler';

    public const int DEFAULT_MAX_AGE_SECONDS = 180;

    public function __construct(private readonly Cache $cache) {}

    public static function path(string $name): string
    {
        return storage_path('framework/'.self::validName($name).'-heartbeat');
    }

    public static function cacheKey(string $name): string
    {
        return 'hub:heartbeat:'.self::validName($name);
    }

    public function beat(string $name, ?CarbonImmutable $at = null): CarbonImmutable
    {
        $at ??= CarbonImmutable::now('UTC');
        $path = self::path($name);
        $directory = dirname($path);

        if (! is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        file_put_contents($path, $at->toIso8601ZuluString().PHP_EOL, LOCK_EX);
        $this->cache->put(self::cacheKey($name), $at->toIso8601ZuluString(), 3600);

        return $at;
    }

    /**
     * Zeitpunkt des letzten Lebenszeichens (Datei, sonst Cache), null wenn keines vorliegt.
     */
    public function lastBeat(string $name): ?CarbonImmutable
    {
        $path = self::path($name);
        $raw = is_file($path) ? trim((string) file_get_contents($path)) : null;

        if ($raw === null || $raw === '') {
            $cached = $this->cache->get(self::cacheKey($name));
            $raw = is_string($cached) ? $cached : null;
        }

        if ($raw === null || $raw === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($raw)->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    public function ageSeconds(string $name, ?CarbonImmutable $now = null): ?int
    {
        $last = $this->lastBeat($name);

        if ($last === null) {
            return null;
        }

        return max(0, (int) $last->diffInSeconds($now ?? CarbonImmutable::now('UTC'), true));
    }

    public function isFresh(string $name, int $maxAgeSeconds = self::DEFAULT_MAX_AGE_SECONDS, ?CarbonImmutable $now = null): bool
    {
        $age = $this->ageSeconds($name, $now);

        return $age !== null && $age <= $maxAgeSeconds;
    }

    private static function validName(string $name): string
    {
        if (! in_array($name, [self::WORKER, self::SCHEDULER], true)) {
            throw new InvalidArgumentException(sprintf('Unbekannter Heartbeat "%s", erlaubt: worker, scheduler.', $name));
        }

        return $name;
    }
}
