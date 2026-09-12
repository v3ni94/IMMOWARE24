<?php

declare(strict_types=1);

namespace App\Core\Contracts;

interface WebhookDispatcherInterface
{
    /**
     * Legt ein Ereignis in der Outbox ab (Outbox-Muster, gleiche Transaktion wie die fachliche Änderung).
     *
     * @param  array<string, mixed>  $payload
     */
    public function dispatch(string $event, array $payload, int $organizationId): void;
}
