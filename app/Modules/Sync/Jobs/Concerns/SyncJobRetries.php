<?php

declare(strict_types=1);

namespace App\Modules\Sync\Jobs\Concerns;

use App\Modules\Sync\Support\SyncBackoff;

/**
 * Gemeinsame Retry-Parameter der Sync-Jobs: 5 Fehlversuche (maxExceptions), Backoff 30 s, 2 min, 10 min, 30 min plus
 * Jitter, Timeout. tries bleibt 0 (unbegrenzt), weil jedes Release durch WithoutOverlapping::releaseAfter attempts
 * erhöht; nur echte Exceptions zählen gegen maxExceptions (Änderungsvermerk 12.09.2026).
 */
trait SyncJobRetries
{
    public int $tries = 0;

    public int $timeout = 900;

    public int $maxExceptions = 5;

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return SyncBackoff::seconds(withJitter: (bool) config('hub.sync.jobs.jitter_enabled', true));
    }

    protected function applyRetryConfig(): void
    {
        $this->tries = 0;
        $this->maxExceptions = max(1, (int) config('hub.sync.jobs.tries', 5));
        $this->timeout = max(60, (int) config('hub.sync.jobs.timeout_seconds', 900));
        $this->onQueue((string) config('hub.sync.queue', 'sync'));
    }
}
