<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Services;

use App\Core\Exceptions\ConnectorException;
use App\Modules\Calendar\Dav\CalDavClient;
use App\Modules\Connector\Http\HttpClientFactory;
use App\Modules\Connector\Models\ImmowareConnection;
use App\Modules\Connector\Services\ConnectorManager;
use App\Modules\Contacts\Contracts\DavTransportInterface;
use App\Modules\Contacts\Dav\CardDavClient;
use App\Modules\Contacts\Dav\HttpDavTransport;

/**
 * Baut aus einer ImmowareConnection den lesenden DAV-Transport und den passenden Client. Zugangsdaten, Auth-Verfahren,
 * Rate Limit und Breaker kommen aus dem ConnectorContext des Connector-Moduls (ConnectorManager::contextFor);
 * der Transport läuft über die HttpClientFactory (Änderungsvermerk 12.09.2026).
 */
final class DavClientFactory
{
    public function __construct(
        private readonly HttpClientFactory $http,
        private readonly ConnectorManager $connectors,
    ) {}

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
        return new CardDavClient($this->transport($connection), $this->collectionUrl($connection));
    }

    public function caldav(ImmowareConnection $connection): CalDavClient
    {
        return new CalDavClient($this->transport($connection), $this->collectionUrl($connection));
    }

    public function transport(ImmowareConnection $connection): DavTransportInterface
    {
        $context = $this->connectors->contextFor($connection);

        if ($context->credentials->isEmpty()) {
            throw new ConnectorException('Connection ohne Zugangsdaten: technischer Nutzer fehlt.');
        }

        return new HttpDavTransport($this->http, $context);
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
