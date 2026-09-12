<?php

declare(strict_types=1);

namespace App\Core\Support;

/**
 * Beträge werden als Integer in Cent geführt und deutsch formatiert: 1.234,56 EUR.
 */
final class Money
{
    public static function format(int $cents, string $currency = 'EUR'): string
    {
        $sign = $cents < 0 ? '-' : '';
        $abs = abs($cents);
        $euros = intdiv($abs, 100);
        $rest = $abs % 100;

        return sprintf('%s%s,%02d %s', $sign, number_format($euros, 0, ',', '.'), $rest, $currency);
    }

    /**
     * Wandelt "1.234,56", "1234.56" oder "-12,5" in Cent. Gibt null bei ungültiger Eingabe zurück.
     */
    public static function parseToCents(string $value): ?int
    {
        $value = trim(str_replace(["\u{a0}", ' ', 'EUR', '€'], '', $value));

        if ($value === '') {
            return null;
        }

        if (str_contains($value, ',')) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        }

        if (preg_match('/^-?\d+(\.\d{1,2})?$/', $value) !== 1) {
            return null;
        }

        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '-');
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '0');
        $cents = ((int) $whole) * 100 + (int) str_pad($fraction, 2, '0');

        return $negative ? -$cents : $cents;
    }
}
