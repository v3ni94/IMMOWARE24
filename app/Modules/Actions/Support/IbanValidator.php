<?php

declare(strict_types=1);

namespace App\Modules\Actions\Support;

/**
 * IBAN-Prüfung nach ISO 13616: Länge je Land, Zeichenformat, Prüfsumme Mod 97. Dazu Maskierung
 * (DE12 **** **** **** **12 34) und Extraktion aus Freitext. Keine Mandats-, Lastschrift- oder Zahlungsfunktion.
 */
final class IbanValidator
{
    /** @var array<string, int> IBAN-Länge je Länderkennung (ISO 13616-Register, gängige Länder) */
    public const array LENGTHS = [
        'AD' => 24, 'AE' => 23, 'AL' => 28, 'AT' => 20, 'AZ' => 28, 'BA' => 20, 'BE' => 16, 'BG' => 22, 'BH' => 22,
        'BR' => 29, 'BY' => 28, 'CH' => 21, 'CR' => 22, 'CY' => 28, 'CZ' => 24, 'DE' => 22, 'DK' => 18, 'DO' => 28,
        'EE' => 20, 'EG' => 29, 'ES' => 24, 'FI' => 18, 'FO' => 18, 'FR' => 27, 'GB' => 22, 'GE' => 22, 'GI' => 23,
        'GL' => 18, 'GR' => 27, 'GT' => 28, 'HR' => 21, 'HU' => 28, 'IE' => 22, 'IL' => 23, 'IS' => 26, 'IT' => 27,
        'JO' => 30, 'KW' => 30, 'KZ' => 20, 'LB' => 28, 'LI' => 21, 'LT' => 20, 'LU' => 20, 'LV' => 21, 'MC' => 27,
        'MD' => 24, 'ME' => 22, 'MK' => 19, 'MR' => 27, 'MT' => 31, 'MU' => 30, 'NL' => 18, 'NO' => 15, 'PK' => 24,
        'PL' => 28, 'PS' => 29, 'PT' => 25, 'QA' => 29, 'RO' => 24, 'RS' => 22, 'SA' => 24, 'SE' => 24, 'SI' => 19,
        'SK' => 24, 'SM' => 27, 'TN' => 24, 'TR' => 26, 'UA' => 29, 'VA' => 22, 'VG' => 24, 'XK' => 20,
    ];

    public function normalize(string $iban): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $iban));
    }

    public function isValid(string $iban): bool
    {
        return $this->validate($iban) === [];
    }

    /**
     * @return array<int, string> Leer bei gültiger IBAN, sonst Fehlergründe (deutsch, ohne Klartext-IBAN)
     */
    public function validate(string $iban): array
    {
        $clean = $this->normalize($iban);
        $errors = [];

        if (preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/', $clean) !== 1) {
            return ['IBAN entspricht nicht dem Format Länderkennung, Prüfziffer, Kontokennung.'];
        }

        $country = substr($clean, 0, 2);
        $expected = self::LENGTHS[$country] ?? null;

        if ($expected === null) {
            $errors[] = sprintf('Länderkennung %s ist nicht im IBAN-Register bekannt.', $country);
        } elseif (strlen($clean) !== $expected) {
            $errors[] = sprintf('IBAN für %s muss %d Stellen haben, hat %d.', $country, $expected, strlen($clean));
        }

        if (! $this->mod97($clean)) {
            $errors[] = 'Prüfsumme (Mod 97) ist ungültig.';
        }

        return $errors;
    }

    /**
     * Maskierung: erste vier und letzte vier Zeichen bleiben lesbar, Vierergruppen. Beispiel DE12 **** **** **** **12 34.
     */
    public function mask(string $iban): string
    {
        $clean = $this->normalize($iban);
        $length = strlen($clean);

        if ($length <= 8) {
            return str_repeat('*', $length);
        }

        $masked = substr($clean, 0, 4).str_repeat('*', $length - 8).substr($clean, -4);

        return trim(chunk_split($masked, 4, ' '));
    }

    /**
     * Extrahiert gültige IBANs aus Freitext (mit oder ohne Leerzeichen), dedupliziert und normalisiert.
     *
     * @return array<int, string>
     */
    public function extract(string $text): array
    {
        preg_match_all('/\b([A-Z]{2}[0-9]{2}(?:[ \t]?[A-Z0-9]){11,40})/u', strtoupper($text), $matches);
        $found = [];

        foreach ($matches[1] as $candidate) {
            $clean = $this->normalize($candidate);
            $length = self::LENGTHS[substr($clean, 0, 2)] ?? null;

            if ($length === null || strlen($clean) < $length) {
                continue;
            }

            // Der Treffer kann in Folgewörter hineinlaufen: auf die Länderlänge kürzen und dann prüfen.
            $clean = substr($clean, 0, $length);

            if ($this->isValid($clean)) {
                $found[$clean] = $clean;
            }
        }

        return array_values($found);
    }

    private function mod97(string $iban): bool
    {
        $rearranged = substr($iban, 4).substr($iban, 0, 4);
        $numeric = '';

        foreach (str_split($rearranged) as $char) {
            $numeric .= ctype_alpha($char) ? (string) (ord($char) - 55) : $char;
        }

        $remainder = 0;

        foreach (str_split($numeric, 7) as $chunk) {
            $remainder = ((int) ($remainder.$chunk)) % 97;
        }

        return $remainder === 1;
    }
}
