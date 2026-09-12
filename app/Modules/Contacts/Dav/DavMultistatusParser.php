<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Dav;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Sicherer Multistatus-Parser für CardDAV und CalDAV (RFC 4918, 6578, 6352, 4791): kein DOCTYPE,
 * keine externen Entitäten, kein Netzwerkzugriff. Eigene Kopie im Modul Contacts, damit die
 * Parallelarbeit am Connector-Modul nicht blockiert; Zusammenführung ist ein offener Punkt.
 */
final class DavMultistatusParser
{
    public const string NS_DAV = 'DAV:';

    public const string NS_CALENDARSERVER = 'http://calendarserver.org/ns/';

    public const string NS_CARDDAV = 'urn:ietf:params:xml:ns:carddav';

    public const string NS_CALDAV = 'urn:ietf:params:xml:ns:caldav';

    /**
     * @return array<int, DavResource>
     */
    public function parse(string $xml): array
    {
        $document = $this->load($xml);

        if ($document === null) {
            return [];
        }

        $xpath = $this->xpath($document);
        $nodes = $xpath->query('//d:response');

        if ($nodes === false) {
            return [];
        }

        $resources = [];

        foreach ($nodes as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $statusLine = $this->text($xpath, 'd:status', $node) ?? $this->text($xpath, 'd:propstat[d:prop/*][1]/d:status', $node) ?? $this->text($xpath, 'd:propstat/d:status', $node);
            $status = $statusLine !== null && preg_match('/\b(\d{3})\b/', $statusLine, $m) === 1 ? (int) $m[1] : null;

            $collection = $xpath->query('d:propstat/d:prop/d:resourcetype/d:collection', $node);
            $reports = [];
            $reportNodes = $xpath->query('d:propstat/d:prop/d:supported-report-set/d:supported-report/d:report/*', $node);

            if ($reportNodes !== false) {
                foreach ($reportNodes as $report) {
                    if ($report instanceof DOMElement) {
                        $reports[] = strtolower($report->localName);
                    }
                }
            }

            $resources[] = new DavResource(
                href: trim($this->text($xpath, 'd:href', $node) ?? ''),
                status: $status,
                isCollection: $collection !== false && $collection->length > 0,
                etag: $this->token($this->text($xpath, 'd:propstat/d:prop/d:getetag', $node)),
                ctag: $this->token($this->text($xpath, 'd:propstat/d:prop/cs:getctag', $node)),
                syncToken: $this->token($this->text($xpath, 'd:propstat/d:prop/d:sync-token', $node)),
                data: $this->text($xpath, 'd:propstat/d:prop/card:address-data', $node) ?? $this->text($xpath, 'd:propstat/d:prop/cal:calendar-data', $node),
                supportedReports: array_values(array_unique($reports)),
            );
        }

        return $resources;
    }

    /**
     * DAV:sync-token auf Wurzelebene eines sync-collection REPORT (RFC 6578).
     */
    public function rootSyncToken(string $xml): ?string
    {
        $document = $this->load($xml);

        if ($document === null) {
            return null;
        }

        return $this->token($this->text($this->xpath($document), '/d:multistatus/d:sync-token', $document->documentElement));
    }

    private function xpath(DOMDocument $document): DOMXPath
    {
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('d', self::NS_DAV);
        $xpath->registerNamespace('cs', self::NS_CALENDARSERVER);
        $xpath->registerNamespace('card', self::NS_CARDDAV);
        $xpath->registerNamespace('cal', self::NS_CALDAV);

        return $xpath;
    }

    private function load(string $xml): ?DOMDocument
    {
        $xml = trim($xml);

        if ($xml === '' || stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            return null;
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_NOBLANKS);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $loaded ? $document : null;
    }

    private function text(DOMXPath $xpath, string $query, ?DOMNode $context): ?string
    {
        if ($context === null) {
            return null;
        }

        $nodes = $xpath->query($query, $context);

        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        return $nodes->item(0)?->textContent;
    }

    private function token(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === null || $value === '' ? null : $value;
    }
}
