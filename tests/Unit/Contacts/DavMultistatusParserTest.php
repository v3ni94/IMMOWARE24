<?php

declare(strict_types=1);

namespace Tests\Unit\Contacts;

use App\Modules\Contacts\Dav\DavMultistatusParser;
use PHPUnit\Framework\TestCase;

final class DavMultistatusParserTest extends TestCase
{
    public function test_parses_propfind_with_ctag_sync_token_and_reports(): void
    {
        $xml = '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:" xmlns:cs="http://calendarserver.org/ns/" xmlns:card="urn:ietf:params:xml:ns:carddav">'
            .'<d:response><d:href>/dav/ab/</d:href><d:propstat><d:prop><d:resourcetype><d:collection/><card:addressbook/></d:resourcetype>'
            .'<cs:getctag>ctag-1</cs:getctag><d:sync-token>http://x/sync/5</d:sync-token>'
            .'<d:supported-report-set><d:supported-report><d:report><d:sync-collection/></d:report></d:supported-report>'
            .'<d:supported-report><d:report><card:addressbook-multiget/></d:report></d:supported-report></d:supported-report-set>'
            .'</d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response></d:multistatus>';

        $resources = (new DavMultistatusParser)->parse($xml);

        $this->assertCount(1, $resources);
        $this->assertTrue($resources[0]->isCollection);
        $this->assertSame('ctag-1', $resources[0]->ctag);
        $this->assertSame('http://x/sync/5', $resources[0]->syncToken);
        $this->assertSame(200, $resources[0]->status);
        $this->assertTrue($resources[0]->supportsReport('sync-collection'));
        $this->assertTrue($resources[0]->supportsReport('addressbook-multiget'));
    }

    public function test_parses_multiget_with_address_data_and_404(): void
    {
        $xml = '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:" xmlns:card="urn:ietf:params:xml:ns:carddav">'
            .'<d:sync-token>tok-2</d:sync-token>'
            .'<d:response><d:href>/dav/ab/a.vcf</d:href><d:propstat><d:prop><d:getetag>"e1"</d:getetag><card:address-data>BEGIN:VCARD&#13;&#10;END:VCARD</card:address-data></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>'
            .'<d:response><d:href>/dav/ab/gone.vcf</d:href><d:status>HTTP/1.1 404 Not Found</d:status></d:response>'
            .'</d:multistatus>';

        $parser = new DavMultistatusParser;
        $resources = $parser->parse($xml);

        $this->assertCount(2, $resources);
        $this->assertSame('"e1"', $resources[0]->etag);
        $this->assertStringContainsString('BEGIN:VCARD', (string) $resources[0]->data);
        $this->assertTrue($resources[1]->isNotFound());
        $this->assertSame('tok-2', $parser->rootSyncToken($xml));
    }

    public function test_rejects_doctype(): void
    {
        $xml = '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><d:multistatus xmlns:d="DAV:"><d:response><d:href>&e;</d:href></d:response></d:multistatus>';

        $this->assertSame([], (new DavMultistatusParser)->parse($xml));
    }
}
