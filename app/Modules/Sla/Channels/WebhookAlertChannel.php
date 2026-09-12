<?php

declare(strict_types=1);

namespace App\Modules\Sla\Channels;

use App\Modules\Security\Models\User;
use App\Modules\Sla\Models\EmergencyAlert;
use App\Modules\Sla\Support\StagingGuard;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Webhook (z. B. Messenger-Bridge) über die Http-Facade. Ohne URL not_configured, Fehler oder Nicht-2xx failed.
 */
final class WebhookAlertChannel implements AlertChannelInterface
{
    public function __construct(
        private readonly Repository $config,
        private readonly ?StagingGuard $staging = null,
    ) {}

    public function name(): string
    {
        return 'webhook';
    }

    public function send(EmergencyAlert $alert, User $recipient, string $subject, string $text): array
    {
        $url = (string) $this->config->get('hub.sla.emergency.webhook_url', '');

        if ($url === '') {
            return ['channel' => 'webhook', 'status' => 'not_configured', 'detail' => 'Keine Webhook-URL konfiguriert.'];
        }

        if ($this->staging?->stagingLike() === true) {
            return ['channel' => 'webhook', 'status' => 'blocked', 'detail' => 'Staging: Webhook-Alarm an externe Systeme gesperrt.'];
        }

        try {
            $response = Http::timeout(10)->acceptJson()->post($url, [
                'type' => 'emergency_alert',
                'alert_id' => $alert->getKey(),
                'case_id' => $alert->case_id,
                'level' => (int) $alert->escalation_level,
                'recipient_user_id' => $recipient->getKey(),
                'subject' => $subject,
                'text' => $text,
            ]);
        } catch (Throwable $e) {
            Log::warning('Notfallalarm per Webhook fehlgeschlagen.', ['alert_id' => $alert->getKey(), 'error' => $e::class]);

            return ['channel' => 'webhook', 'status' => 'failed', 'detail' => 'Transportfehler: '.$e::class];
        }

        if (! $response->successful()) {
            return ['channel' => 'webhook', 'status' => 'failed', 'detail' => 'HTTP '.$response->status()];
        }

        return ['channel' => 'webhook', 'status' => 'sent', 'detail' => 'HTTP '.$response->status().' (Annahme durch Empfänger nicht bestätigt).'];
    }
}
