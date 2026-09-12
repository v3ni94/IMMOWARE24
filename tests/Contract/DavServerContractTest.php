<?php

declare(strict_types=1);

namespace Tests\Contract;

/**
 * Contract-Tests gegen einen laufenden DAV-Server aus der Umgebung (CONTRACT_DAV_BASE_URL).
 * Ohne diese Variable werden alle Tests übersprungen. Ziel kann der Mock (manuell gestartet)
 * oder in Phase 0 der eigene Immoware24-Mandant mit einem technischen Lesenutzer sein.
 * Es werden ausschließlich lesende Methoden gesendet (OPTIONS, PROPFIND, REPORT).
 *
 * Beispiel Mock:  php artisan hub:mock-immoware:serve
 *                 CONTRACT_DAV_BASE_URL=http://127.0.0.1:8089/dav php artisan test --filter=DavServerContractTest
 */
final class DavServerContractTest extends DavContractTestCase
{
    protected function baseUrl(): ?string
    {
        $url = trim((string) getenv('CONTRACT_DAV_BASE_URL'));

        return $url === '' ? null : $url;
    }

    protected function snapshotName(): string
    {
        $configured = trim((string) getenv('CONTRACT_SNAPSHOT_NAME'));

        if ($configured !== '') {
            return $configured;
        }

        $host = parse_url((string) $this->baseUrl(), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : 'unbekannt';
    }
}
