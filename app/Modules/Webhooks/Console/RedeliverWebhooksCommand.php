<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Console;

use App\Modules\Webhooks\Jobs\DeliverWebhookJob;
use App\Modules\Webhooks\Models\WebhookDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Bus\Dispatcher;

/**
 * hub:webhooks:redeliver stellt fällige failed-Zustellungen (next_attempt_at <= jetzt) erneut in die Queue, sofern
 * sie nicht bereits in der Queue liegen (queued_at gesetzt und Fälligkeit noch innerhalb der Karenz). pending-Zustellungen
 * werden nicht angefasst, ihr Job liegt aus dem Dispatch in der Queue. Optional eine DLQ-Zustellung per --dead=<id>.
 */
final class RedeliverWebhooksCommand extends Command
{
    protected $signature = 'hub:webhooks:redeliver {--dead= : ID einer Zustellung mit Status dead erneut anstoßen} {--limit=200}';

    protected $description = 'Stellt fällige Webhook-Zustellungen erneut in die Queue.';

    public function handle(Dispatcher $bus): int
    {
        $queue = (string) config('hub.webhooks.queue', 'default');
        $dead = $this->option('dead');

        if (is_string($dead) && $dead !== '') {
            $delivery = WebhookDelivery::query()->whereKey((int) $dead)->where('status', WebhookDelivery::STATUS_DEAD)->first();

            if ($delivery === null) {
                $this->error('Keine DLQ-Zustellung mit dieser ID.');

                return self::FAILURE;
            }

            $delivery->forceFill(['status' => WebhookDelivery::STATUS_PENDING, 'attempts' => 0, 'dead_at' => null, 'next_attempt_at' => CarbonImmutable::now(), 'queued_at' => CarbonImmutable::now()])->save();
            $bus->dispatch((new DeliverWebhookJob((int) $delivery->getKey()))->onQueue($queue));
            $this->info('Zustellung erneut eingereiht.');

            return self::SUCCESS;
        }

        $count = 0;
        $now = CarbonImmutable::now();

        // Nur failed und fällig. Liegt der Job noch in der Queue (queued_at gesetzt), erst wenn sowohl Fälligkeit als
        // auch Einreihung um die Karenz zurückliegen (verlorener Job nach Worker-Ausfall); sonst würde der
        // Queue-Retry bzw. eine frische Einreihung doppelt zustellen.
        $grace = max(0, (int) config('hub.webhooks.redeliver_grace_seconds', 300));
        $threshold = $now->subSeconds($grace);

        $ids = WebhookDelivery::query()
            ->where('status', WebhookDelivery::STATUS_FAILED)
            ->where('next_attempt_at', '<=', $now)
            ->where(static function ($query) use ($threshold): void {
                $query->whereNull('queued_at')
                    ->orWhere(static function ($lost) use ($threshold): void {
                        $lost->where('next_attempt_at', '<=', $threshold)->where('queued_at', '<=', $threshold);
                    });
            })
            ->orderBy('id')
            ->limit((int) $this->option('limit'))
            ->pluck('id');

        foreach ($ids as $id) {
            WebhookDelivery::query()->whereKey($id)->update(['queued_at' => $now]);
            $bus->dispatch((new DeliverWebhookJob((int) $id))->onQueue($queue));
            $count++;
        }

        $this->info(sprintf('%d Zustellungen eingereiht.', $count));

        return self::SUCCESS;
    }
}
