<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Support;

/**
 * Normalisierung für contact_identifiers gemäß 02-data-model.md:
 * E-Mail: Domain in Kleinschreibung, lokaler Teil unverändert. Telefon: nur Ziffern und führendes Plus.
 */
final class IdentifierNormalizer
{
    public static function email(string $value): ?string
    {
        $value = trim($value);

        if ($value === '' || ! str_contains($value, '@')) {
            return null;
        }

        [$local, $domain] = explode('@', $value, 2);

        $normalized = $local.'@'.mb_strtolower($domain);

        return mb_strlen($normalized) > 254 ? null : $normalized;
    }

    public static function phone(string $value): ?string
    {
        $value = trim($value);
        $plus = str_starts_with($value, '+') ? '+' : '';
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        if ($plus === '' && str_starts_with($digits, '00')) {
            $plus = '+';
            $digits = substr($digits, 2);
        }

        return $digits === '' ? null : $plus.$digits;
    }
}
