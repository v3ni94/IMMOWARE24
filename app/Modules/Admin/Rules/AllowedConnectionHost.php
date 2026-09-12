<?php

declare(strict_types=1);

namespace App\Modules\Admin\Rules;

use App\Core\Support\UrlGuard;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;

/**
 * Schutz vor SSRF über die Freigabe-URL einer Connection: Probe und Sync senden die Zugangsdaten der
 * Connection an diesen Host. Gleiche Regel wie für Webhook-Ziele (App\Core\Support\UrlGuard): nur https,
 * keine Zugangsdaten in der URL, keine lokalen Hostnamen, keine privaten, Loopback-, Link-Local- oder
 * reservierten Adressen (auch nach DNS-Auflösung). Zusätzlich muss der Host in der Allowlist
 * hub.connector.connections.allowed_hosts liegen, sofern diese gesetzt ist.
 */
final class AllowedConnectionHost implements ValidationRule
{
    public function __construct(private readonly ?UrlGuard $guard = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        $host = parse_url($value, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            $fail('Die Freigabe-URL enthält keinen gültigen Host.');

            return;
        }

        $host = UrlGuard::normalizeHost($host);
        $reason = $this->guard()->reason($value);

        if ($reason !== null) {
            $fail(match ($reason) {
                'scheme_not_https' => 'Die Freigabe-URL muss mit https:// beginnen.',
                'credentials_in_url' => 'Die Freigabe-URL darf keine Zugangsdaten enthalten.',
                'dns_unresolved' => 'Der Host der Freigabe-URL ist nicht auflösbar.',
                default => 'Die Freigabe-URL darf nicht auf lokale oder private Adressen zeigen.',
            });

            return;
        }

        $allowed = (array) config('hub.connector.connections.allowed_hosts', []);

        if ($allowed !== [] && ! Str::is(array_map('strtolower', array_map('strval', $allowed)), $host)) {
            $fail('Der Host der Freigabe-URL ist nicht freigegeben. Zulässig sind: '.implode(', ', array_map('strval', $allowed)).'.');
        }
    }

    public static function isForbiddenHost(string $host): bool
    {
        return UrlGuard::isForbiddenHost($host);
    }

    private function guard(): UrlGuard
    {
        if ($this->guard !== null) {
            return $this->guard;
        }

        $guard = app()->bound(UrlGuard::class) ? app(UrlGuard::class) : null;

        return $guard instanceof UrlGuard ? $guard : new UrlGuard;
    }
}
