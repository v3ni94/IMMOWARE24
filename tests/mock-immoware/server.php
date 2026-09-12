<?php

declare(strict_types=1);

/*
 * Mock-Server für den Immoware24-DAV-Adapter (Router für den PHP-Built-in-Server).
 *
 *   php -S 127.0.0.1:8089 tests/mock-immoware/server.php
 *   php artisan hub:mock-immoware:serve
 *
 * Simulation auf Basis belegter Aussagen, kein Nachbau nicht dokumentierter Immoware24-Interna.
 * Nachgebildet wird ausschließlich, was in docs/immoware/ als VERIFIZIERT oder DOKUMENTIERT gilt
 * (WebDAV auf Posteingang und Dokumente, CardDAV-Kontaktfreigabe, CalDAV-Kalenderfreigabe) sowie
 * die in RFC 4918, 6352 und 4791 festgelegte Protokollmechanik. Alle Details, die am Mandanten
 * NICHT VERFÜGBAR oder VERMUTET sind (Auth-Schema, ETag-Stabilität, CTag, sync-token, 412 bei
 * If-None-Match, Rate Limits), sind hier Annahmen und im Code als solche markiert. Der Mock ist
 * keine Aussage über das tatsächliche Verhalten des Immoware24-Servers; er wird in Phase 0 gegen
 * die Probe abgeglichen (docs/immoware/10-test-report.md Abschnitt 3).
 *
 * Zugangswege:
 *   /dav/files/...                 WebDAV (PROPFIND, GET, OPTIONS, create-only PUT)
 *   /dav/addressbooks/<buch>/      CardDAV (PROPFIND, REPORT addressbook-query und -multiget, GET)
 *   /dav/calendars/<kalender>/     CalDAV (PROPFIND, REPORT calendar-query und -multiget, GET)
 *   /__mock/health, /__mock/log, /__mock/reset   Steuerung ohne Auth (nur Mock, kein Immoware24)
 *
 * Szenarien über Header X-Mock-Scenario, Query ?scenario= oder Pfadpräfix /s/<szenario>/...:
 *   ok, unauthorized, forbidden, notfound, conflict, ratelimited, servererror, timeout,
 *   invalidxml, slow, etagunstable
 *
 * Umgebung: MOCK_DAV_USER, MOCK_DAV_PASS, MOCK_RUNTIME_DIR (Uploads, Protokoll, Zähler),
 *           MOCK_TIMEOUT_SLEEP (Sekunden, Standard 35), MOCK_SLOW_SLEEP (Sekunden, Standard 2).
 */

final class MockImmowareDavServer
{
    public const array SCENARIOS = ['ok', 'unauthorized', 'forbidden', 'notfound', 'conflict', 'ratelimited', 'servererror', 'timeout', 'invalidxml', 'slow', 'etagunstable'];

    /** Methoden, die der Hub nie senden darf. Der Mock antwortet 403 und protokolliert den Verstoß. */
    private const array FORBIDDEN_METHODS = ['DELETE', 'MOVE', 'COPY', 'PROPPATCH', 'MKCOL', 'LOCK', 'UNLOCK', 'POST', 'PATCH'];

    private const string NS_DAV = 'DAV:';

    private readonly string $fixtures;

    private readonly string $runtime;

    private readonly string $user;

    private readonly string $pass;

    /** Pfadpräfix /s/<szenario>, damit hrefs in Antworten zur angefragten URL passen. */
    private string $hrefPrefix = '';

    public function __construct()
    {
        $this->fixtures = __DIR__.'/fixtures';
        $port = (string) ($_SERVER['SERVER_PORT'] ?? '0');
        $this->runtime = rtrim((string) (getenv('MOCK_RUNTIME_DIR') ?: sys_get_temp_dir().'/mock-immoware-'.$port), '/');
        $this->user = (string) (getenv('MOCK_DAV_USER') ?: 'hub-read');
        $this->pass = (string) (getenv('MOCK_DAV_PASS') ?: 'mock-secret');

        if (! is_dir($this->runtime.'/uploads')) {
            @mkdir($this->runtime.'/uploads', 0777, true);
        }
    }

    public function handle(): void
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $rawPath = (string) (parse_url($uri, PHP_URL_PATH) ?? '/');
        $path = rawurldecode($rawPath);
        $headers = $this->headers();
        $body = (string) file_get_contents('php://input');

        [$scenario, $path] = $this->resolveScenario($path, $headers);

        if (str_starts_with($path, '/__mock/')) {
            $this->control($method, $path);

            return;
        }

        $entry = [
            'time' => gmdate('c'),
            'method' => $method,
            'path' => $path,
            'scenario' => $scenario,
            'depth' => $headers['depth'] ?? null,
            'if_none_match' => $headers['if-none-match'] ?? null,
            'authorized' => $this->authorized($headers),
            'violation' => null,
            'status' => null,
        ];

        try {
            $status = $this->dispatch($method, $path, $headers, $body, $scenario, $entry);
        } catch (Throwable $e) {
            $status = $this->respond(500, 'Mock-Fehler: '.$e->getMessage(), 'text/plain; charset=utf-8');
        }

        $entry['status'] = $status;
        $this->log($entry);
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $entry
     */
    private function dispatch(string $method, string $path, array $headers, string $body, string $scenario, array &$entry): int
    {
        // Verbotene Methoden werden unabhängig vom Szenario und vor der Auth beantwortet, damit jeder Versuch sichtbar wird.
        if (in_array($method, self::FORBIDDEN_METHODS, true)) {
            $entry['violation'] = 'forbidden_method';

            return $this->respond(403, $this->errorXml('Methode '.$method.' ist auf dieser Freigabe nicht erlaubt (Mock).'));
        }

        if ($method === 'PUT' && trim($headers['if-none-match'] ?? '') !== '*') {
            $entry['violation'] = 'put_without_if_none_match';

            return $this->respond(412, $this->errorXml('PUT ohne If-None-Match: * wird abgelehnt (Mock).'));
        }

        // Annahme (VERMUTET): Basic Auth. Auth-Schema am Mandanten in Phase 0 zu verifizieren.
        if ($scenario === 'unauthorized' || ! $this->authorized($headers)) {
            header('WWW-Authenticate: Basic realm="Immoware24 DAV (Mock)"');

            return $this->respond(401, $this->errorXml('Authentifizierung erforderlich.'));
        }

        switch ($scenario) {
            case 'forbidden':
                return $this->respond(403, $this->errorXml('Freigabe fehlt (Szenario forbidden).'));
            case 'notfound':
                return $this->respond(404, $this->errorXml('Ressource nicht gefunden (Szenario notfound).'));
            case 'conflict':
                return $this->respond(409, $this->errorXml('Zielordner existiert nicht (Szenario conflict).'));
            case 'ratelimited':
                header('Retry-After: 10');

                return $this->respond(429, $this->errorXml('Zu viele Anfragen (Szenario ratelimited).'));
            case 'servererror':
                return $this->respond(500, $this->errorXml('Interner Fehler (Szenario servererror).'));
            case 'timeout':
                sleep(max(1, (int) (getenv('MOCK_TIMEOUT_SLEEP') ?: 35)));

                return $this->respond(200, 'zu spät', 'text/plain; charset=utf-8');
            case 'invalidxml':
                return $this->respond(207, '<D:multistatus xmlns:D="DAV:"><D:response><D:href>/kaputt', 'application/xml; charset=utf-8');
            case 'slow':
                sleep(max(1, (int) (getenv('MOCK_SLOW_SLEEP') ?: 2)));
                break;
        }

        if ($method === 'OPTIONS') {
            return $this->options($path);
        }

        if (str_starts_with($path, '/dav/files')) {
            return $this->webdav($method, substr($path, strlen('/dav/files')), $headers, $body, $scenario);
        }

        if (preg_match('#^/dav/(addressbooks|calendars)/([^/]+)/?(.*)$#', $path, $m) === 1) {
            return $this->collectionDav($method, $m[1], $m[2], $m[3], $headers, $body, $scenario);
        }

        if ($method === 'PROPFIND' && in_array(rtrim($path, '/'), ['', '/dav', '/dav/addressbooks', '/dav/calendars'], true)) {
            return $this->respond(207, $this->multistatus([$this->responseXml(rtrim($path, '/').'/', ['collection' => true, 'displayname' => 'Freigaben (Mock)'])]));
        }

        return $this->respond(404, $this->errorXml('Unbekannter Pfad. Freigaben liegen unter /dav/files, /dav/addressbooks, /dav/calendars.'));
    }

    // ---------------------------------------------------------------- WebDAV

    /**
     * @param  array<string, string>  $headers
     */
    private function webdav(string $method, string $relative, array $headers, string $body, string $scenario): int
    {
        $relative = '/'.trim($relative, '/');
        $relative = $relative === '/' ? '/' : $relative;

        if (str_contains($relative, '..')) {
            return $this->respond(400, $this->errorXml('Ungültiger Pfad.'));
        }

        $resolved = $this->resolveFile($relative);

        if ($method === 'PROPFIND') {
            if ($resolved === null) {
                return $this->respond(404, $this->errorXml('Nicht gefunden.'));
            }

            $depth = ($headers['depth'] ?? '1') === '0' ? 0 : 1;
            $responses = [$this->fileResponse('/dav/files'.$relative, $resolved, $scenario)];

            if ($depth === 1 && is_dir($resolved)) {
                foreach ($this->children($relative) as $name => $child) {
                    $href = '/dav/files'.rtrim($relative, '/').'/'.$name.(is_dir($child) ? '/' : '');
                    $responses[] = $this->fileResponse($href, $child, $scenario);
                }
            }

            return $this->respond(207, $this->multistatus($responses));
        }

        if ($method === 'GET' || $method === 'HEAD') {
            if ($resolved === null || is_dir($resolved)) {
                return $this->respond(404, $this->errorXml('Nicht gefunden.'));
            }

            header('ETag: '.$this->etag($resolved, $scenario));
            header('Last-Modified: '.gmdate('D, d M Y H:i:s', (int) filemtime($resolved)).' GMT');

            return $this->respond(200, $method === 'HEAD' ? '' : (string) file_get_contents($resolved), $this->contentType($resolved));
        }

        if ($method === 'PUT') {
            // Annahme (NICHT VERFÜGBAR am Mandanten): Server wertet If-None-Match: * aus und antwortet 412 bei vorhandener Ressource.
            if ($resolved !== null) {
                return $this->respond(412, $this->errorXml('Ressource existiert bereits (If-None-Match: *).'));
            }

            $parent = $this->resolveFile(dirname($relative));

            if ($parent === null || ! is_dir($parent)) {
                return $this->respond(409, $this->errorXml('Zielordner existiert nicht.'));
            }

            $target = $this->runtime.'/uploads'.$relative;
            @mkdir(dirname($target), 0777, true);
            file_put_contents($target, $body);
            header('ETag: '.$this->etag($target, $scenario));

            return $this->respond(201, '');
        }

        return $this->respond(405, $this->errorXml('Methode nicht unterstützt.'));
    }

    private function resolveFile(string $relative): ?string
    {
        foreach ([$this->fixtures.'/files', $this->runtime.'/uploads'] as $root) {
            $candidate = $root.($relative === '/' ? '' : $relative);

            if (file_exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return array<string, string> name => absoluter Pfad (Fixtures und Uploads zusammengeführt)
     */
    private function children(string $relative): array
    {
        $result = [];

        foreach ([$this->fixtures.'/files', $this->runtime.'/uploads'] as $root) {
            $dir = $root.($relative === '/' ? '' : $relative);

            if (! is_dir($dir)) {
                continue;
            }

            foreach (scandir($dir) ?: [] as $name) {
                if ($name === '.' || $name === '..' || $name === '.gitkeep') {
                    continue;
                }

                $result[$name] = $dir.'/'.$name;
            }
        }

        ksort($result);

        return $result;
    }

    private function fileResponse(string $href, string $file, string $scenario): string
    {
        if (is_dir($file)) {
            return $this->responseXml(rtrim($href, '/').'/', ['collection' => true, 'displayname' => basename($file), 'lastmodified' => (int) filemtime($file)]);
        }

        return $this->responseXml($href, [
            'etag' => $this->etag($file, $scenario),
            'lastmodified' => (int) filemtime($file),
            'contentlength' => (int) filesize($file),
            'contenttype' => $this->contentType($file),
            'displayname' => basename($file),
        ]);
    }

    // ------------------------------------------------------- CardDAV, CalDAV

    /**
     * @param  array<string, string>  $headers
     */
    private function collectionDav(string $method, string $kind, string $name, string $rest, array $headers, string $body, string $scenario): int
    {
        $isCard = $kind === 'addressbooks';
        $dir = $this->fixtures.'/'.$kind.'/'.$name;

        if (! is_dir($dir) || str_contains($name, '..')) {
            return $this->respond(404, $this->errorXml('Collection nicht gefunden.'));
        }

        $collectionHref = '/dav/'.$kind.'/'.$name.'/';
        $items = $this->collectionItems($dir, $isCard ? 'vcf' : 'ics');

        if ($rest !== '') {
            $file = $items[$rest] ?? null;

            if ($method === 'GET' && $file !== null) {
                header('ETag: '.$this->etag($file, $scenario));

                return $this->respond(200, (string) file_get_contents($file), $isCard ? 'text/vcard; charset=utf-8' : 'text/calendar; charset=utf-8');
            }

            if ($method === 'PROPFIND' && $file !== null) {
                return $this->respond(207, $this->multistatus([$this->itemResponse($collectionHref.$rest, $file, $scenario, $isCard, false)]));
            }

            if ($method === 'PUT') {
                // CardDAV und CalDAV sind für den Hub ausschließlich lesend (Hard Lock). Der Mock lehnt jedes PUT ab.
                return $this->respond(403, $this->errorXml('Schreibzugriff auf '.$kind.' ist nicht erlaubt (Mock).'));
            }

            return $this->respond(404, $this->errorXml('Ressource nicht gefunden.'));
        }

        if ($method === 'PROPFIND') {
            $depth = ($headers['depth'] ?? '0') === '0' ? 0 : 1;
            $responses = [$this->collectionResponse($collectionHref, $name, $items, $isCard, $scenario)];

            if ($depth === 1) {
                foreach ($items as $itemName => $file) {
                    $responses[] = $this->itemResponse($collectionHref.$itemName, $file, $scenario, $isCard, false);
                }
            }

            return $this->respond(207, $this->multistatus($responses));
        }

        if ($method === 'REPORT') {
            $lower = strtolower($body);
            $withData = str_contains($lower, $isCard ? 'address-data' : 'calendar-data');

            if (str_contains($lower, 'sync-collection')) {
                // sync-collection (RFC 6578) ist am Mandanten NICHT VERFÜGBAR; der Mock unterstützt es nicht.
                return $this->respond(403, $this->errorXml('REPORT sync-collection wird nicht unterstützt (Mock).'));
            }

            if (str_contains($lower, 'multiget')) {
                preg_match_all('#<(?:[a-z0-9]+:)?href>(.*?)</(?:[a-z0-9]+:)?href>#i', $body, $m);
                $responses = [];

                foreach ($m[1] as $hrefRaw) {
                    $href = rawurldecode(html_entity_decode($hrefRaw, ENT_XML1 | ENT_QUOTES, 'UTF-8'));
                    $itemName = basename((string) parse_url($href, PHP_URL_PATH));
                    $file = $items[$itemName] ?? null;

                    $responses[] = $file === null
                        ? $this->notFoundResponse($href)
                        : $this->itemResponse($collectionHref.$itemName, $file, $scenario, $isCard, $withData);
                }

                return $this->respond(207, $this->multistatus($responses));
            }

            if (str_contains($lower, $isCard ? 'addressbook-query' : 'calendar-query')) {
                $responses = [];

                foreach ($items as $itemName => $file) {
                    $responses[] = $this->itemResponse($collectionHref.$itemName, $file, $scenario, $isCard, $withData);
                }

                return $this->respond(207, $this->multistatus($responses));
            }

            return $this->respond(403, $this->errorXml('REPORT-Typ wird nicht unterstützt (Mock).'));
        }

        if ($method === 'GET') {
            return $this->respond(405, $this->errorXml('GET auf eine Collection wird nicht unterstützt.'));
        }

        return $this->respond(405, $this->errorXml('Methode nicht unterstützt.'));
    }

    /**
     * @return array<string, string> Dateiname => absoluter Pfad
     */
    private function collectionItems(string $dir, string $extension): array
    {
        $items = [];

        foreach (scandir($dir) ?: [] as $name) {
            if (str_ends_with(strtolower($name), '.'.$extension)) {
                $items[$name] = $dir.'/'.$name;
            }
        }

        ksort($items);

        return $items;
    }

    /**
     * @param  array<string, string>  $items
     */
    private function collectionResponse(string $href, string $name, array $items, bool $isCard, string $scenario): string
    {
        $etags = [];

        foreach ($items as $file) {
            $etags[] = $this->etag($file, $scenario);
        }

        // Annahme (NICHT VERFÜGBAR am Mandanten): getctag nach calendarserver.org, abgeleitet aus den ETags der Ressourcen.
        $ctag = 'mock-ctag-'.substr(hash('sha256', implode('|', $etags)), 0, 16);

        $type = $isCard ? '<card:addressbook/>' : '<cal:calendar/>';
        $reports = $isCard
            ? '<D:supported-report><D:report><card:addressbook-query/></D:report></D:supported-report><D:supported-report><D:report><card:addressbook-multiget/></D:report></D:supported-report>'
            : '<D:supported-report><D:report><cal:calendar-query/></D:report></D:supported-report><D:supported-report><D:report><cal:calendar-multiget/></D:report></D:supported-report>';

        return '<D:response><D:href>'.$this->xml($href).'</D:href><D:propstat><D:prop>'
            .'<D:resourcetype><D:collection/>'.$type.'</D:resourcetype>'
            .'<D:displayname>'.$this->xml($name).'</D:displayname>'
            .'<CS:getctag>'.$this->xml($ctag).'</CS:getctag>'
            .'<D:supported-report-set>'.$reports.'</D:supported-report-set>'
            .'</D:prop><D:status>HTTP/1.1 200 OK</D:status></D:propstat></D:response>';
    }

    private function itemResponse(string $href, string $file, string $scenario, bool $isCard, bool $withData): string
    {
        $data = '';

        if ($withData) {
            $element = $isCard ? 'card:address-data' : 'cal:calendar-data';
            $data = '<'.$element.'>'.$this->xml((string) file_get_contents($file)).'</'.$element.'>';
        }

        return '<D:response><D:href>'.$this->xml($href).'</D:href><D:propstat><D:prop>'
            .'<D:resourcetype/>'
            .'<D:getetag>'.$this->xml($this->etag($file, $scenario)).'</D:getetag>'
            .'<D:getlastmodified>'.gmdate('D, d M Y H:i:s', (int) filemtime($file)).' GMT</D:getlastmodified>'
            .'<D:getcontentlength>'.(int) filesize($file).'</D:getcontentlength>'
            .'<D:getcontenttype>'.($isCard ? 'text/vcard; charset=utf-8' : 'text/calendar; charset=utf-8').'</D:getcontenttype>'
            .$data
            .'</D:prop><D:status>HTTP/1.1 200 OK</D:status></D:propstat></D:response>';
    }

    private function notFoundResponse(string $href): string
    {
        return '<D:response><D:href>'.$this->xml($href).'</D:href><D:status>HTTP/1.1 404 Not Found</D:status></D:response>';
    }

    // -------------------------------------------------------------- Helfer

    private function options(string $path): int
    {
        // DAV-Klassen sind Annahmen (NICHT VERFÜGBAR am Mandanten); Klasse 2 (LOCK) wird bewusst nicht beworben.
        $dav = '1, 3';
        $allow = 'OPTIONS, GET, HEAD, PROPFIND';

        if (str_starts_with($path, '/dav/addressbooks')) {
            $dav .= ', addressbook';
            $allow .= ', REPORT';
        } elseif (str_starts_with($path, '/dav/calendars')) {
            $dav .= ', calendar-access';
            $allow .= ', REPORT';
        } else {
            $allow .= ', PUT';
        }

        header('DAV: '.$dav);
        header('Allow: '.$allow);
        header('Server: ImmowareMockDAV/1.0');

        return $this->respond(200, '');
    }

    private function control(string $method, string $path): void
    {
        $logFile = $this->logFile();

        switch (rtrim($path, '/')) {
            case '/__mock/health':
                $this->respond(200, json_encode(['ok' => true, 'scenarios' => self::SCENARIOS, 'runtime' => $this->runtime], JSON_THROW_ON_ERROR), 'application/json');

                return;
            case '/__mock/log':
                $lines = is_file($logFile) ? array_filter(explode("\n", (string) file_get_contents($logFile))) : [];
                $this->respond(200, '['.implode(',', $lines).']', 'application/json');

                return;
            case '/__mock/reset':
                if (is_file($logFile)) {
                    unlink($logFile);
                }

                $this->removeTree($this->runtime.'/uploads');
                @mkdir($this->runtime.'/uploads', 0777, true);
                @unlink($this->runtime.'/counter');
                $this->respond(200, json_encode(['reset' => true], JSON_THROW_ON_ERROR), 'application/json');

                return;
        }

        $this->respond(404, json_encode(['error' => 'unbekannter Steuerpfad'], JSON_THROW_ON_ERROR), 'application/json');
    }

    /**
     * @param  array<string, string>  $headers
     * @return array{0: string, 1: string} Szenario und bereinigter Pfad
     */
    private function resolveScenario(string $path, array $headers): array
    {
        $scenario = 'ok';

        if (preg_match('#^/s/([a-z]+)(/.*)?$#', $path, $m) === 1) {
            $scenario = $m[1];
            $path = $m[2] ?? '/';
            $this->hrefPrefix = '/s/'.$m[1];
        }

        $query = [];
        parse_str((string) ($_SERVER['QUERY_STRING'] ?? ''), $query);

        if (isset($query['scenario']) && is_string($query['scenario'])) {
            $scenario = $query['scenario'];
        }

        if (($headers['x-mock-scenario'] ?? '') !== '') {
            $scenario = $headers['x-mock-scenario'];
        }

        $scenario = strtolower(trim($scenario));

        return [in_array($scenario, self::SCENARIOS, true) ? $scenario : 'ok', $path === '' ? '/' : $path];
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function authorized(array $headers): bool
    {
        $auth = $headers['authorization'] ?? '';

        if (stripos($auth, 'basic ') !== 0) {
            return false;
        }

        $decoded = base64_decode(trim(substr($auth, 6)), true);

        if ($decoded === false || ! str_contains($decoded, ':')) {
            return false;
        }

        [$user, $pass] = explode(':', $decoded, 2);

        return hash_equals($this->user, $user) && hash_equals($this->pass, $pass);
    }

    /**
     * @return array<string, string> Header in Kleinschreibung
     */
    private function headers(): array
    {
        $result = [];

        foreach (function_exists('getallheaders') ? (getallheaders() ?: []) : [] as $name => $value) {
            $result[strtolower((string) $name)] = (string) $value;
        }

        foreach ($_SERVER as $key => $value) {
            if (str_starts_with((string) $key, 'HTTP_') && is_string($value)) {
                $result[strtolower(str_replace('_', '-', substr((string) $key, 5)))] = $value;
            }
        }

        return $result;
    }

    private function etag(string $file, string $scenario): string
    {
        $base = substr(hash_file('sha256', $file) ?: '', 0, 20);

        if ($scenario === 'etagunstable') {
            // Simuliert instabile ETags (am Mandanten NICHT VERFÜGBAR): jeder Request liefert einen neuen Wert.
            $base .= '-'.$this->nextCounter();
        }

        return '"'.$base.'"';
    }

    private function nextCounter(): int
    {
        $file = $this->runtime.'/counter';
        $value = is_file($file) ? (int) file_get_contents($file) : 0;
        $value++;
        file_put_contents($file, (string) $value);

        return $value;
    }

    private function contentType(string $file): string
    {
        return match (strtolower(pathinfo($file, PATHINFO_EXTENSION))) {
            'pdf' => 'application/pdf',
            'txt' => 'text/plain; charset=utf-8',
            'vcf' => 'text/vcard; charset=utf-8',
            'ics' => 'text/calendar; charset=utf-8',
            'xml' => 'application/xml',
            'csv' => 'text/csv; charset=utf-8',
            default => 'application/octet-stream',
        };
    }

    /**
     * @param  array<string, mixed>  $props
     */
    private function responseXml(string $href, array $props): string
    {
        $xml = '<D:response><D:href>'.$this->xml($href).'</D:href><D:propstat><D:prop>';
        $xml .= ($props['collection'] ?? false) ? '<D:resourcetype><D:collection/></D:resourcetype>' : '<D:resourcetype/>';

        if (isset($props['etag'])) {
            $xml .= '<D:getetag>'.$this->xml((string) $props['etag']).'</D:getetag>';
        }

        if (isset($props['lastmodified'])) {
            $xml .= '<D:getlastmodified>'.gmdate('D, d M Y H:i:s', (int) $props['lastmodified']).' GMT</D:getlastmodified>';
        }

        if (isset($props['contentlength'])) {
            $xml .= '<D:getcontentlength>'.(int) $props['contentlength'].'</D:getcontentlength>';
        }

        if (isset($props['contenttype'])) {
            $xml .= '<D:getcontenttype>'.$this->xml((string) $props['contenttype']).'</D:getcontenttype>';
        }

        if (isset($props['displayname'])) {
            $xml .= '<D:displayname>'.$this->xml((string) $props['displayname']).'</D:displayname>';
        }

        return $xml.'</D:prop><D:status>HTTP/1.1 200 OK</D:status></D:propstat></D:response>';
    }

    /**
     * @param  array<int, string>  $responses
     */
    private function multistatus(array $responses): string
    {
        $xml = '<?xml version="1.0" encoding="utf-8"?>'
            .'<D:multistatus xmlns:D="'.self::NS_DAV.'" xmlns:CS="http://calendarserver.org/ns/" xmlns:card="urn:ietf:params:xml:ns:carddav" xmlns:cal="urn:ietf:params:xml:ns:caldav">'
            .implode('', $responses)
            .'</D:multistatus>';

        return $this->hrefPrefix === '' ? $xml : str_replace('<D:href>/dav/', '<D:href>'.$this->hrefPrefix.'/dav/', $xml);
    }

    private function errorXml(string $message): string
    {
        return '<?xml version="1.0" encoding="utf-8"?><D:error xmlns:D="DAV:"><D:message>'.$this->xml($message).'</D:message></D:error>';
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function respond(int $status, string $body, string $contentType = 'application/xml; charset=utf-8'): int
    {
        http_response_code($status);
        header('Content-Type: '.$contentType);
        header('X-Mock-Immoware: 1');
        header('Content-Length: '.strlen($body));
        echo $body;

        return $status;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function log(array $entry): void
    {
        file_put_contents($this->logFile(), json_encode($entry, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND | LOCK_EX);
    }

    private function logFile(): string
    {
        return $this->runtime.'/requests.log';
    }

    private function removeTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $path = $dir.'/'.$name;
            is_dir($path) ? $this->removeTree($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}

(new MockImmowareDavServer)->handle();
