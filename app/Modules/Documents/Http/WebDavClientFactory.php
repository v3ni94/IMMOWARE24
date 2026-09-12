<?php

declare(strict_types=1);

namespace App\Modules\Documents\Http;

use App\Modules\Connector\Http\HttpClientFactory;
use App\Modules\Connector\Models\ImmowareConnection;
use App\Modules\Connector\Services\ConnectorManager;
use App\Modules\Connector\Support\ConnectorContext;
use App\Modules\Documents\Support\MultistatusParser;

/**
 * Erzeugt WebDavClients für einen ConnectorContext bzw. eine ImmowareConnection.
 */
final class WebDavClientFactory
{
    public function __construct(
        private readonly HttpClientFactory $http,
        private readonly ConnectorManager $connectors,
        private readonly MultistatusParser $parser,
    ) {}

    public function make(ConnectorContext $context, ?string $allowedWritePrefix = null): WebDavClient
    {
        return new WebDavClient($this->http, $context, $this->parser, $allowedWritePrefix);
    }

    public function forConnection(ImmowareConnection $connection): WebDavClient
    {
        $prefix = $connection->getAttribute('allowed_write_prefix');

        return $this->make(
            $this->connectors->contextFor($connection),
            is_string($prefix) && $prefix !== '' ? $prefix : null,
        );
    }
}
