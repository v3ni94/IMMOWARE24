<?php

declare(strict_types=1);

namespace App\Modules\Gmail\Services;

use App\Modules\Gmail\Exceptions\GmailQuotaExceededException;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository;

/**
 * Quota-Zähler je Postfach und Minute (Einheiten der Gmail-API, Kosten aus config hub.gmail.quota.costs,
 * aus Snippets, vor Produktivbetrieb am Original zu prüfen). Wird vor jedem Aufruf reserviert; bei Überschreitung
 * wird der Aufruf nicht abgesetzt, sondern GmailQuotaExceededException geworfen (Retry über Backoff).
 */
final class QuotaCounter
{
    public function __construct(
        private readonly CacheRepository $cache,
        private readonly Repository $config,
    ) {}

    public function costOf(string $method): int
    {
        return max(1, (int) $this->config->get('hub.gmail.quota.costs.'.$method, 5));
    }

    public function limit(): int
    {
        return max(1, (int) $this->config->get('hub.gmail.quota.per_minute_per_mailbox', 5000));
    }

    /**
     * @throws GmailQuotaExceededException
     */
    public function reserve(int $mailboxId, string $method): void
    {
        $cost = $this->costOf($method);
        $key = $this->key($mailboxId);
        $used = (int) $this->cache->get($key, 0);

        if ($used + $cost > $this->limit()) {
            throw new GmailQuotaExceededException($mailboxId, $used, $this->limit());
        }

        if ($used === 0) {
            $this->cache->put($key, $cost, 60);

            return;
        }

        $this->cache->increment($key, $cost);
    }

    public function used(int $mailboxId): int
    {
        return (int) $this->cache->get($this->key($mailboxId), 0);
    }

    private function key(int $mailboxId): string
    {
        return 'mail:gmail:quota:'.$mailboxId.':'.gmdate('YmdHi');
    }
}
