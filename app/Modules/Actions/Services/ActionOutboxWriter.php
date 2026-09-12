<?php

declare(strict_types=1);

namespace App\Modules\Actions\Services;

use App\Core\Support\SecretMasker;
use App\Modules\Actions\Models\ActionOutbox;
use Illuminate\Contracts\Config\Repository;

/**
 * Schreibt Outbox-Einträge (mail_outbox) in derselben Transaktion wie die fachliche Änderung. Payload maskiert.
 */
final class ActionOutboxWriter
{
    public function __construct(
        private readonly SecretMasker $masker,
        private readonly Repository $config,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function write(int $organizationId, string $event, string $aggregateType, int $aggregateId, array $payload = []): ActionOutbox
    {
        $entry = new ActionOutbox;
        $entry->forceFill([
            'organization_id' => $organizationId,
            'event' => $event,
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'payload_json' => $this->masker->maskArray($payload),
            'queue' => (string) $this->config->get('hub.actions.jobs.queue', 'mail-high'),
            'status' => 'pending',
            'attempts' => 0,
        ]);
        $entry->save();

        return $entry;
    }
}
