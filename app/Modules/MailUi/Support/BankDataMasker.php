<?php

declare(strict_types=1);

namespace App\Modules\MailUi\Support;

/**
 * Maskiert Bankdaten in Alt/Neu-Werten für die Anzeige. Volle IBAN nur mit Recht mail.bank_data.view und nach
 * bewusstem Klick (auditiert). Farbe ist nie der einzige Träger, deshalb Textmarker "(maskiert)".
 */
final class BankDataMasker
{
    private const array SENSITIVE_KEYS = ['iban', 'kontonummer', 'account_number', 'bic', 'mandate', 'mandat'];

    public static function maskIban(string $iban): string
    {
        $clean = strtoupper(preg_replace('/\s+/', '', $iban) ?? '');

        if (mb_strlen($clean) < 8) {
            return str_repeat('*', mb_strlen($clean));
        }

        $masked = mb_substr($clean, 0, 4).str_repeat('*', max(0, mb_strlen($clean) - 6)).mb_substr($clean, -2);

        return trim(chunk_split($masked, 4, ' '));
    }

    public static function isSensitiveKey(string $key): bool
    {
        $lower = mb_strtolower($key);

        foreach (self::SENSITIVE_KEYS as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, string>
     */
    public static function maskArray(array $values, bool $reveal = false): array
    {
        $result = [];

        foreach ($values as $key => $value) {
            $text = is_scalar($value) || $value === null ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE);
            $text = (string) $text;

            if (! $reveal && self::isSensitiveKey((string) $key) && $text !== '') {
                $text = str_contains(mb_strtolower((string) $key), 'iban') ? self::maskIban($text) : mb_substr($text, 0, 2).str_repeat('*', max(0, mb_strlen($text) - 2));
                $text .= ' (maskiert)';
            }

            $result[(string) $key] = $text;
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public static function containsSensitive(array $values): bool
    {
        foreach (array_keys($values) as $key) {
            if (self::isSensitiveKey((string) $key)) {
                return true;
            }
        }

        return false;
    }
}
