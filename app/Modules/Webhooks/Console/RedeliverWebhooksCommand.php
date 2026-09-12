<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Console;

use App\Modules\Webhooks\Jobs\DeliverWebhookJob;
use App\Modules\Webhooks\Models\WebhookDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Bus\Dispatcher;

/**
 * hub:webhooks:redeliver stellt fällige Zustellungen (pending, failed) erneut in die Queue,
 * optional eine DLQ-Zustellung per --dead=<id> (manuelle Wiederzustellung).
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

            $delivery->forceFill(['status' => WebhookDelivery::STATUS_PENDING, 'attempts' => 0, 'dead_at' => null, 'next_attempt_at' => CarbonImmutable::now()])->save();
            $bus->dispatch((new DeliverWebhookJob((int) $delivery->getKey()))->onQueue($queue));
            $this->info('Zustellung erneut eingereiht.');

            return self::SUCCESS;
        }

        $count = 0;

        $ids = WebhookDelivery::query()
            ->whereIn('status', [WebhookDelivery::STATUS_PENDING, WebhookDelivery::STATUS_FAILED])
            ->where('next_attempt_at', '<=', CarbonImmutable::now())
            ->orderBy('id')
            ->limit((int) $this->option('limit'))
            ->pluck('id');

        foreach ($ids as $id) {
            $bus->dispatch((new DeliverWebhookJob((int) $id))->onQueue($queue));
            $count++;
        }

        $this->info(sprintf('%d Zustellungen eingereiht.', $count));

        return self::SUCCESS;
    }
}
