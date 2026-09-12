<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Dav;

use App\Core\Exceptions\ConnectorException;
use App\Core\Exceptions\WriteBlockedException;
use App\Modules\Contacts\Contracts\DavTransportInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Lesender DAV-Transport über die Http-Facade. Das Auth-Verfahren kommt aus connection.auth_scheme
 * (Probe-Ergebnis: basic, digest, unknown), analog zu HttpClientFactory; die Annahme "Basic" ist laut
 * 04-authentication.md nur VERMUTET und darf nicht fest im Code stehen. Jede nicht lesende Methode
 * (PUT, DELETE, MOVE, COPY, PROPPATCH, MKCOL, LOCK, UNLOCK, POST, PATCH) wird vor dem Senden
 * mit WriteBlockedException abgewiesen; CardDAV und CalDAV sind ausschließlich lesend.
 */
final class HttpDavTransport implements DavTransportInterface
{
    public const array READ_METHODS = ['PROPFIND', 'REPORT', 'GET', 'OPTIONS', 'HEAD'];

    public function __construct(
        private readonly string $username,
        #[\SensitiveParameter] private readonly string $password,
        private readonly string $userAgent = 'ImmowareHub/0.1.0',
        private readonly int $timeoutSeconds = 60,
        private readonly int $connectTimeoutSeconds = 10,
        private readonly string $authScheme = 'unknown',
    ) {}

    public function request(string $method, string $url, array $headers = [], ?string $body = null): DavHttpResponse
    {
        $method = strtoupper($method);

        if (! in_array($method, self::READ_METHODS, true)) {
            throw new WriteBlockedException(sprintf('DAV-Methode %s ist für CardDAV und CalDAV hart gesperrt.', $method), $method);
        }

        $pending = Http::withUserAgent($this->userAgent)
            ->connectTimeout($this->connectTimeoutSeconds)
            ->timeout($this->timeoutSeconds)
            ->withOptions(['allow_redirects' => false, 'http_errors' => false])
            ->withHeaders($headers);

        // digest laut Probe: Digest-Auth; basic und unknown: Basic-Auth (Standardvermutung, siehe Klassenkommentar).
        $pending = strtolower($this->authScheme) === 'digest'
            ? $pending->withDigestAuth($this->username, $this->password)
            : $pending->withBasicAuth($this->username, $this->password);

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
