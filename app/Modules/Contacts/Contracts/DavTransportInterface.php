<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Contracts;

use App\Modules\Contacts\Dav\DavHttpResponse;

/**
 * Transport für DAV-Anfragen (PROPFIND, REPORT, GET, OPTIONS, HEAD). Ausschließlich lesende Methoden.
 * Die Implementierung läuft über die HttpClientFactory des Connector-Moduls (Rate Limit, Breaker, remote_requests)
 * und damit über die Http-Facade, sodass Http::fake() in Tests greift.
 */
interface DavTransportInterface
{
    /**
     * @param  array<string, string>  $headers
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null): DavHttpResponse;
}
