<?php

declare(strict_types=1);

namespace App\Core\Support;

use RuntimeException;

/**
 * HMAC-SHA256 mit geheimem Pepper für Kennungen, die vergleichbar, aber nicht rückrechenbar gespeichert werden
 * (02-data-model.md Abschnitt Hash-Verfahren, 08-security.md 2.2): iban_hash, base_url_hash, ip_address_hash.
 * Modulübergreifender Core-Dienst; die Modelle setzen den Hash in ihren saving-Hooks über diesen Dienst.
 * Pepper aus HUB_HASH_PEPPER; ohne Wert wird der APP_KEY verwendet (nur Entwicklung und Test, in Produktion Exception).
 */
final class HashedIdentifier
{
    public const string PREFIX_IBAN = 'iban:';

    public const string PREFIX_BASE_URL = 'base_url:';

    public const string PREFIX_IP = 'ip:';

    private readonly string $pepper;

    public function __construct(?string $pepper = null)
    {
        $pepper ??= (string) config('hub.security.hashing.pepper', '');

        if ($pepper === '') {
            if ((string) config('app.env') === 'production') {
                // Kein Fallback auf APP_KEY in Produktion: Der Verschlüsselungsschlüssel darf nicht zusätzlich als
                // HMAC-Schlüssel dienen, und ein APP_KEY-Wechsel würde alle Hashes still entwerten.
                throw new RuntimeException('HUB_HASH_PEPPER ist in Produktion Pflicht (kein Fallback auf APP_KEY).');
            }

            $appKey = (string) config('app.key', '');

            if (str_starts_with($appKey, 'base64:')) {
                $appKey = (string) base64_decode(substr($appKey, 7), true);
            }

            $pepper = $appKey;
        }

        if ($pepper === '') {
            throw new RuntimeException('Kein Pepper für HMAC-Hashes konfiguriert (HUB_HASH_PEPPER).');
        }

        $this->pepper = $pepper;
    }

    public function hash(string $value): string
    {
        return hash_hmac('sha256', $value, $this->pepper);
    }

    /**
     * IBAN normalisiert (ohne Leerzeichen, Großbuchstaben) mit Domänenpräfix.
     */
    public function iban(?string $iban): ?string
    {
        if ($iban === null) {
            return null;
        }

        $clean = strtoupper((string) preg_replace('/\s+/', '', $iban));

        return $clean === '' ? null : $this->hash(self::PREFIX_IBAN.$clean);
    }

    /**
     * Basis-URL ohne umschließende Leerzeichen mit Domänenpräfix.
     */
    public function baseUrl(?string $baseUrl): ?string
    {
        if ($baseUrl === null) {
            return null;
        }

        $clean = trim($baseUrl);

        return $clean === '' ? null : $this->hash(self::PREFIX_BASE_URL.$clean);
    }

    public function ip(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }

        return $this->hash(self::PREFIX_IP.$ip);
    }

    public function equals(string $value, string $knownHash): bool
    {
        return hash_equals($knownHash, $this->hash($value));
    }
}
