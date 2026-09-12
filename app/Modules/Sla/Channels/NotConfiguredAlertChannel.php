<?php

declare(strict_types=1);

namespace App\Modules\Sla\Channels;

use App\Modules\Security\Models\User;
use App\Modules\Sla\Models\EmergencyAlert;

/**
 * Stub für sms und call: kein Scheinversand, Status immer not_configured.
 */
final class NotConfiguredAlertChannel implements AlertChannelInterface
{
    public function __construct(private readonly string $name) {}

    public function name(): string
    {
        return $this->name;
    }

    public function send(EmergencyAlert $alert, User $recipient, string $subject, string $text): array
    {
        return ['channel' => $this->name, 'status' => 'not_configured', 'detail' => sprintf('Kanal %s ist nicht eingerichtet, kein Versand.', $this->name)];
    }
}
