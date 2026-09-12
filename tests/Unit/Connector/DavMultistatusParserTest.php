<?php

declare(strict_types=1);

namespace Tests\Unit\Connector;

use App\Modules\Connector\Support\DavMultistatusParser;
use PHPUnit\Framework\TestCase;

final class DavMultistatusParserTest extends TestCase
{
    private const string XML = <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<D:multistatus xmlns:D="DAV:" xmlns:CS="http://calendarserver.org/ns/">
  <D:sync-token>http://example.test/sync/42</D:sync-token>
  <D:response>
    <D:href>/share/</D:href>
    <D:propstat>
      <D:prop>
        <D:resourcetype><D:collection/></D:resourcetype>
        <D:getetag>"root-1"</D:getetag>
        <CS:getctag>ctag-7</CS:getctag>
        <D:sync-token>http://example.test/sync/42</D:sync-token>
        <D:supported-report-set>
          <D:supported-report><D:report><D:sync-collection/></D:report></D:supported-report>
          <D:supported-report><D:report><C:addressbook-multiget xmlns:C="urn:ietf:params:xml:ns:carddav"/></D:report></D:supported-report>
        </D:supported-report-set>
      </D:prop>
      <D:status>HTTP/1.1 200 OK</D:status>
    </D:propstat>
  </D:response>
  <D:response>
    <D:href>/share/a.pdf</D:href>
    <D:propstat>
      <D:prop>
        <D:resourcetype/>
        <D:getetag>"a-1"</D:getetag>
        <D:getlastmodified>Fri, 11 Sep 2026 10:00:00 GMT</D:getlastmodified>
        <D:getcontentlength>1234</D:getcontentlength>
      </D:prop>
      <D:status>HTTP/1.1 200 OK</D:status>
    </D:propstat>
  </D:response>
</D:multistatus>
XML;

    public function test_parses_multistatus_with_properties_and_reports(): void
    {
        $parser = new DavMultistatusParser;
        $responses = $parser->parse(self::XML);

        $this->assertCount(2, $responses);
        $this->assertTrue($responses[0]->isCollection);
        $this->assertSame('"root-1"', $responses[0]->etag);
        $this->assertSame('ctag-7', $responses[0]->ctag);
        $this->assertSame('http://example.test/sync/42', $responses[0]->syncToken);
        $this->assertTrue($responses[0]->supportsReport('sync-collection'));
        $this->assertTrue($responses[0]->supportsReport('addressbook-multiget'));
        $this->assertSame(200, $responses[0]->status);

        $this->assertFalse($responses[1]->isCollection);
        $this->assertSame(1234, $responses[1]->contentLength);
        $this->assertNotNull($responses[1]->lastModified);
        $this->assertSame('http://example.test/sync/42', $parser->rootSyncToken(self::XML));
        $this->assertTrue($parser->isMultistatus(self::XML));
    }

    public function test_rejects_doctype_and_invalid_xml(): void
    {
        $parser = new DavMultistatusParser;

        $this->assertSame([], $parser->parse('<!DOCTYPE foo [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><D:multistatus xmlns:D="DAV:"/>'));
        $this->assertSame([], $parser->parse('kein xml'));
        $this->assertFalse($parser->isMultistatus('<html></html>'));
    }
}
