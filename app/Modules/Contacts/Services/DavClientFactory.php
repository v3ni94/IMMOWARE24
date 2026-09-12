<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Services;

use App\Core\Exceptions\ConnectorException;
use App\Modules\Calendar\Dav\CalDavClient;
use App\Modules\Connector\Models\ImmowareConnection;
use App\Modules\Contacts\Contracts\DavTransportInterface;
use App\Modules\Contacts\Dav\CardDavClient;
use App\Modules\Contacts\Dav\HttpDavTransport;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Baut aus einer ImmowareConnection den lesenden DAV-Transport und den passenden Client.
 * Zugangsdaten stammen aus connection.credentials (encrypted) oder aus dem technischen Nutzer.
 * Offener Punkt: Ersatz des Transports durch HttpClientFactory des Connector-Moduls.
 */
final class DavClientFactory
{
    public function __construct(private readonly ConfigRepository $config) {}

    public function connection(int $connectionId): ImmowareConnection
    {
        $connection = ImmowareConnection::query()->withoutGlobalScope('organization')->find($connectionId);

        if ($connection === null) {
            throw new ConnectorException(sprintf('Connection %d nicht gefunden.', $connectionId));
        }

        return $connection;
    }

    public function carddav(ImmowareConnection $connection): CardDavClient
    {
        return new CardDavClient($this->transport($connection, 'hub.contacts.http'), $this->collectionUrl($connection));
    }

    public function caldav(ImmowareConnection $connection): CalDavClient
    {
        return new CalDavClient($this->transport($connection, 'hub.calendar.http'), $this->collectionUrl($connection));
    }

    public function transport(ImmowareConnection $connection, string $configPrefix): DavTransportInterface
    {
        /** @var array<string, mixed>|null $credentials */
        $credentials = $connection->getAttribute('credentials');
        $username = (string) ($credentials['username'] ?? '');
        $password = (string) ($credentials['password'] ?? '');

        if ($username === '') {
            $technicalUser = $connection->technicalUser;
            $username = (string) ($technicalUser?->getAttribute('username') ?? '');
            $password = (string) ($technicalUser?->getAttribute('secret') ?? '');
        }

        if ($username === '') {
            throw new ConnectorException('Connection ohne Zugangsdaten: technischer Nutzer fehlt.');
        }

        return new HttpDavTransport(
            username: $username,
            password: $password,
            userAgent: 'ImmowareHub/'.(string) $this->config->get('hub.connector.version', '0.1.0'),
            timeoutSeconds: (int) $this->config->get($configPrefix.'.timeout_seconds', 60),
            connectTimeoutSeconds: (int) $this->config->get($configPrefix.'.connect_timeout_seconds', 10),
            authScheme: (string) ($connection->getAttribute('auth_scheme') ?? 'unknown'),
        );
    }

    private function collectionUrl(ImmowareConnection $connection): string
    {
        $url = (string) $connection->getAttribute('base_url');

        if ($url === '') {
            throw new ConnectorException('Connection ohne base_url.');
        }

        return rtrim($url, '/').'/';
    }
}
