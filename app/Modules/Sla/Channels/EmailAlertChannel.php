<?php

declare(strict_types=1);

namespace App\Modules\Sla\Channels;

use App\Modules\Security\Models\User;
use App\Modules\Sla\Models\EmergencyAlert;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * E-Mail über den Laravel Mailer (kein Gmail-Versand nötig). Fehler werden protokolliert und als failed gemeldet.
 */
final class EmailAlertChannel implements AlertChannelInterface
{
    public function __construct(
        private readonly Mailer $mailer,
        private readonly Repository $config,
    ) {}

    public function name(): string
    {
        return 'email';
    }

    public function send(EmergencyAlert $alert, User $recipient, string $subject, string $text): array
    {
        $to = (string) $recipient->getAttribute('email');

        if ($to === '') {
            return ['channel' => 'email', 'status' => 'failed', 'detail' => 'Empfänger ohne E-Mail-Adresse.'];
        }

        $from = (string) $this->config->get('hub.sla.emergency.email_from', '');

        try {
            $this->mailer->raw($text, static function (Message $message) use ($to, $subject, $from): void {
                $message->to($to)->subject($subject);

                if ($from !== '') {
                    $message->from($from);
                }
            });
        } catch (Throwable $e) {
            Log::warning('Notfallalarm per E-Mail fehlgeschlagen.', ['alert_id' => $alert->getKey(), 'error' => $e::class]);

            return ['channel' => 'email', 'status' => 'failed', 'detail' => 'Versand fehlgeschlagen: '.$e::class];
        }

        return ['channel' => 'email', 'status' => 'sent', 'detail' => 'An '.$this->mask($to).' übergeben (Zustellung nicht bestätigt).'];
    }

    private function mask(string $email): string
    {
        $at = strpos($email, '@');

        return $at === false ? '***' : mb_substr($email, 0, 1).'***'.substr($email, $at);
    }
}
