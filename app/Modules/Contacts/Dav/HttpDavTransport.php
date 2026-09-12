<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Dav;

use App\Core\Exceptions\ConnectorException;
use App\Core\Exceptions\WriteBlockedException;
use App\Modules\Connector\Http\HttpClientFactory;
use App\Modules\Connector\Support\ConnectorContext;
use App\Modules\Contacts\Contracts\DavTransportInterface;
use Illuminate\Http\Client\ConnectionException;

/**
 * Lesender DAV-Transport für CardDAV und CalDAV über die HttpClientFactory des Connector-Moduls
 * (Änderungsvermerk 12.09.2026): Rate Limit, Circuit Breaker, remote_requests-Protokoll, Timeouts, User-Agent
 * und Auth-Verfahren (connection.auth_scheme laut Probe: basic, digest, unknown) kommen ausschließlich von dort.
 * Jede nicht lesende Methode wird vor dem Senden mit WriteBlockedException abgewiesen; zusätzlich sperrt der
 * Methoden-Guard der Factory für CardDAV- und CalDAV-Kontexte alles außer OPTIONS, PROPFIND, REPORT, GET, HEAD.
 */
final class HttpDavTransport implements DavTransportInterface
{
    public const array READ_METHODS = HttpClientFactory::DAV_READ_METHODS;

    public function __construct(
        private readonly HttpClientFactory $http,
        private readonly ConnectorContext $context,
    ) {}

    public function request(string $method, string $url, array $headers = [], ?string $body = null): DavHttpResponse
    {
        $method = strtoupper($method);

        if (! in_array($method, self::READ_METHODS, true)) {
            throw new WriteBlockedException(sprintf('DAV-Methode %s ist für CardDAV und CalDAV hart gesperrt.', $method), $method);
        }

        // Absolute URL: die baseUrl des Kontexts darf nicht erneut vorangestellt werden.
        $pending = $this->http->for($this->context, 'read')->baseUrl('')->withHeaders($headers);

        if ($body !== null) {
            $pending = $pending->withBody($body, 'application/xml; charset=utf-8');
        }

        try {
            $response = $pending->send($method, $url);
        } catch (ConnectionException $e) {
            throw new ConnectorException(sprintf('DAV-Anfrage %s fehlgeschlagen: Verbindungsfehler.', $method), 0, $e);
        }

        /** @var array<string, array<int, string>> $responseHeaders */
        $responseHeaders = $response->headers();

        return new DavHttpResponse($response->status(), $response->body(), $responseHeaders);
    }
}
