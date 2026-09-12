<?php

declare(strict_types=1);

namespace App\Core\Enums;

enum Role: string
{
    case Owner = 'owner';
    case Administrator = 'administrator';
    case Developer = 'developer';
    case Operator = 'operator';
    case ReadOnly = 'read_only';
    case ApiClient = 'api_client';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Geschäftsführung (Owner)',
            self::Administrator => 'Administrator',
            self::Developer => 'Entwickler',
            self::Operator => 'Operator',
            self::ReadOnly => 'Nur Lesen',
            self::ApiClient => 'API-Client',
        };
    }

    /**
     * Rollen, die sich an der Admin-UI anmelden dürfen.
     */
    public function canLogin(): bool
    {
        return $this !== self::ApiClient;
    }

    /**
     * Zweite Person im Vier-Augen-Prinzip für den Schreibpfad.
     */
    public function canConfirmWriteEnable(): bool
    {
        return $this === self::Owner;
    }

    /**
     * Erste Person im Vier-Augen-Prinzip für den Schreibpfad.
     */
    public function canRequestWriteEnable(): bool
    {
        return $this === self::Administrator;
    }
}
