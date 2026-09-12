<?php

declare(strict_types=1);

namespace App\Modules\Lexware\Services;

use App\Modules\Lexware\DTO\LexwareCredentials;
use App\Modules\Lexware\Exceptions\LexwareNotConfiguredException;
use App\Modules\Lexware\Models\LexwareConnection;
use Illuminate\Contracts\Config\Repository;

/**
 * Ermittelt den Lexware-Zugang je Gesellschaft: zuerst mail_lexware_connections (API-Key verschlüsselt), sonst
 * MAIL_LEXWARE_API_KEY aus der Umgebung. Ohne Key: "Nicht eingerichtet".
 */
final class LexwareConnectionResolver
{
    public const string STATUS_NOT_CONFIGURED = 'not_configured';

    public const string STATUS_CONFIGURED = 'configured';

    public function __construct(private readonly Repository $config) {}

    public function find(?int $organizationId, ?string $legalEntityCode = null): ?LexwareCredentials
    {
        $query = LexwareConnection::query()->withoutGlobalScopes()->whereNotNull('api_key')->whereNotIn('status', ['revoked']);

        if ($organizationId !== null) {
            $query->where('organization_id', $organizationId);
        }

        if ($legalEntityCode !== null) {
            $query->where('legal_entity_code', $legalEntityCode);
        }

        $connection = $query->orderBy('id')->first();

        if ($connection instanceof LexwareConnection) {
            $apiKey = (string) $connection->getAttribute('api_key');

            if ($apiKey !== '') {
                return new LexwareCredentials(
                    (string) ($connection->getAttribute('base_url') ?: $this->config->get('hub.lexware.base_url')),
                    $apiKey,
                    (int) $connection->getKey(),
                    (int) $connection->getAttribute('organization_id'),
                    (string) $connection->getAttribute('legal_entity_code'),
                    (bool) $connection->getAttribute('write_enabled'),
                );
            }
        }

        $envKey = $this->config->get('hub.lexware.api_key');

        if (is_string($envKey) && trim($envKey) !== '') {
            return new LexwareCredentials((string) $this->config->get('hub.lexware.base_url', 'https://api.lexoffice.io/v1'), trim($envKey), null, $organizationId, $legalEntityCode, false);
        }

        return null;
    }

    /**
     * @throws LexwareNotConfiguredException
     */
    public function require(?int $organizationId, ?string $legalEntityCode = null): LexwareCredentials
    {
        return $this->find($organizationId, $legalEntityCode) ?? throw new LexwareNotConfiguredException('Lexware Office ist nicht eingerichtet (kein API-Key hinterlegt).');
    }

    public function status(?int $organizationId, ?string $legalEntityCode = null): string
    {
        return $this->find($organizationId, $legalEntityCode) === null ? self::STATUS_NOT_CONFIGURED : self::STATUS_CONFIGURED;
    }

    public function statusLabel(?int $organizationId, ?string $legalEntityCode = null): string
    {
        return $this->status($organizationId, $legalEntityCode) === self::STATUS_CONFIGURED ? 'Eingerichtet' : 'Nicht eingerichtet';
    }
}
