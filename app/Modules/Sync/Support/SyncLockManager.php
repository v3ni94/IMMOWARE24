<?php

declare(strict_types=1);

namespace App\Modules\Sync\Support;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Locks für Full Syncs je Connection und Entität. Locks werden ausschließlich vom Besitzer
 * (Owner-Token) freigegeben, nie blind gelöscht (07-sync-strategy.md Abschnitt 5).
 */
final class SyncLockManager
{
    public static function key(int $connectionId, string $entityType): string
    {
        return sprintf('immoware:sync:%d:%s', $connectionId, $entityType);
    }

    /**
     * Versucht den Lock zu erwerben. Liefert den Owner-Token oder null, wenn ein anderer Lauf ihn hält.
     */
    public function acquire(int $connectionId, string $entityType, ?int $ttlSeconds = null): ?string
    {
        $owner = (string) Str::uuid();
        $lock = Cache::lock(self::key($connectionId, $entityType), $ttlSeconds ?? $this->defaultTtl(), $owner);

        return $lock->get() ? $owner : null;
    }

    /**
     * Setzt einen in einem vorherigen Chunk erworbenen Lock fort.
     */
    public function restore(int $connectionId, string $entityType, string $owner): Lock
    {
        return Cache::restoreLock(self::key($connectionId, $entityType), $owner);
    }

    public function release(int $connectionId, string $entityType, ?string $owner): void
    {
        if ($owner === null) {
            return;
        }

        $this->restore($connectionId, $entityType, $owner)->release();
    }

    public function isLocked(int $connectionId, string $entityType): bool
    {
        $lock = Cache::lock(self::key($connectionId, $entityType), 1);

        if ($lock->get()) {
            $lock->release();

            return false;
        }

        return true;
    }

    public function defaultTtl(): int
    {
        return max(1800, (int) config('hub.sync.locks.full_ttl_seconds', 7200));
    }
}
