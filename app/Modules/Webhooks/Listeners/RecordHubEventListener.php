<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Listeners;

use App\Core\Contracts\WebhookDispatcherInterface;
use App\Modules\Webhooks\Events\HubEvent;
use App\Modules\Webhooks\Services\WebhookDispatcher;

/**
 * Nimmt HubEvent (und, falls vorhanden, App\Modules\Sync\Events\EntitySynced) entgegen und schreibt in die Outbox.
 */
final class RecordHubEventListener
{
    public function __construct(private readonly WebhookDispatcherInterface $dispatcher) {}

    public function handle(object $event): void
    {
        if ($event instanceof HubEvent) {
            if ($this->dispatcher instanceof WebhookDispatcher) {
                $this->dispatcher->dispatchWithSource($event->event, $event->data, $event->organizationId, $event->source, $event->entityType, $event->entityId);

                return;
            }

            $this->dispatcher->dispatch($event->event, $event->data, $event->organizationId);

            return;
        }

        // Fremde Event-Klasse (z. B. Sync\Events\EntitySynced): nur verarbeiten, wenn die Felder eindeutig lesbar sind.
        $name = $this->read($event, ['event', 'eventName', 'name']);
        $organizationId = $this->read($event, ['organizationId', 'organization_id']);
        $data = $this->read($event, ['data', 'payload']);

        if (! is_string($name) || ! is_numeric($organizationId)) {
            return;
        }

        $this->dispatcher->dispatch($name, is_array($data) ? $data : [], (int) $organizationId);
    }

    /**
     * @param  array<int, string>  $candidates
     */
    private function read(object $event, array $candidates): mixed
    {
        foreach ($candidates as $property) {
            if (property_exists($event, $property)) {
                return $event->{$property};
            }

            if (method_exists($event, $property)) {
                return $event->{$property}();
            }
        }

        return null;
    }
}
