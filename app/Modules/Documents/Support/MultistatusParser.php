<?php

declare(strict_types=1);

namespace App\Modules\Documents\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Parser für WebDAV-Multistatus-Antworten (RFC 4918) des Dokumentenspiegels. Liest getetag,
 * getlastmodified, getcontentlength, getcontenttype, resourcetype und displayname im Namensraum DAV:.
 * Robust gegen fehlende Properties, mehrere propstat-Blöcke, Umlaute und URL-kodierte hrefs.
 * Keine externen Entitäten, kein Netzwerkzugriff, kein DOCTYPE.
 */
final class MultistatusParser
{
    public const string NS_DAV = 'DAV:';

    /**
     * @return array<int, DavEntry>
     */
    public function parse(string $xml, ?string $baseUrl = null): array
    {
        $document = $this->load($xml);

        if ($document === null) {
            return [];
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('d', self::NS_DAV);

        $nodes = $xpath->query('//d:response');

        if ($nodes === false) {
            return [];
        }

        $entries = [];

        foreach ($nodes as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $href = trim($this->text($xpath, 'd:href', $node) ?? '');

            if ($href === '') {
                continue;
            }

            // Nur der propstat mit Status 200 trägt gültige Werte; 404-propstat listet fehlende Properties.
            $okProp = $this->okPropNode($xpath, $node);
            $status = $this->status($xpath, $node);

            $collectionNodes = $okProp !== null ? $xpath->query('d:resourcetype/d:collection', $okProp) : false;
            $isCollection = $collectionNodes !== false && $collectionNodes->length > 0;

            if (! $isCollection && $okProp === null && str_ends_with($href, '/')) {
                $isCollection = true;
            }

            $contentLength = $okProp !== null ? $this->text($xpath, 'd:getcontentlength', $okProp) : null;

            $entries[] = new DavEntry(
                href: $href,
                path: WebDavPath::fromHref($href, $baseUrl, $isCollection),
                isCollection: $isCollection,
                status: $status,
                etag: $this->token($okProp !== null ? $this->text($xpath, 'd:getetag', $okProp) : null),
                lastModified: $this->token($okProp !== null ? $this->text($xpath, 'd:getlastmodified', $okProp) : null),
                contentLength: $contentLength !== null && is_numeric(trim($contentLength)) ? (int) trim($contentLength) : null,
                contentType: $this->contentType($okProp !== null ? $this->text($xpath, 'd:getcontenttype', $okProp) : null),
                displayName: $this->token($okProp !== null ? $this->text($xpath, 'd:displayname', $okProp) : null),
            );
        }

        return $entries;
    }

    public function isMultistatus(string $xml): bool
    {
        $document = $this->load($xml);

        return $document !== null
            && $document->documentElement !== null
            && $document->documentElement->namespaceURI === self::NS_DAV
            && $document->documentElement->localName === 'multistatus';
    }

    /**
     * XML-Body für PROPFIND mit den Properties des Dokumentenspiegels.
     */
    public static function propfindBody(): string
    {
        return '<?xml version="1.0" encoding="utf-8"?>'
            .'<D:propfind xmlns:D="DAV:"><D:prop>'
            .'<D:getetag/><D:getlastmodified/><D:getcontentlength/><D:getcontenttype/><D:resourcetype/><D:displayname/>'
            .'</D:prop></D:propfind>';
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

    private function okPropNode(DOMXPath $xpath, DOMElement $response): ?DOMElement
    {
        $propstats = $xpath->query('d:propstat', $response);

        if ($propstats === false || $propstats->length === 0) {
            return null;
        }

        $fallback = null;

        foreach ($propstats as $propstat) {
            if (! $propstat instanceof DOMElement) {
                continue;
            }

            $prop = $xpath->query('d:prop', $propstat);
            $propNode = $prop !== false && $prop->length > 0 ? $prop->item(0) : null;

            if (! $propNode instanceof DOMElement) {
                continue;
            }

            $statusLine = $this->text($xpath, 'd:status', $propstat);
            $code = $this->statusCode($statusLine);

            if ($code === null || ($code >= 200 && $code < 300)) {
                return $propNode;
            }

            $fallback ??= $propNode;
        }

        return $fallback;
    }

    private function status(DOMXPath $xpath, DOMElement $response): ?int
    {
        $direct = $this->statusCode($this->text($xpath, 'd:status', $response));

        if ($direct !== null) {
            return $direct;
        }

        $propstats = $xpath->query('d:propstat/d:status', $response);

        if ($propstats === false) {
            return null;
        }

        foreach ($propstats as $statusNode) {
            $code = $this->statusCode($statusNode->textContent);

            if ($code !== null && $code >= 200 && $code < 300) {
                return $code;
            }
        }

        $first = $propstats->item(0);

        return $first !== null ? $this->statusCode($first->textContent) : null;
    }

    private function statusCode(?string $statusLine): ?int
    {
        if ($statusLine === null) {
            return null;
        }

        return preg_match('/\s(\d{3})(?:\s|$)/', ' '.trim($statusLine).' ', $m) === 1 ? (int) $m[1] : null;
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
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function contentType(?string $value): ?string
    {
        $token = $this->token($value);

        if ($token === null) {
            return null;
        }

        // Parameter (charset) abschneiden, Kleinschreibung.
        $type = strtolower(trim(explode(';', $token)[0]));

        return $type === '' ? null : substr($type, 0, 120);
    }
}
