<?php

declare(strict_types=1);

namespace App\Modules\Connector\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Minimaler, sicherer Parser für DAV-Multistatus-Antworten (RFC 4918, 6578, 6352, 4791) für WebDAV, CardDAV
 * und CalDAV. Keine externen Entitäten, kein Netzwerkzugriff, kein DOCTYPE. Einzige Implementierung im Hub
 * (Änderungsvermerk 12.09.2026: die Kopie im Modul Contacts wurde entfernt).
 */
final class DavMultistatusParser
{
    public const string NS_DAV = 'DAV:';

    public const string NS_CALENDARSERVER = 'http://calendarserver.org/ns/';

    public const string NS_CARDDAV = 'urn:ietf:params:xml:ns:carddav';

    public const string NS_CALDAV = 'urn:ietf:params:xml:ns:caldav';

    /**
     * @return array<int, DavResponse>
     */
    public function parse(string $xml): array
    {
        $document = $this->load($xml);

        if ($document === null) {
            return [];
        }

        $xpath = $this->xpath($document);

        $responses = [];

        $nodes = $xpath->query('//d:response');

        if ($nodes === false) {
            return [];
        }

        foreach ($nodes as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $href = trim($this->text($xpath, 'd:href', $node) ?? '');
            // Status des Eintrags: DAV:status direkt am response (z. B. 404 im sync-collection REPORT), sonst der erste
            // propstat mit Properties, sonst irgendein propstat-Status.
            $statusLine = $this->text($xpath, 'd:status', $node)
                ?? $this->text($xpath, 'd:propstat[d:prop/*][1]/d:status', $node)
                ?? $this->text($xpath, 'd:propstat/d:status', $node);
            $status = $statusLine !== null && preg_match('/\s(\d{3})\s/', ' '.$statusLine.' ', $m) === 1 ? (int) $m[1] : null;

            $resourceType = $xpath->query('d:propstat/d:prop/d:resourcetype/d:collection', $node);
            $isCollection = $resourceType !== false && $resourceType->length > 0;

            $reports = [];
            $reportNodes = $xpath->query('d:propstat/d:prop/d:supported-report-set/d:supported-report/d:report/*', $node);

            if ($reportNodes !== false) {
                foreach ($reportNodes as $report) {
                    if ($report instanceof DOMElement) {
                        $reports[] = strtolower($report->localName);
                    }
                }
            }

            $contentLength = $this->text($xpath, 'd:propstat/d:prop/d:getcontentlength', $node);

            $responses[] = new DavResponse(
                href: $href,
                status: $status,
                isCollection: $isCollection,
                etag: $this->normalizeToken($this->text($xpath, 'd:propstat/d:prop/d:getetag', $node)),
                ctag: $this->normalizeToken($this->text($xpath, 'd:propstat/d:prop/cs:getctag', $node)),
                syncToken: $this->normalizeToken($this->text($xpath, 'd:propstat/d:prop/d:sync-token', $node)),
                lastModified: $this->text($xpath, 'd:propstat/d:prop/d:getlastmodified', $node),
                contentLength: $contentLength !== null && is_numeric(trim($contentLength)) ? (int) trim($contentLength) : null,
                supportedReports: array_values(array_unique($reports)),
                data: $this->text($xpath, 'd:propstat/d:prop/card:address-data', $node) ?? $this->text($xpath, 'd:propstat/d:prop/cal:calendar-data', $node),
            );
        }

        return $responses;
    }

    /**
     * Sync-Token auf Wurzelebene eines sync-collection REPORT (RFC 6578, DAV:multistatus/DAV:sync-token).
     */
    public function rootSyncToken(string $xml): ?string
    {
        $document = $this->load($xml);

        if ($document === null) {
            return null;
        }

        return $this->normalizeToken($this->text($this->xpath($document), '/d:multistatus/d:sync-token', $document->documentElement));
    }

    public function isMultistatus(string $xml): bool
    {
        $document = $this->load($xml);

        return $document !== null
            && $document->documentElement !== null
            && $document->documentElement->namespaceURI === self::NS_DAV
            && $document->documentElement->localName === 'multistatus';
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

    private function normalizeToken(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
