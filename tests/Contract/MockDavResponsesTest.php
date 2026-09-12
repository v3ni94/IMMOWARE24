<?php

declare(strict_types=1);

namespace Tests\Contract;

use App\Core\Exceptions\ConnectorException;
use App\Modules\Connector\Support\DavMultistatusParser;
use App\Modules\Connector\Testing\MockDavResponses;
use App\Modules\Contacts\Dav\CardDavClient;
use App\Modules\Contacts\Services\DavClientFactory;
use App\Modules\Documents\Http\WebDavClientFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Prüft die wiederverwendbaren Http::fake-Bausteine aus MockDavResponses gegen die echten Parser und Clients.
 */
final class MockDavResponsesTest extends TestCase
{
    use RefreshDatabase;

    public function test_multistatus_bausteine_werden_vom_parser_gelesen(): void
    {
        $xml = MockDavResponses::multistatusXml([
            MockDavResponses::addressbook('/dav/addressbooks/kontakte/', 'ctag-7', ['addressbook-query', 'addressbook-multiget', 'sync-collection'], 'token-1'),
            MockDavResponses::vcardItem('/dav/addressbooks/kontakte/a.vcf', 'etag-a', MockDavResponses::vcard('uid-a', 'Erika Mustermann', ['CATEGORIES' => 'Mieter'])),
            MockDavResponses::file('/dav/files/Posteingang/x.pdf', 'etag-x', 1234),
            MockDavResponses::missing('/dav/addressbooks/kontakte/weg.vcf'),
        ]);

        $responses = (new DavMultistatusParser)->parse($xml);

        $this->assertCount(4, $responses);
        $this->assertTrue($responses[0]->isCollection);
        $this->assertSame('ctag-7', $responses[0]->ctag);
        $this->assertSame('token-1', $responses[0]->syncToken);
        $this->assertTrue($responses[0]->supportsReport('sync-collection'));
        $this->assertSame('"etag-a"', $responses[1]->etag);
        $this->assertSame(1234, $responses[2]->contentLength);
        $this->assertSame(404, $responses[3]->status);
        $this->assertStringContainsString('CATEGORIES:Mieter', $xml);
    }

    public function test_fehlerantworten_tragen_status_und_header(): void
    {
        Http::fake([
            'dav.example.test/limit/*' => MockDavResponses::rateLimited(10),
            'dav.example.test/auth/*' => MockDavResponses::unauthorized(),
            'dav.example.test/exists/*' => MockDavResponses::preconditionFailed(),
            'dav.example.test/seq/*' => MockDavResponses::sequence([MockDavResponses::serverError(), MockDavResponses::emptyMultistatus()]),
        ]);

        $limited = Http::get('https://dav.example.test/limit/x');
        $this->assertSame(429, $limited->status());
        $this->assertSame('10', $limited->header('Retry-After'));

        $unauthorized = Http::get('https://dav.example.test/auth/x');
        $this->assertSame(401, $unauthorized->status());
        $this->assertStringStartsWith('Basic realm=', $unauthorized->header('WWW-Authenticate'));

        $this->assertSame(412, Http::get('https://dav.example.test/exists/x')->status());
        $this->assertSame(500, Http::get('https://dav.example.test/seq/x')->status());
        $this->assertSame(207, Http::get('https://dav.example.test/seq/x')->status());
    }

    public function test_invalid_xml_fuehrt_im_carddav_client_zu_connector_exception(): void
    {
        Http::fake(['dav.example.test/*' => MockDavResponses::invalidXml()]);

        $connection = $this->createConnection(null, ['connector_type' => 'carddav_contacts', 'base_url' => 'https://dav.example.test/dav/addressbooks/kontakte/', 'base_url_hash' => hash('sha256', 'https://dav.example.test/dav/addressbooks/kontakte/'), 'status' => 'active', 'rate_limit_rps' => 50]);
        $client = $this->app->make(DavClientFactory::class)->carddav($connection);
        $this->assertInstanceOf(CardDavClient::class, $client);

        $this->expectException(ConnectorException::class);
        $this->expectExceptionMessage('ohne gültiges Multistatus-XML');

        $client->propfindCollection();
    }

    public function test_invalid_xml_fuehrt_im_webdav_client_zu_connector_exception(): void
    {
        Http::fake(['dav.example.test/*' => MockDavResponses::invalidXml()]);

        $connection = $this->createConnection(null, ['base_url' => 'https://dav.example.test/dav/files', 'base_url_hash' => hash('sha256', 'https://dav.example.test/dav/files'), 'status' => 'active']);
        $client = $this->app->make(WebDavClientFactory::class)->forConnection($connection);

        $this->expectException(ConnectorException::class);

        $client->propfind('/Posteingang/', 1);
    }
}
