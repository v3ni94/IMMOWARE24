<?php

declare(strict_types=1);

namespace Tests\Contract;

use App\Modules\Connector\Support\DavMultistatusParser;
use App\Modules\Connector\Support\DavResponse;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Basis der Contract-Tests: prüft die Struktur eines laufenden DAV-Servers (Mock oder Mandant) und
 * verdichtet sie zu einem JSON-Fingerprint ohne volatile Werte (keine ETag-Werte, Zeitstempel, Inhalte).
 * Der Fingerprint wird unter tests/Contract/snapshots/<name>.json mit dem letzten Lauf verglichen.
 *
 * Konfiguration über Umgebung:
 *   CONTRACT_DAV_BASE_URL          z. B. https://dav.example/dav (ohne Freigabepfad)
 *   CONTRACT_DAV_USER, CONTRACT_DAV_PASS
 *   CONTRACT_DAV_FILES_PATH        Standard /files/Posteingang/ (Ordner mit Dateien)
 *   CONTRACT_DAV_ADDRESSBOOK_PATH  Standard /addressbooks/kontakte/
 *   CONTRACT_DAV_CALENDAR_PATH     Standard /calendars/termine/
 *   CONTRACT_SNAPSHOT_NAME         Standard: Hostname der Basis-URL
 *   CONTRACT_SNAPSHOT_UPDATE=1     Snapshot überschreiben statt vergleichen
 */
abstract class DavContractTestCase extends TestCase
{
    /** @var array<string, array<string, mixed>> Fingerprint je Testklasse (einmal pro Klasse ermittelt) */
    private static array $fingerprints = [];

    abstract protected function baseUrl(): ?string;

    abstract protected function snapshotName(): string;

    protected function username(): string
    {
        return (string) (getenv('CONTRACT_DAV_USER') ?: MockServerProcess::USER);
    }

    protected function password(): string
    {
        return (string) (getenv('CONTRACT_DAV_PASS') ?: MockServerProcess::PASSWORD);
    }

    protected function filesPath(): string
    {
        return (string) (getenv('CONTRACT_DAV_FILES_PATH') ?: '/files/Posteingang/');
    }

    protected function addressbookPath(): string
    {
        return (string) (getenv('CONTRACT_DAV_ADDRESSBOOK_PATH') ?: '/addressbooks/kontakte/');
    }

    protected function calendarPath(): string
    {
        return (string) (getenv('CONTRACT_DAV_CALENDAR_PATH') ?: '/calendars/termine/');
    }

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->baseUrl() === null) {
            $this->markTestSkipped('CONTRACT_DAV_BASE_URL nicht gesetzt, Contract-Tests übersprungen.');
        }
    }

    // ------------------------------------------------------------ Tests

    public function test_options_liefert_dav_header(): void
    {
        $f = $this->fingerprint();

        $this->assertSame(200, $f['options']['status'], 'OPTIONS muss 200 liefern.');
        $this->assertNotEmpty($f['options']['dav_classes'], 'OPTIONS ohne DAV-Header: kein WebDAV-Server.');
        $this->assertContains('1', $f['options']['dav_classes'], 'DAV-Klasse 1 fehlt.');
    }

    public function test_unauthentifizierter_request_liefert_401_mit_www_authenticate(): void
    {
        $f = $this->fingerprint();

        $this->assertSame(401, $f['auth']['status_without_credentials'], 'Ohne Zugangsdaten wird 401 erwartet.');
        $this->assertNotNull($f['auth']['scheme'], 'WWW-Authenticate fehlt oder ist leer.');
        $this->assertContains($f['auth']['scheme'], ['basic', 'digest'], 'Unbekanntes Auth-Schema: '.$f['auth']['scheme']);
    }

    public function test_propfind_depth_0_liefert_multistatus_mit_collection(): void
    {
        $f = $this->fingerprint();

        $this->assertSame(207, $f['files']['propfind_depth0']['status']);
        $this->assertTrue($f['files']['propfind_depth0']['is_multistatus'], 'Body ist kein DAV:multistatus.');
        $this->assertTrue($f['files']['propfind_depth0']['is_collection'], 'Wurzel ist keine Collection.');
    }

    public function test_propfind_depth_1_liefert_erwartete_properties_und_etag_format(): void
    {
        $f = $this->fingerprint();

        $this->assertSame(207, $f['files']['propfind_depth1']['status']);
        $this->assertGreaterThan(0, $f['files']['propfind_depth1']['entries'], 'Freigabe ohne Einträge.');

        foreach (['getetag', 'getlastmodified', 'getcontentlength', 'getcontenttype', 'resourcetype'] as $property) {
            $this->assertContains($property, $f['files']['propfind_depth1']['file_properties'], sprintf('Property %s fehlt an Dateien.', $property));
        }

        $this->assertContains($f['files']['propfind_depth1']['etag_format'], ['strong_quoted', 'weak'], 'ETag-Format nicht RFC-7232-konform: '.$f['files']['propfind_depth1']['etag_format']);
    }

    public function test_adressbuch_meldet_ctag_und_reports(): void
    {
        $f = $this->fingerprint();

        $this->assertSame(207, $f['addressbook']['propfind_depth0']['status']);
        $this->assertTrue($f['addressbook']['propfind_depth0']['ctag_present'], 'Kein CTag am Adressbuch (Strategie fällt auf ETag-Vergleich zurück).');
        $this->assertContains('addressbook-multiget', $f['addressbook']['propfind_depth0']['reports'], 'supported-report-set ohne addressbook-multiget.');
        $this->assertSame(207, $f['addressbook']['report_query_status'], 'REPORT addressbook-query wird nicht unterstützt.');
        $this->assertSame(207, $f['addressbook']['report_multiget_status'], 'REPORT addressbook-multiget wird nicht unterstützt.');
    }

    public function test_kalender_meldet_ctag_und_reports(): void
    {
        $f = $this->fingerprint();

        $this->assertSame(207, $f['calendar']['propfind_depth0']['status']);
        $this->assertTrue($f['calendar']['propfind_depth0']['ctag_present'], 'Kein CTag am Kalender.');
        $this->assertContains('calendar-multiget', $f['calendar']['propfind_depth0']['reports']);
        $this->assertSame(207, $f['calendar']['report_query_status'], 'REPORT calendar-query wird nicht unterstützt.');
    }

    public function test_fingerprint_entspricht_snapshot(): void
    {
        $fingerprint = $this->fingerprint();
        $file = __DIR__.'/snapshots/'.preg_replace('/[^a-z0-9_.-]+/i', '_', $this->snapshotName()).'.json';
        $current = json_encode($fingerprint, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";

        if (! is_file($file) || (string) getenv('CONTRACT_SNAPSHOT_UPDATE') === '1') {
            if (! is_dir(dirname($file))) {
                mkdir(dirname($file), 0777, true);
            }

            file_put_contents($file, $current);
            $this->assertFileExists($file);

            return;
        }

        $previous = (string) file_get_contents($file);

        $this->assertSame(
            $previous,
            $current,
            sprintf('DAV-Fingerprint weicht vom letzten Lauf ab (%s). Verhalten des Servers hat sich geändert; Diff prüfen, Belegstand in docs/immoware/ anpassen und Snapshot mit CONTRACT_SNAPSHOT_UPDATE=1 erneuern.', basename($file)),
        );
    }

    // ------------------------------------------------------ Fingerprint

    /**
     * @return array<string, mixed>
     */
    protected function fingerprint(): array
    {
        $key = static::class;

        if (! isset(self::$fingerprints[$key])) {
            self::$fingerprints[$key] = $this->collectFingerprint();
        }

        return self::$fingerprints[$key];
    }

    /**
     * @return array<string, mixed>
     */
    private function collectFingerprint(): array
    {
        $parser = new DavMultistatusParser;
        $files = $this->filesPath();
        $book = $this->addressbookPath();
        $calendar = $this->calendarPath();

        $options = $this->client()->send('OPTIONS', $files);
        $unauth = $this->client(false)->withHeaders(['Depth' => '0'])->withBody($this->propfindBody(), 'application/xml; charset=utf-8')->send('PROPFIND', $files);

        $depth0 = $this->propfind($files, 0);
        $depth1 = $this->propfind($files, 1);
        $bookDepth0 = $this->propfind($book, 0, true);
        $calDepth0 = $this->propfind($calendar, 0, true);

        $bookQuery = $this->report($book, '<card:addressbook-query xmlns:d="DAV:" xmlns:card="urn:ietf:params:xml:ns:carddav"><d:prop><d:getetag/></d:prop></card:addressbook-query>');
        $bookHrefs = $this->hrefs($parser, $bookQuery, $book);
        $bookMultiget = $this->report($book, '<card:addressbook-multiget xmlns:d="DAV:" xmlns:card="urn:ietf:params:xml:ns:carddav"><d:prop><d:getetag/><card:address-data/></d:prop>'.$this->hrefXml(array_slice($bookHrefs, 0, 1)).'</card:addressbook-multiget>');

        $calQuery = $this->report($calendar, '<cal:calendar-query xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav"><d:prop><d:getetag/></d:prop><cal:filter><cal:comp-filter name="VCALENDAR"><cal:comp-filter name="VEVENT"/></cal:comp-filter></cal:filter></cal:calendar-query>');
        $syncReport = $this->report($book, '<d:sync-collection xmlns:d="DAV:"><d:sync-token/><d:sync-level>1</d:sync-level><d:prop><d:getetag/></d:prop></d:sync-collection>', '0');

        $depth0Responses = $parser->parse($depth0->body());
        $depth1Responses = $parser->parse($depth1->body());
        $bookRoot = $parser->parse($bookDepth0->body())[0] ?? null;
        $calRoot = $parser->parse($calDepth0->body())[0] ?? null;

        return [
            'contract_version' => 1,
            'options' => [
                'status' => $options->status(),
                'dav_classes' => $this->splitHeader($options->header('DAV')),
                'allow' => $this->splitHeader($options->header('Allow'), true),
                'server_header_present' => $options->header('Server') !== '',
            ],
            'auth' => [
                'status_without_credentials' => $unauth->status(),
                'scheme' => $this->scheme($unauth->header('WWW-Authenticate')),
                'realm_present' => stripos($unauth->header('WWW-Authenticate'), 'realm=') !== false,
            ],
            'files' => [
                'propfind_depth0' => [
                    'status' => $depth0->status(),
                    'is_multistatus' => $parser->isMultistatus($depth0->body()),
                    'is_collection' => $depth0Responses !== [] && $depth0Responses[0]->isCollection,
                    'content_type' => $this->mediaType($depth0->header('Content-Type')),
                ],
                'propfind_depth1' => [
                    'status' => $depth1->status(),
                    'entries' => max(0, count($depth1Responses) - 1),
                    'collection_properties' => $this->propertyNames($depth1->body(), true),
                    'file_properties' => $this->propertyNames($depth1->body(), false),
                    'etag_format' => $this->etagFormat($depth1Responses),
                    'lastmodified_format' => $this->lastModifiedFormat($depth1Responses),
                ],
            ],
            'addressbook' => [
                'propfind_depth0' => $this->collectionFacts($bookDepth0, $bookRoot),
                'report_query_status' => $bookQuery->status(),
                'report_multiget_status' => $bookMultiget->status(),
                'multiget_returns_address_data' => str_contains($bookMultiget->body(), 'address-data'),
                'etag_format' => $this->etagFormat($parser->parse($bookQuery->body())),
                'sync_collection_status' => $syncReport->status(),
            ],
            'calendar' => [
                'propfind_depth0' => $this->collectionFacts($calDepth0, $calRoot),
                'report_query_status' => $calQuery->status(),
                'etag_format' => $this->etagFormat($parser->parse($calQuery->body())),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function collectionFacts(Response $response, ?DavResponse $root): array
    {
        return [
            'status' => $response->status(),
            'is_collection' => $root !== null && $root->isCollection,
            'ctag_present' => $root?->ctag !== null,
            'sync_token_present' => $root?->syncToken !== null,
            'reports' => $root !== null ? $this->sorted($root->supportedReports) : [],
            'resourcetypes' => $this->resourceTypes($response->body()),
        ];
    }

    private function client(bool $withAuth = true): PendingRequest
    {
        $request = Http::baseUrl(rtrim((string) $this->baseUrl(), '/'))
            ->withUserAgent('ImmowareHub-Contract/1.0')
            ->connectTimeout(10)
            ->timeout(30)
            ->withOptions(['allow_redirects' => false, 'http_errors' => false]);

        return $withAuth ? $request->withBasicAuth($this->username(), $this->password()) : $request;
    }

    private function propfind(string $path, int $depth, bool $collectionProps = false): Response
    {
        $body = $collectionProps
            ? '<?xml version="1.0" encoding="utf-8"?><d:propfind xmlns:d="DAV:" xmlns:cs="http://calendarserver.org/ns/"><d:prop><d:resourcetype/><d:displayname/><cs:getctag/><d:sync-token/><d:supported-report-set/></d:prop></d:propfind>'
            : $this->propfindBody();

        return $this->client()->withHeaders(['Depth' => (string) $depth])->withBody($body, 'application/xml; charset=utf-8')->send('PROPFIND', $path);
    }

    private function report(string $path, string $body, string $depth = '1'): Response
    {
        return $this->client()->withHeaders(['Depth' => $depth])->withBody('<?xml version="1.0" encoding="utf-8"?>'.$body, 'application/xml; charset=utf-8')->send('REPORT', $path);
    }

    private function propfindBody(): string
    {
        return '<?xml version="1.0" encoding="utf-8"?><D:propfind xmlns:D="DAV:"><D:prop><D:getetag/><D:getlastmodified/><D:getcontentlength/><D:getcontenttype/><D:resourcetype/><D:displayname/></D:prop></D:propfind>';
    }

    /**
     * @return array<int, string>
     */
    private function hrefs(DavMultistatusParser $parser, Response $response, string $collectionPath): array
    {
        $hrefs = [];

        foreach ($parser->parse($response->body()) as $item) {
            if (! $item->isCollection && $item->href !== '' && rtrim($item->href, '/') !== rtrim($collectionPath, '/')) {
                $hrefs[] = $item->href;
            }
        }

        return $hrefs;
    }

    /**
     * @param  array<int, string>  $hrefs
     */
    private function hrefXml(array $hrefs): string
    {
        $xml = '';

        foreach ($hrefs as $href) {
            $xml .= '<d:href>'.htmlspecialchars($href, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</d:href>';
        }

        return $xml;
    }

    /**
     * Lokale Namen aller Properties mit Status 200, getrennt nach Collections und Dateien.
     *
     * @return array<int, string>
     */
    private function propertyNames(string $xml, bool $collections): array
    {
        $document = $this->load($xml);

        if ($document === null) {
            return [];
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('d', 'DAV:');
        $names = [];

        foreach ($xpath->query('//d:response') ?: [] as $response) {
            if (! $response instanceof DOMElement) {
                continue;
            }

            $collectionNodes = $xpath->query('d:propstat/d:prop/d:resourcetype/d:collection', $response);
            $isCollection = $collectionNodes !== false && $collectionNodes->length > 0;

            if ($isCollection !== $collections) {
                continue;
            }

            foreach ($xpath->query('d:propstat[contains(d:status, " 200 ")]/d:prop/*', $response) ?: [] as $prop) {
                if ($prop instanceof DOMElement) {
                    $names[$prop->localName] = true;
                }
            }
        }

        return $this->sorted(array_keys($names));
    }

    /**
     * @return array<int, string>
     */
    private function resourceTypes(string $xml): array
    {
        $document = $this->load($xml);

        if ($document === null) {
            return [];
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('d', 'DAV:');
        $types = [];

        foreach ($xpath->query('//d:response[1]/d:propstat/d:prop/d:resourcetype/*') ?: [] as $type) {
            if ($type instanceof DOMElement) {
                $types[$type->localName] = true;
            }
        }

        return $this->sorted(array_keys($types));
    }

    /**
     * @param  array<int, DavResponse>  $responses
     */
    private function etagFormat(array $responses): string
    {
        $formats = [];

        foreach ($responses as $response) {
            if ($response->isCollection || $response->etag === null) {
                continue;
            }

            $formats[match (true) {
                str_starts_with($response->etag, 'W/"') => 'weak',
                preg_match('/^"[^"]*"$/', $response->etag) === 1 => 'strong_quoted',
                default => 'unquoted',
            }] = true;
        }

        return $formats === [] ? 'none' : implode('+', $this->sorted(array_keys($formats)));
    }

    /**
     * @param  array<int, DavResponse>  $responses
     */
    private function lastModifiedFormat(array $responses): string
    {
        foreach ($responses as $response) {
            if ($response->lastModified === null) {
                continue;
            }

            return preg_match('/^[A-Z][a-z]{2}, \d{2} [A-Z][a-z]{2} \d{4} \d{2}:\d{2}:\d{2} GMT$/', trim($response->lastModified)) === 1 ? 'rfc7231' : 'other';
        }

        return 'none';
    }

    private function scheme(string $challenge): ?string
    {
        $lower = strtolower(trim($challenge));

        return match (true) {
            $lower === '' => null,
            str_starts_with($lower, 'basic') => 'basic',
            str_starts_with($lower, 'digest') => 'digest',
            default => 'other',
        };
    }

    private function mediaType(string $contentType): ?string
    {
        $type = strtolower(trim(explode(';', $contentType)[0]));

        return $type === '' ? null : $type;
    }

    /**
     * @return array<int, string>
     */
    private function splitHeader(string $value, bool $upper = false): array
    {
        $parts = array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $v): bool => $v !== ''));

        return $this->sorted($upper ? array_map('strtoupper', $parts) : $parts);
    }

    /**
     * @param  array<int, string>  $values
     * @return array<int, string>
     */
    private function sorted(array $values): array
    {
        $values = array_values(array_unique($values));
        sort($values);

        return $values;
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
            $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $loaded ? $document : null;
    }
}
