<?php

declare(strict_types=1);

namespace App\Modules\Documents\Http;

use App\Core\Exceptions\ConnectorException;
use App\Core\Exceptions\WriteBlockedException;
use App\Modules\Connector\Http\HttpClientFactory;
use App\Modules\Connector\Support\ConnectorContext;
use App\Modules\Documents\Support\DavEntry;
use App\Modules\Documents\Support\MultistatusParser;
use App\Modules\Documents\Support\WebDavPath;
use GuzzleHttp\Psr7\Stream;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * WebDAV-Client des Dokumentenspiegels. Einziger Einstiegspunkt für WebDAV-Requests des Moduls Documents.
 * Lesend: OPTIONS, PROPFIND (Depth 0 und 1), GET (Streaming, nur Hash). Schreibend ausschließlich PUT mit
 * If-None-Match: * auf Pfade unterhalb des Schreibpräfixes (create-only). MKCOL, DELETE, MOVE und COPY
 * existieren als Methoden, werfen aber unabhängig von jeder Konfiguration WriteBlockedException.
 * Alle Requests laufen über die HttpClientFactory des Connector-Moduls (Rate Limit, Breaker, Protokoll).
 */
final class WebDavClient
{
    public const string IF_NONE_MATCH_CREATE_ONLY = '*';

    private const int STREAM_CHUNK_BYTES = 65536;

    public function __construct(
        private readonly HttpClientFactory $http,
        private readonly ConnectorContext $context,
        private readonly MultistatusParser $parser,
        private readonly ?string $allowedWritePrefix = null,
    ) {}

    public function context(): ConnectorContext
    {
        return $this->context;
    }

    public function options(string $path = '/'): OptionsResult
    {
        $response = $this->read()->send('OPTIONS', $this->url($path));

        $dav = $response->header('DAV');
        $allow = $response->header('Allow');

        return new OptionsResult(
            status: $response->status(),
            davClasses: $this->splitHeader($dav),
            allow: array_map('strtoupper', $this->splitHeader($allow)),
            server: $response->header('Server') !== '' ? $response->header('Server') : null,
        );
    }

    /**
     * PROPFIND mit XML-Body für getetag, getlastmodified, getcontentlength, getcontenttype, resourcetype, displayname.
     */
    public function propfind(string $path, int $depth = 1): PropfindResult
    {
        $depth = $depth === 0 ? 0 : 1;
        $normalized = WebDavPath::normalize($path);

        $response = $this->read()
            ->withHeaders(['Depth' => (string) $depth])
            ->withBody(MultistatusParser::propfindBody(), 'application/xml; charset=utf-8')
            ->send('PROPFIND', $this->url($normalized));

        if ($response->status() === 207 && ! $this->parser->isMultistatus($response->body())) {
            // Datenintegrität: ein 207 ohne auswertbares Multistatus darf nie als leerer Ordner gelten (sonst Sweep).
            throw new ConnectorException(sprintf('PROPFIND %s: Status 207 ohne gültiges Multistatus-XML.', $normalized));
        }

        $entries = $response->status() === 207 ? $this->parser->parse($response->body(), $this->context->baseUrl) : [];

        return new PropfindResult($response->status(), $normalized, $entries, $response->status() === 207 ? $response->body() : null);
    }

    /**
     * PROPFIND Depth 0. Liefert den Eintrag oder null bei 404. Andere Status werfen nicht, sondern liefern null
     * mit gesetztem Status im Rückgabe-Array.
     *
     * @return array{status: int, entry: DavEntry|null}
     */
    public function head(string $path): array
    {
        $result = $this->propfind($path, 0);

        return ['status' => $result->status, 'entry' => $result->isMultistatus() ? ($result->self() ?? ($result->entries[0] ?? null)) : null];
    }

    /**
     * GET mit Stream: berechnet SHA-256 und Größe, hält den Inhalt nie vollständig im Speicher.
     * Bei gesetztem maxBytes wird der Download abgebrochen, sobald die Grenze überschritten wird (sha256 = null).
     */
    public function download(string $path, ?int $maxBytes = null): DownloadResult
    {
        $response = $this->http->for($this->context, 'read', true, true)
            ->withOptions(['stream' => true])
            ->send('GET', $this->url(WebDavPath::normalize($path, false)));

        if ($response->status() < 200 || $response->status() >= 300) {
            return new DownloadResult($response->status(), null, 0, null, null);
        }

        $body = $response->toPsrResponse()->getBody();

        if ($body->isSeekable()) {
            $body->rewind();
        }

        $hash = hash_init('sha256');
        $size = 0;
        $truncated = false;

        while (! $body->eof()) {
            $chunk = $body->read(self::STREAM_CHUNK_BYTES);

            if ($chunk === '') {
                break;
            }

            $size += strlen($chunk);

            if ($maxBytes !== null && $size > $maxBytes) {
                $truncated = true;

                break;
            }

            hash_update($hash, $chunk);
        }

        $body->close();

        $contentType = $response->header('Content-Type');
        $etag = $response->header('ETag');

        return new DownloadResult(
            status: $response->status(),
            sha256: $truncated ? null : hash_final($hash),
            sizeBytes: $size,
            contentType: $contentType !== '' ? strtolower(trim(explode(';', $contentType)[0])) : null,
            etag: $etag !== '' ? $etag : null,
        );
    }

    /**
     * Einziger Schreibpfad: create-only PUT mit If-None-Match: *. Wirft WriteBlockedException, wenn die
     * Connection nicht purpose = write hat oder der Zielpfad nicht unterhalb des Schreibpräfixes liegt.
     * Netzwerkfehler (Timeout, Reset) werden als Illuminate\Http\Client\ConnectionException weitergereicht.
     *
     * @param  string|resource  $body
     */
    public function putCreateOnly(string $path, mixed $body, string $contentType): Response
    {
        $normalized = WebDavPath::normalize($path, false);

        if ($this->context->purpose !== 'write') {
            throw new WriteBlockedException('PUT ist nur über eine Connection mit purpose = write zulässig.', 'PUT');
        }

        if ($this->allowedWritePrefix === null || trim($this->allowedWritePrefix) === '') {
            throw new WriteBlockedException('Für die Connection ist kein Schreibpräfix (allowed_write_prefix) gesetzt.', 'PUT');
        }

        if (! WebDavPath::isWithin($normalized, $this->allowedWritePrefix)) {
            throw new WriteBlockedException(sprintf('Zielpfad liegt nicht unterhalb des Schreibpräfixes %s.', $this->allowedWritePrefix), 'PUT');
        }

        $request = $this->http->for($this->context, 'write')
            ->withHeaders(['If-None-Match' => self::IF_NONE_MATCH_CREATE_ONLY]);

        if (is_resource($body)) {
            $request = $request->withBody(new Stream($body), $contentType);
        } else {
            $request = $request->withBody((string) $body, $contentType);
        }

        return $request->send('PUT', $this->url($normalized));
    }

    /**
     * Methoden-Guard: MKCOL ist gegenüber Immoware24 hart gesperrt.
     */
    public function mkcol(string $path): never
    {
        throw new WriteBlockedException('MKCOL ist gegenüber Immoware24 hart gesperrt.', 'MKCOL');
    }

    /**
     * Methoden-Guard: DELETE ist hart gesperrt. Ein DELETE-Writeback existiert nicht.
     */
    public function delete(string $path): never
    {
        throw new WriteBlockedException('DELETE ist gegenüber Immoware24 hart gesperrt.', 'DELETE');
    }

    /**
     * Methoden-Guard: MOVE ist hart gesperrt.
     */
    public function move(string $source, string $destination): never
    {
        throw new WriteBlockedException('MOVE ist gegenüber Immoware24 hart gesperrt.', 'MOVE');
    }

    /**
     * Methoden-Guard: COPY ist hart gesperrt.
     */
    public function copy(string $source, string $destination): never
    {
        throw new WriteBlockedException('COPY ist gegenüber Immoware24 hart gesperrt.', 'COPY');
    }

    public function url(string $normalizedPath): string
    {
        return WebDavPath::encode($normalizedPath);
    }

    private function read(): PendingRequest
    {
        return $this->http->for($this->context, 'read');
    }

    /**
     * @return array<int, string>
     */
    private function splitHeader(string $value): array
    {
        if (trim($value) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $v): bool => $v !== ''));
    }
}
