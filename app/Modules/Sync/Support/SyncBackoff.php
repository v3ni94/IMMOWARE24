<?php

declare(strict_types=1);

namespace App\Modules\Sync\Support;

/**
 * Retry-Stufen nach 07-sync-strategy.md Abschnitt 6.1: 30 s, 2 min, 10 min, 30 min plus Jitter.
 * Der fünfte Versuch endet in der Dead Letter Queue.
 */
final class SyncBackoff
{
    /** @var array<int, int> */
    public const array BASE_SECONDS = [30, 120, 600, 1800];

    /** @var array<int, int> */
    public const array MAX_JITTER_SECONDS = [10, 30, 60, 120];

    /**
     * @param  array<int, int>|null  $base
     * @param  array<int, int>|null  $jitter
     * @return array<int, int>
     */
    public static function seconds(?array $base = null, ?array $jitter = null, bool $withJitter = true): array
    {
        $base ??= array_map('intval', (array) config('hub.sync.jobs.backoff', self::BASE_SECONDS));
        $jitter ??= array_map('intval', (array) config('hub.sync.jobs.jitter', self::MAX_JITTER_SECONDS));

        $result = [];

        foreach (array_values($base) as $index => $seconds) {
            $max = $jitter[$index] ?? 0;
            $result[] = $withJitter && $max > 0 ? $seconds + random_int(0, $max) : $seconds;
        }

        return $result;
    }

    /**
     * Prüft, ob ein Wert innerhalb der Stufe (Basis plus maximaler Jitter) liegt.
     */
    public static function isWithinStage(int $stageIndex, int $seconds): bool
    {
        $base = self::BASE_SECONDS[$stageIndex] ?? null;
        $jitter = self::MAX_JITTER_SECONDS[$stageIndex] ?? 0;

        return $base !== null && $seconds >= $base && $seconds <= $base + $jitter;
    }
}
