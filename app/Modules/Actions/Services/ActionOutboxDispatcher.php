<?php

declare(strict_types=1);

namespace App\Modules\Actions\Services;

use App\Core\Contracts\WebhookDispatcherInterface;
use App\Modules\Actions\Models\ActionOutbox;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Verarbeitet mail_outbox: pending-Einträge werden als Hub-Ereignisse (Präfix mail.) an den WebhookDispatcher
 * übergeben und dann als dispatched markiert; nach hub.actions.outbox.max_attempts erfolglosen Versuchen failed.
 * Statusübergänge pending -> dispatched | failed, attempts zählt jeden Versuch (docs/mail/02-datenmodell.md).
 */
final class ActionOutboxDispatcher
{
    public const string STATUS_PENDING = 'pending';

    public const string STATUS_DISPATCHED = 'dispatched';

    public const string STATUS_FAILED = 'failed';

    public function __construct(
        private readonly WebhookDispatcherInterface $webhooks,
        private readonly Repository $config,
    ) {}

    /**
     * @return array{dispatched: int, failed: int, retried: int}
     */
    public function dispatchPending(?int $limit = null): array
    {
        $limit ??= max(1, (int) $this->config->get('hub.actions.outbox.batch_size', 500));
        $maxAttempts = max(1, (int) $this->config->get('hub.actions.outbox.max_attempts', 5));
        $stats = ['dispatched' => 0, 'failed' => 0, 'retried' => 0];
        $processed = 0;

        ActionOutbox::query()->withoutGlobalScopes()
            ->where('status', self::STATUS_PENDING)
            ->lazyById(200)
            ->each(function (ActionOutbox $entry) use (&$stats, &$processed, $limit, $maxAttempts): bool {
                if ($processed >= $limit) {
                    return false;
                }

                $processed++;
                $attempts = (int) $entry->getAttribute('attempts') + 1;

                try {
                    $this->webhooks->dispatch(
                        'mail.'.(string) $entry->getAttribute('event'),
                        [
                            'aggregate_type' => (string) $entry->getAttribute('aggregate_type'),
                            'aggregate_id' => (int) $entry->getAttribute('aggregate_id'),
                            'outbox_id' => (int) $entry->getKey(),
                            'payload' => (array) ($entry->getAttribute('payload_json') ?? []),
                        ],
                        (int) $entry->getAttribute('organization_id'),
                    );
                } catch (Throwable $e) {
                    $exhausted = $attempts >= $maxAttempts;
                    $entry->forceFill(['attempts' => $attempts, 'status' => $exhausted ? self::STATUS_FAILED : self::STATUS_PENDING]);
                    $entry->save();
                    $stats[$exhausted ? 'failed' : 'retried']++;
                    Log::warning('Outbox-Eintrag nicht übergeben.', ['outbox_id' => $entry->getKey(), 'event' => $entry->getAttribute('event'), 'attempt' => $attempts, 'error' => $e::class, 'final' => $exhausted]);

                    return true;
                }

                $entry->forceFill(['attempts' => $attempts, 'status' => self::STATUS_DISPATCHED, 'dispatched_at' => CarbonImmutable::now()]);
                $entry->save();
                $stats['dispatched']++;

                return true;
            });

        return $stats;
    }
}
