<?php

declare(strict_types=1);

namespace App\Modules\Connector\Testing;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\ResponseSequence;
use Illuminate\Support\Facades\Http;

/**
 * Wiederverwendbare Http::fake-Antworten eines simulierten DAV-Servers für Tests aller Module.
 *
 * Simulation auf Basis belegter Aussagen, kein Nachbau nicht dokumentierter Immoware24-Interna.
 * Die Bausteine bilden die Protokollmechanik nach RFC 4918, 6352 und 4791 ab; alle Details, die
 * am Mandanten NICHT VERFÜGBAR oder VERMUTET sind (CTag, ETag-Format, 412-Verhalten), sind Annahmen
 * und in Phase 0 gegen die Probe abzugleichen. Fixtures enthalten ausschließlich synthetische Daten.
 *
 * Beispiel:
 *   Http::fake([
 *       'dav.example.test/files/*' => MockDavResponses::multistatus([
 *           MockDavResponses::collection('/files/Posteingang/'),
 *           MockDavResponses::file('/files/Posteingang/a.pdf', 'etag-1', 1234, 'application/pdf'),
 *       ]),
 *       'dav.example.test/limit/*' => MockDavResponses::rateLimited(10),
 *   ]);
 */
final class MockDavResponses
{
    public const string NS_DAV = 'DAV:';

    public const string NS_CALENDARSERVER = 'http://calendarserver.org/ns/';

    public const string NS_CARDDAV = 'urn:ietf:params:xml:ns:carddav';

    public const string NS_CALDAV = 'urn:ietf:params:xml:ns:caldav';

    public const string LAST_MODIFIED = 'Fri, 11 Sep 2026 10:00:00 GMT';

    /** @var array<string, string> */
    public const array XML_HEADERS = ['Content-Type' => 'application/xml; charset=utf-8'];

    /** @var array<string, string> Header, die ein DAV-Server auf OPTIONS liefert */
    public const array DAV_HEADERS = ['DAV' => '1, 3', 'Allow' => 'OPTIONS, GET, HEAD, PROPFIND, REPORT, PUT', 'Server' => 'MockDAV/1.0'];

    // ------------------------------------------------------------ Antworten

    /**
     * 207 Multistatus aus fertigen Response-Fragmenten (siehe collection(), file(), vcardItem(), icalItem()).
     *
     * @param  array<int, string>  $responses
     */
    public static function multistatus(array $responses, int $status = 207): PromiseInterface
    {
        return Http::response(self::multistatusXml($responses), $status, self::XML_HEADERS);
    }

    public static function options(array $headers = []): PromiseInterface
    {
        return Http::response('', 200, [...self::DAV_HEADERS, ...$headers]);
    }

    public static function ok(string $body = '', string $contentType = 'application/octet-stream', array $headers = []): PromiseInterface
    {
        return Http::response($body, 200, ['Content-Type' => $contentType, ...$headers]);
    }

    /** 201 nach create-only PUT. */
    public static function created(?string $etag = null): PromiseInterface
    {
        return Http::response('', 201, $etag !== null ? ['ETag' => self::quote($etag)] : []);
    }

    public static function unauthorized(string $scheme = 'Basic', string $realm = 'Immoware24 DAV (Mock)'): PromiseInterface
    {
        return Http::response(self::errorXml('Authentifizierung erforderlich.'), 401, [...self::XML_HEADERS, 'WWW-Authenticate' => sprintf('%s realm="%s"', $scheme, $realm)]);
    }

    public static function forbidden(string $message = 'Freigabe fehlt.'): PromiseInterface
    {
        return Http::response(self::errorXml($message), 403, self::XML_HEADERS);
    }

    public static function notFound(string $message = 'Ressource nicht gefunden.'): PromiseInterface
    {
        return Http::response(self::errorXml($message), 404, self::XML_HEADERS);
    }

    public static function methodNotAllowed(): PromiseInterface
    {
        return Http::response(self::errorXml('Methode nicht erlaubt.'), 405, self::XML_HEADERS);
    }

    /** 409 bei PUT in einen nicht existierenden Zielordner. */
    public static function conflict(): PromiseInterface
    {
        return Http::response(self::errorXml('Zielordner existiert nicht.'), 409, self::XML_HEADERS);
    }

    /** 412 bei PUT mit If-None-Match: * auf eine existierende Ressource (Annahme, am Mandanten NICHT VERFÜGBAR). */
    public static function preconditionFailed(): PromiseInterface
    {
        return Http::response(self::errorXml('Ressource existiert bereits.'), 412, self::XML_HEADERS);
    }

    public static function rateLimited(?int $retryAfterSeconds = 10): PromiseInterface
    {
        $headers = self::XML_HEADERS;

        if ($retryAfterSeconds !== null) {
            $headers['Retry-After'] = (string) $retryAfterSeconds;
        }

        return Http::response(self::errorXml('Zu viele Anfragen.'), 429, $headers);
    }

    public static function serverError(int $status = 500): PromiseInterface
    {
        return Http::response(self::errorXml('Interner Fehler.'), max(500, $status), self::XML_HEADERS);
    }

    public static function serviceUnavailable(?int $retryAfterSeconds = 60): PromiseInterface
    {
        $headers = self::XML_HEADERS;

        if ($retryAfterSeconds !== null) {
            $headers['Retry-After'] = (string) $retryAfterSeconds;
        }

        return Http::response(self::errorXml('Wartung.'), 503, $headers);
    }

    /** 207 mit unvollständigem XML: Parser müssen sauber mit ConnectorException reagieren. */
    public static function invalidXml(): PromiseInterface
    {
        return Http::response('<D:multistatus xmlns:D="DAV:"><D:response><D:href>/kaputt', 207, self::XML_HEADERS);
    }

    /** 207 mit leerem Multistatus (keine Einträge). */
    public static function emptyMultistatus(): PromiseInterface
    {
        return self::multistatus([]);
    }

    /**
     * Antworten in fester Reihenfolge, z. B. fünfmal 500 und dann 207 (Breaker-Tests).
     *
     * @param  array<int, PromiseInterface>  $responses
     */
    public static function sequence(array $responses): ResponseSequence
    {
        $sequence = Http::sequence();

        foreach ($responses as $response) {
            $sequence->pushResponse($response);
        }

        return $sequence;
    }

    /** Verbindungsfehler (Timeout, Reset), wie ihn Guzzle liefert. */
    public static function connectionTimeout(): \Closure
    {
        return static function (): never {
            throw new ConnectionException('cURL error 28: Operation timed out (Mock).');
        };
    }

    // ---------------------------------------------- Multistatus-Fragmente

    /**
     * Ordner (D:collection). Optional mit getctag und supported-report-set.
     *
     * @param  array<int, string>  $reports  lokale Report-Namen, z. B. addressbook-multiget, sync-collection
     */
    public static function collection(string $href, ?string $displayname = null, ?string $ctag = null, array $reports = [], ?string $syncToken = null, string $type = ''): string
    {
        $props = '<D:resourcetype><D:collection/>'.$type.'</D:resourcetype>';

        if ($displayname !== null) {
            $props .= '<D:displayname>'.self::xml($displayname).'</D:displayname>';
        }

        $props .= '<D:getlastmodified>'.self::LAST_MODIFIED.'</D:getlastmodified>';

        if ($ctag !== null) {
            $props .= '<CS:getctag>'.self::xml($ctag).'</CS:getctag>';
        }

        if ($syncToken !== null) {
            $props .= '<D:sync-token>'.self::xml($syncToken).'</D:sync-token>';
        }

        if ($reports !== []) {
            $props .= '<D:supported-report-set>';

            foreach ($reports as $report) {
                $prefix = match (true) {
                    str_starts_with($report, 'addressbook') => 'card:',
                    str_starts_with($report, 'calendar') => 'cal:',
                    default => 'D:',
                };
                $props .= '<D:supported-report><D:report><'.$prefix.$report.'/></D:report></D:supported-report>';
            }

            $props .= '</D:supported-report-set>';
        }

        return self::response(rtrim($href, '/').'/', $props);
    }

    public static function addressbook(string $href, ?string $ctag = 'ctag-1', array $reports = ['addressbook-query', 'addressbook-multiget'], ?string $syncToken = null): string
    {
        return self::collection($href, 'Kontakte', $ctag, $reports, $syncToken, '<card:addressbook/>');
    }

    public static function calendar(string $href, ?string $ctag = 'ctag-1', array $reports = ['calendar-query', 'calendar-multiget'], ?string $syncToken = null): string
    {
        return self::collection($href, 'Termine', $ctag, $reports, $syncToken, '<cal:calendar/>');
    }

    /** Datei mit getetag, getlastmodified, getcontentlength, getcontenttype, displayname. */
    public static function file(string $href, string $etag, int $length = 100, string $contentType = 'application/pdf', ?string $lastModified = null, ?string $displayname = null): string
    {
        $props = '<D:resourcetype/>'
            .'<D:getetag>'.self::xml(self::quote($etag)).'</D:getetag>'
            .'<D:getlastmodified>'.self::xml($lastModified ?? self::LAST_MODIFIED).'</D:getlastmodified>'
            .'<D:getcontentlength>'.$length.'</D:getcontentlength>'
            .'<D:getcontenttype>'.self::xml($contentType).'</D:getcontenttype>'
            .'<D:displayname>'.self::xml($displayname ?? basename($href)).'</D:displayname>';

        return self::response($href, $props);
    }

    /** vCard-Ressource, optional mit address-data (Multiget). */
    public static function vcardItem(string $href, string $etag, ?string $vcard = null): string
    {
        $props = '<D:resourcetype/><D:getetag>'.self::xml(self::quote($etag)).'</D:getetag><D:getcontenttype>text/vcard; charset=utf-8</D:getcontenttype>';

        if ($vcard !== null) {
            $props .= '<card:address-data>'.self::xml($vcard).'</card:address-data>';
        }

        return self::response($href, $props);
    }

    /** iCalendar-Ressource, optional mit calendar-data (Multiget). */
    public static function icalItem(string $href, string $etag, ?string $ical = null): string
    {
        $props = '<D:resourcetype/><D:getetag>'.self::xml(self::quote($etag)).'</D:getetag><D:getcontenttype>text/calendar; charset=utf-8</D:getcontenttype>';

        if ($ical !== null) {
            $props .= '<cal:calendar-data>'.self::xml($ical).'</cal:calendar-data>';
        }

        return self::response($href, $props);
    }

    /** Response-Fragment mit Status 404 (Multiget auf entfernte Ressource, sync-collection gelöscht). */
    public static function missing(string $href): string
    {
        return '<D:response><D:href>'.self::xml($href).'</D:href><D:status>HTTP/1.1 404 Not Found</D:status></D:response>';
    }

    /**
     * Multistatus-XML als String (für Tests, die den Body direkt brauchen).
     *
     * @param  array<int, string>  $responses
     */
    public static function multistatusXml(array $responses, ?string $syncToken = null): string
    {
        return '<?xml version="1.0" encoding="utf-8"?>'
            .'<D:multistatus xmlns:D="'.self::NS_DAV.'" xmlns:CS="'.self::NS_CALENDARSERVER.'" xmlns:card="'.self::NS_CARDDAV.'" xmlns:cal="'.self::NS_CALDAV.'">'
            .implode('', $responses)
            .($syncToken !== null ? '<D:sync-token>'.self::xml($syncToken).'</D:sync-token>' : '')
            .'</D:multistatus>';
    }

    // -------------------------------------------------------- Nutzdaten

    /**
     * Synthetische vCard 3.0.
     *
     * @param  array<string, string>  $extra  weitere Zeilen, z. B. ['ORG' => 'Firma', 'CATEGORIES' => 'Mieter']
     */
    public static function vcard(string $uid, string $fn, array $extra = []): string
    {
        $lines = ['BEGIN:VCARD', 'VERSION:3.0', 'UID:'.$uid, 'FN:'.$fn];

        foreach ($extra as $key => $value) {
            $lines[] = $key.':'.$value;
        }

        $lines[] = 'END:VCARD';

        return implode("\r\n", $lines)."\r\n";
    }

    /** Synthetischer iCalendar-Termin (UTC). */
    public static function ical(string $uid, string $summary, string $dtStartUtc = '20260915T080000Z', string $dtEndUtc = '20260915T090000Z'): string
    {
        return implode("\r\n", [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Immoware Hub Mock//DE',
            'BEGIN:VEVENT', 'UID:'.$uid, 'DTSTAMP:20260901T080000Z', 'DTSTART:'.$dtStartUtc, 'DTEND:'.$dtEndUtc, 'SUMMARY:'.$summary, 'END:VEVENT',
            'END:VCALENDAR',
        ])."\r\n";
    }

    public static function errorXml(string $message): string
    {
        return '<?xml version="1.0" encoding="utf-8"?><D:error xmlns:D="DAV:"><D:message>'.self::xml($message).'</D:message></D:error>';
    }

    /** ETag in Anführungszeichen (RFC 7232), sofern nicht bereits gesetzt. */
    public static function quote(string $etag): string
    {
        return str_starts_with($etag, '"') || str_starts_with($etag, 'W/"') ? $etag : '"'.$etag.'"';
    }

    private static function response(string $href, string $props): string
    {
        return '<D:response><D:href>'.self::xml($href).'</D:href><D:propstat><D:prop>'.$props.'</D:prop><D:status>HTTP/1.1 200 OK</D:status></D:propstat></D:response>';
    }

    private static function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
