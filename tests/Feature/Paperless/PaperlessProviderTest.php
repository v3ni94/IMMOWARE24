<?php

declare(strict_types=1);

namespace Tests\Feature\Paperless;

use App\Core\Contracts\Mail\PaperlessSourceInterface;
use App\Modules\Mail\Exceptions\MailIntegrationNotConfiguredException;
use App\Modules\Mail\Exceptions\MailRemoteException;
use App\Modules\Paperless\Services\NotConfiguredPaperlessSource;
use App\Modules\Paperless\Services\PaperlessProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Nur Http::fake(): kein Live-Test gegen eine echte Paperless-Instanz möglich. Endpunkte und Feldnamen aus
 * allgemeinem Wissen zur öffentlichen Paperless-ngx-REST-API, am eigenen Server zu prüfen.
 */
final class PaperlessProviderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('hub.paperless.base_url', 'https://paperless.muellerhv.de');
        config()->set('hub.paperless.api_token', 'geheimer-token');
        config()->set('hub.paperless.retry.times', 1);
        config()->set('hub.paperless.object_number_field_id', 7);
    }

    private function provider(): PaperlessProvider
    {
        return $this->app->make(PaperlessProvider::class);
    }

    public function test_default_binding_without_configuration_is_not_configured(): void
    {
        config()->set('hub.paperless.base_url', null);
        config()->set('hub.paperless.api_token', null);

        $this->assertInstanceOf(NotConfiguredPaperlessSource::class, $this->app->make(PaperlessSourceInterface::class));
        $this->assertFalse($this->provider()->isConfigured());

        $this->expectException(MailIntegrationNotConfiguredException::class);
        $this->provider()->search('x');
    }

    public function test_search_sends_query_and_maps_object_number_from_custom_fields(): void
    {
        Http::fake([
            'https://paperless.muellerhv.de/api/documents/*' => Http::response([
                'count' => 1,
                'next' => null,
                'results' => [[
                    'id' => 42, 'title' => 'Nebenkostenabrechnung 2025', 'correspondent' => 'Verwaltung',
                    'document_type' => 'Abrechnung', 'created' => '2026-03-01T10:00:00Z', 'tags' => ['abrechnung'],
                    'custom_fields' => [['field' => 7, 'value' => 'OBJ-0001']],
                ]],
            ]),
        ]);

        $result = $this->provider()->search('Nebenkosten');

        $this->assertSame(1, $result['count']);
        $this->assertFalse($result['next']);
        $this->assertSame('OBJ-0001', $result['documents'][0]['object_number']);
        $this->assertSame('Nebenkostenabrechnung 2025', $result['documents'][0]['title']);

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), '/api/documents/')) {
                return false;
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $query['query'] === 'Nebenkosten' && $request->header('Authorization')[0] === 'Token geheimer-token';
        });
    }

    public function test_for_property_uses_custom_field_query_and_empty_without_field_id(): void
    {
        Http::fake([
            'https://paperless.muellerhv.de/api/documents/*' => Http::response(['count' => 0, 'next' => null, 'results' => []]),
        ]);

        $this->provider()->forProperty('OBJ-0001');

        Http::assertSent(function (Request $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return isset($query['custom_field_query']) && str_contains($query['custom_field_query'], 'OBJ-0001');
        });

        config()->set('hub.paperless.object_number_field_id', null);
        $result = $this->provider()->forProperty('OBJ-0001');
        $this->assertSame(['documents' => [], 'count' => 0, 'next' => false], $result);
    }

    public function test_upload_is_blocked_without_write_flag(): void
    {
        config()->set('hub.mail.flags.paperless_write', false);

        $this->expectException(MailIntegrationNotConfiguredException::class);
        $this->provider()->upload('beleg.pdf', 'Inhalt', 'application/pdf', 'Beleg', 'OBJ-0001');
    }

    public function test_upload_sends_multipart_with_custom_field_and_returns_task_id(): void
    {
        config()->set('hub.mail.flags.paperless_write', true);

        Http::fake([
            'https://paperless.muellerhv.de/api/documents/post_document/' => Http::response('"3fa85f64-5717-4562-b3fc-2c963f66afa6"'),
        ]);

        $taskId = $this->provider()->upload('beleg.pdf', 'Inhalt', 'application/pdf', 'Beleg Handwerker', 'OBJ-0001');

        $this->assertSame('3fa85f64-5717-4562-b3fc-2c963f66afa6', $taskId);

        Http::assertSent(function (Request $request): bool {
            return str_contains($request->url(), 'post_document') && $request->isMultipart();
        });
    }

    public function test_upload_error_response_raises_mail_remote_exception(): void
    {
        config()->set('hub.mail.flags.paperless_write', true);

        Http::fake(['https://paperless.muellerhv.de/api/documents/post_document/' => Http::response(['detail' => 'ungueltig'], 400)]);

        try {
            $this->provider()->upload('beleg.pdf', 'Inhalt', 'application/pdf', 'Beleg', null);
            $this->fail('Erwartet MailRemoteException.');
        } catch (MailRemoteException $e) {
            $this->assertSame(400, $e->httpStatus);
            $this->assertSame('paperless', $e->integration);
        }
    }
}
