<?php

declare(strict_types=1);

namespace App\Modules\Imports\Services;

/**
 * Normalisiert Spaltenüberschriften und bildet daraus den Header-Fingerprint.
 * Normalform: Kleinschreibung, Umlaute transliteriert, alles außer a-z0-9 wird zu "_", ohne Rand-Unterstriche.
 */
final class HeaderNormalizer
{
    /**
     * @param  array<int, string>  $headers
     * @return array<int, string>
     */
    public static function normalizeAll(array $headers): array
    {
        $normalized = [];
        $seen = [];

        foreach ($headers as $index => $header) {
            $name = self::normalize((string) $header);

            if ($name === '') {
                $name = 'spalte_'.($index + 1);
            }

            if (isset($seen[$name])) {
                $seen[$name]++;
                $name .= '_'.$seen[$name];
            } else {
                $seen[$name] = 1;
            }

            $normalized[] = $name;
        }

        return $normalized;
    }

    public static function normalize(string $header): string
    {
        $value = trim($header);
        $value = ltrim($value, "\u{FEFF}");
        $value = mb_strtolower($value, 'UTF-8');
        $value = str_replace(['ä', 'ö', 'ü', 'ß', 'é', 'è', 'à'], ['ae', 'oe', 'ue', 'ss', 'e', 'e', 'a'], $value);
        $value = (string) preg_replace('/[^a-z0-9]+/', '_', $value);

        return trim($value, '_');
    }

    /**
     * SHA-256 über die normalisierte Headerliste (Reihenfolge ist Teil des Fingerprints).
     *
     * @param  array<int, string>  $normalizedHeaders
     */
    public static function fingerprint(array $normalizedHeaders): string
    {
        return hash('sha256', implode("\x1f", $normalizedHeaders));
    }
}
