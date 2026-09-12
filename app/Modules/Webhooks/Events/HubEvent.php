<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Generisches fachliches Ereignis, das andere Module dispatchen können (event()->dispatch(new HubEvent(...))).
 * Der Webhooks-Listener legt es in die Outbox. data enthält nur IDs, Typ, Link und Steuerfelder,
 * keine personenbezogenen Feldwerte und keine Beträge.
 */
final class HubEvent
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $source
     */
    public function __construct(
        public readonly string $event,
        public readonly int $organizationId,
        public readonly array $data = [],
        public readonly array $source = [],
        public readonly ?string $entityType = null,
        public readonly ?int $entityId = null,
    ) {}

    /**
     * Bequemer Konstruktor für Spiegelentitäten: <type>.<action> mit id, type und href.
     */
    public static function forEntity(string $type, string $action, int $id, int $organizationId, string $href, array $source = [], array $extra = []): self
    {
        return new self(
            event: $type.'.'.$action,
            organizationId: $organizationId,
            data: array_merge(['id' => $id, 'type' => $type, 'href' => $href], $extra),
            source: $source,
            entityType: $type,
            entityId: $id,
        );
    }
}
