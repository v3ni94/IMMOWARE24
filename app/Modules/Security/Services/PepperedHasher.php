<?php

declare(strict_types=1);

namespace App\Modules\Security\Services;

use RuntimeException;

/**
 * HMAC-SHA256 mit geheimem Pepper für ip_address_hash, iban_hash und base_url_hash (08-security.md 2.2).
 * Pepper aus HUB_HASH_PEPPER; ohne Wert wird der APP_KEY verwendet (nur Entwicklung und Test).
 */
final class PepperedHasher
{
    private readonly string $pepper;

    public function __construct(?string $pepper = null)
    {
        $pepper ??= (string) config('hub.security.hashing.pepper', '');

        if ($pepper === '') {
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

    public function hashIp(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }

        return $this->hash('ip:'.$ip);
    }

    public function equals(string $value, string $knownHash): bool
    {
        return hash_equals($knownHash, $this->hash($value));
    }
}
