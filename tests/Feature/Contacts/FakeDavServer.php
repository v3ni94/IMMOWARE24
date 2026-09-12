<?php

declare(strict_types=1);

namespace Tests\Feature\Contacts;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Simulierter CardDAV- oder CalDAV-Server über Http::fake(). Antwortet auf PROPFIND, addressbook-query
 * bzw. calendar-query, multiget und sync-collection anhand des Request-Bodys.
 */
final class FakeDavServer
{
    /** @var array<string, array{etag: string, data: string}> href => Ressource */
    public array $resources = [];

    public ?string $ctag = 'ctag-1';

    public ?string $syncToken = null;

    public bool $supportsSyncCollection = false;

    /** @var array<int, string> hrefs, die der nächste sync-collection REPORT als 404 meldet */
    public array $syncDeleted = [];

    /** @var array<int, string> hrefs, die der nächste sync-collection REPORT als geändert meldet */
    public array $syncChanged = [];

    public function __construct(public readonly string $host, public readonly string $collectionPath, public readonly string $dataElement = 'address-data') {}

    public function url(): string
    {
        return 'https://'.$this->host.$this->collectionPath;
    }

    public function put(string $href, string $etag, string $data): void
    {
        $this->resources[$href] = ['etag' => $etag, 'data' => $data];
    }

    public function install(): void
    {
        Http::fake([$this->host.'/*' => fn (Request $request): Response|\GuzzleHttp\Promise\PromiseInterface => $this->respond($request)]);
    }

    private function respond(Request $request): PromiseInterface
    {
        $body = $request->body();

        if ($request->method() === 'PROPFIND') {
            return Http::response($this->propfind(), 207, ['Content-Type' => 'application/xml']);
        }

        if ($request->method() === 'REPORT' && str_contains($body, 'sync-collection')) {
            return Http::response($this->syncCollection(), 207, ['Content-Type' => 'application/xml']);
        }

        if ($request->method() === 'REPORT' && str_contains($body, 'multiget')) {
            preg_match_all('#<d:href>(.*?)</d:href>#', $body, $m);

            return Http::response($this->multiget(array_map('html_entity_decode', $m[1])), 207, ['Content-Type' => 'application/xml']);
        }

        if ($request->method() === 'REPORT') {
            return Http::response($this->etagList(), 207, ['Content-Type' => 'application/xml']);
        }

        return Http::response('', 405);
    }

    private function propfind(): string
    {
        $reports = '<d:supported-report><d:report><card:addressbook-multiget/></d:report></d:supported-report>';

        if ($this->supportsSyncCollection) {
            $reports .= '<d:supported-report><d:report><d:sync-collection/></d:report></d:supported-report>';
        }

        $token = $this->syncToken !== null ? '<d:sync-token>'.$this->syncToken.'</d:sync-token>' : '';
        $ctag = $this->ctag !== null ? '<cs:getctag>'.$this->ctag.'</cs:getctag>' : '';

        return $this->wrap('<d:response><d:href>'.$this->collectionPath.'</d:href><d:propstat><d:prop><d:resourcetype><d:collection/></d:resourcetype>'
            .$ctag.$token.'<d:supported-report-set>'.$reports.'</d:supported-report-set></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>');
    }

    private function etagList(): string
    {
        $xml = '';

        foreach ($this->resources as $href => $resource) {
            $xml .= '<d:response><d:href>'.$href.'</d:href><d:propstat><d:prop><d:getetag>"'.$resource['etag'].'"</d:getetag></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>';
        }

        return $this->wrap($xml);
    }

    /**
     * @param  array<int, string>  $hrefs
     */
    private function multiget(array $hrefs): string
    {
        $xml = '';

        foreach ($hrefs as $href) {
            if (! isset($this->resources[$href])) {
                $xml .= '<d:response><d:href>'.$href.'</d:href><d:status>HTTP/1.1 404 Not Found</d:status></d:response>';

                continue;
            }

            $resource = $this->resources[$href];
            $xml .= '<d:response><d:href>'.$href.'</d:href><d:propstat><d:prop><d:getetag>"'.$resource['etag'].'"</d:getetag>'
                .'<x:'.$this->dataElement.'>'.htmlspecialchars($resource['data'], ENT_XML1 | ENT_QUOTES, 'UTF-8').'</x:'.$this->dataElement.'>'
                .'</d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>';
        }

        return $this->wrap($xml);
    }

    private function syncCollection(): string
    {
        $xml = '';

        foreach ($this->syncChanged as $href) {
            $xml .= '<d:response><d:href>'.$href.'</d:href><d:propstat><d:prop><d:getetag>"'.$this->resources[$href]['etag'].'"</d:getetag></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>';
        }

        foreach ($this->syncDeleted as $href) {
            $xml .= '<d:response><d:href>'.$href.'</d:href><d:status>HTTP/1.1 404 Not Found</d:status></d:response>';
        }

        return $this->wrap($xml.'<d:sync-token>'.$this->syncToken.'</d:sync-token>');
    }

    private function wrap(string $inner): string
    {
        $ns = $this->dataElement === 'address-data' ? 'urn:ietf:params:xml:ns:carddav' : 'urn:ietf:params:xml:ns:caldav';

        return '<?xml version="1.0" encoding="utf-8"?><d:multistatus xmlns:d="DAV:" xmlns:cs="http://calendarserver.org/ns/" xmlns:card="urn:ietf:params:xml:ns:carddav" xmlns:x="'.$ns.'">'.$inner.'</d:multistatus>';
    }
}
