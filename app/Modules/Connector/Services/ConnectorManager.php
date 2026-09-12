<?php

declare(strict_types=1);

namespace App\Modules\Connector\Services;

use App\Core\Contracts\ImmowareConnectorInterface;
use App\Core\Exceptions\ConnectorException;
use App\Modules\Connector\Enums\ConnectorType;
use App\Modules\Connector\Models\ImmowareConnection;
use App\Modules\Connector\Support\ConnectorContext;
use App\Modules\Connector\Support\ConnectorCredentials;
use Closure;

/**
 * Registriert Adapter per Name (webdav, carddav, caldav, file_import, rest_api_slot) und löst für eine
 * ImmowareConnection den passenden Adapter auf. Zugangsdaten werden entschlüsselt nur im Speicher
 * an den Adapter übergeben (ConnectorContext), nie persistiert oder geloggt.
 */
final class ConnectorManager
{
    /** @var array<string, Closure(ConnectorContext): ImmowareConnectorInterface> */
    private array $factories = [];

    /**
     * @param  Closure(ConnectorContext): ImmowareConnectorInterface  $factory
     */
    public function register(string $name, Closure $factory): void
    {
        $type = ConnectorType::tryFrom(strtolower($name));

        if ($type === null) {
            throw new ConnectorException(sprintf('Adaptername "%s" ist nicht zulässig. Erlaubt: %s.', $name, implode(', ', $this->knownNames())));
        }

        $this->factories[$type->value] = $factory;
    }

    public function isRegistered(string $name): bool
    {
        return isset($this->factories[strtolower($name)]);
    }

    /**
     * @return array<int, string>
     */
    public function registeredNames(): array
    {
        return array_keys($this->factories);
    }

    /**
     * @return array<int, string>
     */
    public function knownNames(): array
    {
        return array_map(static fn (ConnectorType $t): string => $t->value, ConnectorType::cases());
    }

    public function adapterNameFor(ImmowareConnection $connection): ConnectorType
    {
        return ConnectorType::fromConnectorType((string) $connection->getAttribute('connector_type'));
    }

    public function resolve(ImmowareConnection $connection): ImmowareConnectorInterface
    {
        $context = $this->contextFor($connection);

        return $this->make($context->type->value, $context);
    }

    public function make(string $name, ConnectorContext $context): ImmowareConnectorInterface
    {
        $name = strtolower($name);

        if (! isset($this->factories[$name])) {
            throw new ConnectorException(sprintf('Für den Adapter "%s" ist keine Implementierung registriert.', $name));
        }

        return ($this->factories[$name])($context);
    }

    /**
     * Baut den Laufzeitkontext mit entschlüsselten Zugangsdaten. Reihenfolge: credentials der Connection,
     * sonst username und secret des technischen Nutzers.
     */
    public function contextFor(ImmowareConnection $connection): ConnectorContext
    {
        return new ConnectorContext(
            connectionId: (int) $connection->getKey(),
            organizationId: (int) $connection->getAttribute('organization_id'),
            type: $this->adapterNameFor($connection),
            connectorType: (string) $connection->getAttribute('connector_type'),
            baseUrl: $this->nullableString($connection->getAttribute('base_url')),
            credentials: $this->credentialsFor($connection),
            authScheme: (string) ($connection->getAttribute('auth_scheme') ?? 'unknown'),
            purpose: (string) ($connection->getAttribute('purpose') ?? 'read'),
            technicalUserId: $connection->getAttribute('technical_user_id') !== null ? (int) $connection->getAttribute('technical_user_id') : null,
            rateLimitRps: (float) ($connection->getAttribute('rate_limit_rps') ?? 2.0),
            maxConcurrency: (int) ($connection->getAttribute($connection->isWritePurpose() ? 'max_concurrency_write' : 'max_concurrency_read') ?? 2),
            allowedWritePrefix: $this->nullableString($connection->getAttribute('allowed_write_prefix')),
        );
    }

    public function credentialsFor(ImmowareConnection $connection): ConnectorCredentials
    {
        $credentials = $connection->getAttribute('credentials');

        if (is_array($credentials) && (($credentials['username'] ?? '') !== '' || ($credentials['password'] ?? '') !== '')) {
            return new ConnectorCredentials(
                (string) ($credentials['username'] ?? ''),
                (string) ($credentials['password'] ?? $credentials['secret'] ?? ''),
            );
        }

        $technicalUser = $connection->technicalUser;

        if ($technicalUser !== null) {
            return new ConnectorCredentials(
                (string) $technicalUser->getAttribute('username'),
                (string) ($technicalUser->getAttribute('secret') ?? ''),
            );
        }

        return new ConnectorCredentials('', '');
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
