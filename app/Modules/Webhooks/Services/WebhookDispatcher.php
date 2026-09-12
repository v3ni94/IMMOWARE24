<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Services;

use App\Core\Contracts\WebhookDispatcherInterface;
use App\Core\Support\CorrelationId;
use App\Modules\Webhooks\Jobs\DeliverWebhookJob;
use App\Modules\Webhooks\Models\WebhookDelivery;
use App\Modules\Webhooks\Models\WebhookEndpoint;
use App\Modules\Webhooks\Models\WebhookOutbox;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Outbox-Muster: Ereignis und je aktivem, abonnierendem Endpunkt eine Zustellung in derselben Transaktion
 * wie die fachliche Änderung. Die Jobs werden erst nach Commit in die Queue gestellt.
 */
final class WebhookDispatcher implements WebhookDispatcherInterface
{
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly BusDispatcher $bus,
        private readonly CorrelationId $correlationId,
    ) {}

    public function dispatch(string $event, array $payload, int $organizationId): void
    {
        $this->dispatchWithSource($event, $payload, $organizationId);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $source
     */
    public function dispatchWithSource(string $event, array $data, int $organizationId, array $source = [], ?string $entityType = null, ?int $entityId = null): ?WebhookOutbox
    {
        if (! $this->enabled()) {
            return null;
        }

        if (! $this->known($event)) {
            Log::warning('Webhook-Ereignis nicht im Katalog, wird verworfen', ['event' => $event]);

            return null;
        }

        $entityType ??= is_string($data['type'] ?? null) ? $data['type'] : null;
        $entityId ??= is_numeric($data['id'] ?? null) ? (int) $data['id'] : null;

        return DB::transaction(function () use ($event, $data, $organizationId, $source, $entityType, $entityId): WebhookOutbox {
            $eventId = (string) Str::uuid();
            $occurredAt = CarbonImmutable::now();

            $outbox = new WebhookOutbox;
            $outbox->forceFill([
                'event_id' => $eventId,
                'organization_id' => $organizationId,
                'event_type' => $event,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'payload_json' => [
                    'event_id' => $eventId,
                    'event' => $event,
                    'occurred_at' => $occurredAt->toIso8601ZuluString('millisecond'),
                    'organization_id' => $organizationId,
                    'data' => $data,
                    'source' => $source,
                ],
                'correlation_id' => $this->correlationId->current(),
                'occurred_at' => $occurredAt,
            ]);
            $outbox->save();

            $endpoints = WebhookEndpoint::query()->allOrganizations()
                ->where('organization_id', $organizationId)
                ->active()
                ->get();

            $deliveryIds = [];

            foreach ($endpoints as $endpoint) {
                if (! $endpoint instanceof WebhookEndpoint || ! $endpoint->subscribesTo($event)) {
                    continue;
                }

                $delivery = new WebhookDelivery;
                $delivery->forceFill([
                    'endpoint_id' => $endpoint->getKey(),
                    'outbox_id' => $outbox->getKey(),
                    'delivery_uuid' => (string) Str::uuid(),
                    'signature' => '',
                    'attempts' => 0,
                    'status' => WebhookDelivery::STATUS_PENDING,
                    'next_attempt_at' => $occurredAt,
                ]);
                $delivery->save();

                $deliveryIds[] = (int) $delivery->getKey();
            }

            $outbox->forceFill(['dispatched_at' => $occurredAt])->save();

            $queue = (string) $this->config->get('hub.webhooks.queue', 'default');

            DB::afterCommit(function () use ($deliveryIds, $queue): void {
                foreach ($deliveryIds as $id) {
                    $this->bus->dispatch((new DeliverWebhookJob($id))->onQueue($queue));
                }
            });

            return $outbox;
        });
    }

    public function enabled(): bool
    {
        return (bool) $this->config->get('hub.webhooks.enabled', false);
    }

    public function known(string $event): bool
    {
        $catalog = (array) $this->config->get('hub.webhooks.events', []);

        return $catalog === [] || array_key_exists($event, $catalog);
    }
}
