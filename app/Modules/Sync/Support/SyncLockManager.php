<?php

declare(strict_types=1);

namespace App\Modules\Sync\Support;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Lock je Connection und Adapter (Entität) für jeden Sync-Lauf, Full wie Incremental (07-sync-strategy.md
 * Abschnitt 5: kein paralleler Lauf derselben Quelle, auch nicht Incremental neben Full). TTL mindestens das
 * Doppelte der erwarteten Laufdauer (2 mal Job-Timeout), Verlängerung je Chunk (Heartbeat), Wiederaufnahme durch
 * denselben Besitzer bei Retry. Locks werden ausschließlich vom Besitzer (Owner-Token) freigegeben, nie blind
 * gelöscht. Änderungsvermerk 12.09.2026.
 */
final class SyncLockManager
{
    public static function key(int $connectionId, string $entityType): string
    {
        return sprintf('immoware:sync:%d:%s', $connectionId, $entityType);
    }

    /**
     * Deterministischer Owner-Token je Queue-Job: ein Retry desselben Jobs nimmt seinen eigenen Lock wieder auf,
     * ohne ihn bei einer transienten Exception freigeben zu müssen.
     */
    public static function ownerForJob(?string $jobUuid): string
    {
        return $jobUuid !== null && $jobUuid !== '' ? 'job:'.$jobUuid : (string) Str::uuid();
    }

    /**
     * Versucht den Lock zu erwerben. Liefert den Owner-Token oder null, wenn ein anderer Lauf ihn hält.
     */
    public function acquire(int $connectionId, string $entityType, ?int $ttlSeconds = null, ?string $owner = null): ?string
    {
        $owner ??= (string) Str::uuid();

        return $this->acquireOrResume($connectionId, $entityType, $owner, $ttlSeconds) ? $owner : null;
    }

    /**
     * Erwirbt den Lock oder nimmt ihn wieder auf, wenn er bereits von diesem Besitzer gehalten wird (Retry,
     * Fortsetzungsjob). Bei Wiederaufnahme wird die TTL erneuert.
     */
    public function acquireOrResume(int $connectionId, string $entityType, string $owner, ?int $ttlSeconds = null): bool
    {
        $ttl = $ttlSeconds ?? $this->defaultTtl();
        $lock = Cache::lock(self::key($connectionId, $entityType), $ttl, $owner);

        if ($lock->get()) {
            return true;
        }

        $restored = $this->restore($connectionId, $entityType, $owner);

        if (! $restored->isOwnedByCurrentProcess()) {
            return false;
        }

        $this->refresh($restored, $ttl);

        return true;
    }

    /**
     * Heartbeat je Chunk: verlängert die TTL, sofern der Lock noch diesem Besitzer gehört. false bedeutet
     * Lock-Verlust (TTL abgelaufen und von einem anderen Lauf übernommen, oder Cache-Ausfall): der Lauf ist als
     * aborted zu beenden, ohne Cursor-Commit und ohne Soft Deletes.
     */
    public function renew(int $connectionId, string $entityType, string $owner, ?int $ttlSeconds = null): bool
    {
        $lock = $this->restore($connectionId, $entityType, $owner);

        if (! $lock->isOwnedByCurrentProcess()) {
            return false;
        }

        return $this->refresh($lock, $ttlSeconds ?? $this->defaultTtl());
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

    /**
     * TTL: konfigurierter Wert, mindestens jedoch das Doppelte des Job-Timeouts und 30 Minuten.
     */
    public function defaultTtl(): int
    {
        $timeout = max(60, (int) config('hub.sync.jobs.timeout_seconds', 900));

        return max(1800, 2 * $timeout, (int) config('hub.sync.locks.full_ttl_seconds', 7200));
    }

    /**
     * Treiber ohne refresh() (z. B. Datei- oder Datenbank-Lock) behalten die ursprüngliche TTL; der Besitz gilt dann als bestätigt.
     */
    private function refresh(Lock $lock, int $ttl): bool
    {
        try {
            return (bool) $lock->refresh($ttl);
        } catch (RuntimeException) {
            return true;
        }
    }
}
