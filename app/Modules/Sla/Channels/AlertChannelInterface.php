<?php

declare(strict_types=1);

namespace App\Modules\Sla\Channels;

use App\Modules\Security\Models\User;
use App\Modules\Sla\Models\EmergencyAlert;

/**
 * Zustellkanal eines Notfallalarms. Das Ergebnis beschreibt nur die technische Zustellung (sent, failed,
 * not_configured), nie die menschliche Annahme; ein technischer Fehler erscheint nie als Erfolg.
 */
interface AlertChannelInterface
{
    public function name(): string;

    /**
     * @return array{channel: string, status: string, detail: ?string}
     */
    public function send(EmergencyAlert $alert, User $recipient, string $subject, string $text): array;
}
