<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Dav;

use App\Core\Exceptions\ConnectorException;
use App\Modules\Connector\Support\DavMultistatusParser;
use App\Modules\Connector\Support\DavResponse;
use App\Modules\Contacts\Contracts\DavTransportInterface;

/**
 * Gemeinsame lesende DAV-Operationen für CardDAV und CalDAV: PROPFIND Depth 0 auf die Collection,
 * ETag-Liste per REPORT, Multiget in Batches, sync-collection REPORT (RFC 6578).
 */
abstract class AbstractDavClient
{
    public const int MULTIGET_BATCH_SIZE = 50;

    public function __construct(
        protected readonly DavTransportInterface $transport,
        protected readonly string $collectionUrl,
        protected readonly DavMultistatusParser $parser = new DavMultistatusParser,
    ) {}

    abstract protected function namespacePrefix(): string;

    abstract protected function namespaceUri(): string;

    abstract protected function queryReportName(): string;

    abstract protected function multigetReportName(): string;

    abstract protected function dataElementName(): string;

    /**
     * Zusätzlicher Filter im Query-REPORT (CalDAV braucht comp-filter VCALENDAR/VEVENT).
     */
    protected function queryFilterXml(): string
    {
        return '';
    }

    public function collectionUrl(): string
    {
        return $this->collectionUrl;
    }

    public function collectionPath(): string
    {
        $path = parse_url($this->collectionUrl, PHP_URL_PATH);

        return is_string($path) && $path !== '' ? $path : '/';
    }

    public function propfindCollection(): CollectionInfo
    {
        $body = '<?xml version="1.0" encoding="utf-8"?>'
            .'<d:propfind xmlns:d="DAV:" xmlns:cs="http://calendarserver.org/ns/">'
            .'<d:prop><d:resourcetype/><d:displayname/><cs:getctag/><d:sync-token/><d:supported-report-set/></d:prop>'
            .'</d:propfind>';

        $response = $this->transport->request('PROPFIND', $this->collectionUrl, ['Depth' => '0'], $body);
        $this->assertMultistatus($response, 'PROPFIND');

        $resources = $this->parser->parse($response->body);
        $collection = $resources[0] ?? null;

        return new CollectionInfo(
            ctag: $collection?->ctag,
            syncToken: $collection?->syncToken,
            supportedReports: $collection !== null ? $collection->supportedReports : [],
        );
    }

    /**
     * ETag-Liste aller Ressourcen der Collection (REPORT addressbook-query bzw. calendar-query, nur getetag).
     *
     * @return array<string, string|null> href => etag
     */
    public function listEtags(): array
    {
        $p = $this->namespacePrefix();
        $body = '<?xml version="1.0" encoding="utf-8"?>'
            .sprintf('<%s:%s xmlns:d="DAV:" xmlns:%s="%s">', $p, $this->queryReportName(), $p, $this->namespaceUri())
            .'<d:prop><d:getetag/></d:prop>'
            .$this->queryFilterXml()
            .sprintf('</%s:%s>', $p, $this->queryReportName());

        $response = $this->transport->request('REPORT', $this->collectionUrl, ['Depth' => '1'], $body);
        $this->assertMultistatus($response, 'REPORT '.$this->queryReportName());

        $result = [];

        foreach ($this->parser->parse($response->body) as $resource) {
            if ($resource->isCollection || $resource->href === '' || $resource->isNotFound()) {
                continue;
            }

            if ($this->isCollectionHref($resource->href)) {
                continue;
            }

            $result[$resource->href] = $resource->etag;
        }

        return $result;
    }

    /**
     * Lädt die Nutzdaten (vCard bzw. iCalendar) der angegebenen hrefs in Batches von 50.
     *
     * @param  array<int, string>  $hrefs
     * @return array<int, DavResponse>
     */
    public function multiget(array $hrefs): array
    {
        $hrefs = array_values(array_unique(array_filter($hrefs, static fn (string $h): bool => $h !== '')));
        $resources = [];

        foreach (array_chunk($hrefs, self::MULTIGET_BATCH_SIZE) as $batch) {
            $p = $this->namespacePrefix();
            $body = '<?xml version="1.0" encoding="utf-8"?>'
                .sprintf('<%s:%s xmlns:d="DAV:" xmlns:%s="%s">', $p, $this->multigetReportName(), $p, $this->namespaceUri())
                .sprintf('<d:prop><d:getetag/><%s:%s/></d:prop>', $p, $this->dataElementName());

            foreach ($batch as $href) {
                $body .= '<d:href>'.htmlspecialchars($href, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</d:href>';
            }

            $body .= sprintf('</%s:%s>', $p, $this->multigetReportName());

            $response = $this->transport->request('REPORT', $this->collectionUrl, ['Depth' => '1'], $body);
            $this->assertMultistatus($response, 'REPORT '.$this->multigetReportName());

            foreach ($this->parser->parse($response->body) as $resource) {
                if ($resource->href !== '') {
                    $resources[] = $resource;
                }
            }
        }

        return $resources;
    }

    /**
     * sync-collection REPORT (RFC 6578). Liefert geänderte Ressourcen (mit ETag), gelöschte (Status 404)
     * und das neue Token. Bei ungültigem Token (403 oder 410) wird null zurückgegeben.
     *
     * @return array{changed: array<string, string|null>, deleted: array<int, string>, token: string|null}|null
     */
    public function syncCollection(?string $token): ?array
    {
        $body = '<?xml version="1.0" encoding="utf-8"?>'
            .'<d:sync-collection xmlns:d="DAV:">'
            .'<d:sync-token>'.htmlspecialchars((string) $token, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</d:sync-token>'
            .'<d:sync-level>1</d:sync-level>'
            .'<d:prop><d:getetag/></d:prop>'
            .'</d:sync-collection>';

        $response = $this->transport->request('REPORT', $this->collectionUrl, ['Depth' => '0'], $body);

        if ($response->status === 403 || $response->status === 410) {
            return null;
        }

        $this->assertMultistatus($response, 'REPORT sync-collection');

        $changed = [];
        $deleted = [];

        foreach ($this->parser->parse($response->body) as $resource) {
            if ($resource->href === '' || $this->isCollectionHref($resource->href)) {
                continue;
            }

            if ($resource->isNotFound()) {
                $deleted[] = $resource->href;

                continue;
            }

            $changed[$resource->href] = $resource->etag;
        }

        return ['changed' => $changed, 'deleted' => $deleted, 'token' => $this->parser->rootSyncToken($response->body)];
    }

    /**
     * Macht einen href absolut (relativ zur Collection-URL).
     */
    public function absoluteUrl(string $href): string
    {
        if (preg_match('#^https?://#i', $href) === 1) {
            return $href;
        }

        $scheme = parse_url($this->collectionUrl, PHP_URL_SCHEME);
        $host = parse_url($this->collectionUrl, PHP_URL_HOST);
        $port = parse_url($this->collectionUrl, PHP_URL_PORT);
        $origin = sprintf('%s://%s%s', is_string($scheme) ? $scheme : 'https', is_string($host) ? $host : '', is_int($port) ? ':'.$port : '');

        return $origin.(str_starts_with($href, '/') ? $href : rtrim($this->collectionPath(), '/').'/'.$href);
    }

    protected function isCollectionHref(string $href): bool
    {
        $path = parse_url($href, PHP_URL_PATH);
        $path = is_string($path) ? $path : $href;

        return rtrim($path, '/') === rtrim($this->collectionPath(), '/');
    }

    protected function assertMultistatus(DavHttpResponse $response, string $operation): void
    {
        if ($response->status === 401) {
            throw new ConnectorException(sprintf('%s: Authentifizierung abgelehnt (401).', $operation));
        }

        if ($response->status === 403 || $response->status === 404) {
            throw new ConnectorException(sprintf('%s: Collection nicht erreichbar (%d), Freigabe prüfen.', $operation, $response->status));
        }

        if ($response->status === 405 || $response->status === 501) {
            throw new ConnectorException(sprintf('%s: Methode vom Server nicht unterstützt (%d).', $operation, $response->status));
        }

        if ($response->status !== 207) {
            throw new ConnectorException(sprintf('%s: unerwarteter Status %d.', $operation, $response->status));
        }

        if (! $this->parser->isMultistatus($response->body)) {
            // Datenintegrität: ein 207 ohne auswertbares Multistatus darf nie als leere Collection gelten (sonst Sweep).
            throw new ConnectorException(sprintf('%s: Status 207 ohne gültiges Multistatus-XML.', $operation));
        }
    }
}
