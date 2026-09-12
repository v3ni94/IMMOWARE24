<?php

declare(strict_types=1);

namespace App\Modules\Security\Mail;

use App\Modules\Security\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sicherheitshinweis: Anmeldung von einer bisher unbekannten IP-Adresse.
 * Die IP wird bewusst nur maskiert angezeigt, der Hash liegt im Audit.
 */
final class NewLoginLocationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly string $maskedIp,
        public readonly string $userAgent,
        public readonly string $occurredAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Sicherheitshinweis: Anmeldung von neuer IP-Adresse');
    }

    public function content(): Content
    {
        return new Content(text: 'security::mail.new-login-location');
    }

    public static function maskIp(string $ip): string
    {
        if (str_contains($ip, ':')) {
            $parts = explode(':', $ip);

            return implode(':', array_slice($parts, 0, 3)).':****';
        }

        $parts = explode('.', $ip);

        if (count($parts) === 4) {
            return $parts[0].'.'.$parts[1].'.***.***';
        }

        return '***';
    }
}
