<?php

declare(strict_types=1);

namespace App\Modules\Admin\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;

/**
 * Schutz vor SSRF über die Freigabe-URL einer Connection: Probe und Sync senden die Zugangsdaten der
 * Connection an diesen Host. Zulässig sind nur Hosts der Allowlist hub.connector.connections.allowed_hosts;
 * IP-Literale privater, lokaler und reservierter Bereiche sowie lokale Hostnamen werden immer abgelehnt.
 */
final class AllowedConnectionHost implements ValidationRule
{
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

        $host = strtolower(trim($host, '[]'));

        if (self::isForbiddenHost($host)) {
            $fail('Die Freigabe-URL darf nicht auf lokale oder private Adressen zeigen.');

            return;
        }

        $allowed = (array) config('hub.connector.connections.allowed_hosts', []);

        if ($allowed !== [] && ! Str::is(array_map('strtolower', array_map('strval', $allowed)), $host)) {
            $fail('Der Host der Freigabe-URL ist nicht freigegeben. Zulässig sind: '.implode(', ', array_map('strval', $allowed)).'.');
        }
    }

    public static function isForbiddenHost(string $host): bool
    {
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        // Öffentliche IP-Literale sind zulässig, sofern die Allowlist sie erlaubt; alles andere ist gesperrt.
        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }
}
