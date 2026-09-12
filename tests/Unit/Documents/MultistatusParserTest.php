<?php

declare(strict_types=1);

namespace Tests\Unit\Documents;

use App\Modules\Documents\Support\MultistatusParser;
use PHPUnit\Framework\TestCase;

final class MultistatusParserTest extends TestCase
{
    public function test_parses_umlauts_special_characters_and_url_encoding(): void
    {
        $xml = '<?xml version="1.0" encoding="utf-8"?><D:multistatus xmlns:D="DAV:">'
            .'<D:response><D:href>/share/Dokumente/</D:href><D:propstat><D:prop><D:resourcetype><D:collection/></D:resourcetype><D:displayname>Dokumente</D:displayname></D:prop><D:status>HTTP/1.1 200 OK</D:status></D:propstat></D:response>'
            .'<D:response><D:href>/share/Dokumente/M%C3%BCller%20%26%20S%C3%B6hne%20%28Rechnung%29%20%231.pdf</D:href><D:propstat><D:prop>'
            .'<D:resourcetype/><D:getetag>"abc-1"</D:getetag><D:getlastmodified>Fri, 11 Sep 2026 10:00:00 GMT</D:getlastmodified>'
            .'<D:getcontentlength>1234</D:getcontentlength><D:getcontenttype>application/pdf; charset=binary</D:getcontenttype>'
            .'<D:displayname>Müller &amp; Söhne (Rechnung) #1.pdf</D:displayname>'
            .'</D:prop><D:status>HTTP/1.1 200 OK</D:status></D:propstat></D:response>'
            .'<D:response><D:href>/share/Dokumente/Straße%20ohne%20Props.pdf</D:href><D:propstat><D:prop><D:resourcetype/></D:prop><D:status>HTTP/1.1 200 OK</D:status></D:propstat>'
            .'<D:propstat><D:prop><D:getetag/><D:getcontentlength/></D:prop><D:status>HTTP/1.1 404 Not Found</D:status></D:propstat></D:response>'
            .'</D:multistatus>';

        $entries = (new MultistatusParser)->parse($xml, 'https://dav.example.test/share');

        $this->assertCount(3, $entries);

        $this->assertTrue($entries[0]->isCollection);
        $this->assertSame('/Dokumente/', $entries[0]->path);

        $file = $entries[1];
        $this->assertFalse($file->isCollection);
        $this->assertSame('/Dokumente/Müller & Söhne (Rechnung) #1.pdf', $file->path);
        $this->assertSame('Müller & Söhne (Rechnung) #1.pdf', $file->name());
        $this->assertSame('"abc-1"', $file->etag);
        $this->assertSame(1234, $file->contentLength);
        $this->assertSame('application/pdf', $file->contentType);
        $this->assertSame('Müller & Söhne (Rechnung) #1.pdf', $file->displayName);
        $this->assertSame('2026-09-11 10:00:00', $file->lastModifiedAt()?->format('Y-m-d H:i:s'));

        $sparse = $entries[2];
        $this->assertSame('/Dokumente/Straße ohne Props.pdf', $sparse->path);
        $this->assertNull($sparse->etag);
        $this->assertNull($sparse->contentLength);
        $this->assertNull($sparse->lastModifiedAt());
        $this->assertSame(200, $sparse->status);
    }

    public function test_handles_absolute_hrefs_other_prefixes_and_rejects_doctype(): void
    {
        $parser = new MultistatusParser;

        $xml = '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:"><d:response><d:href>https://dav.example.test/share/Posteingang/scan.pdf</d:href>'
            .'<d:propstat><d:prop><d:resourcetype/><d:getcontentlength>10</d:getcontentlength></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response></d:multistatus>';

        $entries = $parser->parse($xml, 'https://dav.example.test/share/');
        $this->assertCount(1, $entries);
        $this->assertSame('/Posteingang/scan.pdf', $entries[0]->path);
        $this->assertTrue($parser->isMultistatus($xml));

        $this->assertSame([], $parser->parse('<!DOCTYPE foo [<!ENTITY x SYSTEM "file:///etc/passwd">]><D:multistatus xmlns:D="DAV:"/>'));
        $this->assertSame([], $parser->parse('kein xml'));
        $this->assertSame([], $parser->parse(''));
    }

    public function test_propfind_body_requests_all_mirror_properties(): void
    {
        $body = MultistatusParser::propfindBody();

        foreach (['getetag', 'getlastmodified', 'getcontentlength', 'getcontenttype', 'resourcetype', 'displayname'] as $prop) {
            $this->assertStringContainsString('<D:'.$prop.'/>', $body);
        }
    }
}
