<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Contracts;

use App\Modules\Contacts\Dav\DavHttpResponse;

/**
 * Transport für DAV-Anfragen (PROPFIND, REPORT, GET, OPTIONS). Ausschließlich lesende Methoden.
 * Implementierungen laufen über die Http-Facade, damit Http::fake() in Tests greift.
 * Offener Punkt: Anbindung an HttpClientFactory des Connector-Moduls (Rate Limit, Breaker, remote_requests).
 */
interface DavTransportInterface
{
    /**
     * @param  array<string, string>  $headers
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null): DavHttpResponse;
}
