<?php

declare(strict_types=1);

namespace App\Modules\Security\Services;

use App\Core\Support\HashedIdentifier;

/**
 * HMAC-SHA256 mit geheimem Pepper für ip_address_hash, iban_hash und base_url_hash (08-security.md 2.2).
 * Fassade des Moduls Security über den Core-Dienst HashedIdentifier (ein Pepper, eine Berechnung für alle Module,
 * Änderungsvermerk 12.09.2026).
 */
final class PepperedHasher
{
    private readonly HashedIdentifier $identifier;

    public function __construct(?string $pepper = null, ?HashedIdentifier $identifier = null)
    {
        $this->identifier = $identifier ?? new HashedIdentifier($pepper);
    }

    public function hash(string $value): string
    {
        return $this->identifier->hash($value);
    }

    public function hashIp(?string $ip): ?string
    {
        return $this->identifier->ip($ip);
    }

    public function hashBaseUrl(?string $baseUrl): ?string
    {
        return $this->identifier->baseUrl($baseUrl);
    }

    public function hashIban(?string $iban): ?string
    {
        return $this->identifier->iban($iban);
    }

    public function equals(string $value, string $knownHash): bool
    {
        return $this->identifier->equals($value, $knownHash);
    }
}
