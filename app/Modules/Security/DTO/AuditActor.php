<?php

declare(strict_types=1);

namespace App\Modules\Security\DTO;

/**
 * Akteur eines Auditeintrags: user, api_key oder system.
 */
final readonly class AuditActor
{
    public const string TYPE_USER = 'user';

    public const string TYPE_API_KEY = 'api_key';

    public const string TYPE_SYSTEM = 'system';

    public function __construct(
        public string $type,
        public ?int $id = null,
        public ?int $organizationId = null,
    ) {}

    public static function system(): self
    {
        return new self(self::TYPE_SYSTEM);
    }

    public static function user(int $id, ?int $organizationId = null): self
    {
        return new self(self::TYPE_USER, $id, $organizationId);
    }

    public static function apiKey(int $id, ?int $organizationId = null): self
    {
        return new self(self::TYPE_API_KEY, $id, $organizationId);
    }
}
