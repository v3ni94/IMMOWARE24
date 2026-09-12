<?php

declare(strict_types=1);

namespace App\Modules\Lexware\DTO;

/**
 * Zugang zu Lexware Office je Gesellschaft. API-Key nur im Speicher, nie in Logs, Exceptions oder Views.
 */
final readonly class LexwareCredentials
{
    public function __construct(
        public string $baseUrl,
        #[\SensitiveParameter] public string $apiKey,
        public ?int $connectionId = null,
        public ?int $organizationId = null,
        public ?string $legalEntityCode = null,
        public bool $writeEnabled = false,
    ) {}

    /**
     * Schlüssel für Rate-Limiter und Sperren (ohne Geheimnis).
     */
    public function rateLimitKey(): string
    {
        return 'lexware:'.($this->connectionId !== null ? 'conn:'.$this->connectionId : 'env:'.substr(hash('sha256', $this->apiKey), 0, 12));
    }
}
