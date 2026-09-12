<?php

declare(strict_types=1);

namespace Tests\Feature\Drive;

use App\Core\Contracts\Mail\DocumentSourceInterface;
use App\Modules\Drive\Models\DriveConnection;
use App\Modules\Drive\Services\DriveProvider;
use App\Modules\Drive\Services\NotConfiguredDocumentSource;
use App\Modules\Mail\Exceptions\MailIntegrationNotConfiguredException;
use App\Modules\Mail\Exceptions\MailRemoteException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Nur Http::fake(): kein Live-Test gegen googleapis.com möglich. Parameter aus Snippets, am Original zu prüfen.
 */
final class DriveProviderTest extends TestCase
{
    use RefreshDatabase;

    private DriveConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('hub.drive.oauth.client_id', 'client-id');
        config()->set('hub.drive.oauth.client_secret', 'client-secret');
        config()->set('hub.drive.retry.times', 1);

        $organization = $this->createOrganization();
        $this->connection = DriveConnection::query()->create([
            'organization_id' => $organization->getKey(),
            'label' => 'Ablage',
            'oauth_refresh_token' => 'refresh-geheim',
            'oauth_access_token' => null,
            'status' => 'configured',
        ]);
    }

    private function provider(): DriveProvider
    {
        return $this->app->make(DriveProvider::class)->using($this->connection);
    }

    public function test_default_binding_without_configuration_is_not_configured(): void
    {
        $this->assertInstanceOf(NotConfiguredDocumentSource::class, $this->app->make(DocumentSourceInterface::class));

        $this->connection->forceFill(['oauth_refresh_token' => null])->save();
        $this->assertFalse($this->app->make(DriveProvider::class)->isConfigured());

        $this->expectException(MailIntegrationNotConfiguredException::class);
        $this->app->make(DriveProvider::class)->search('x');
    }

    public function test_search_refreshes_token_and_sends_documented_list_parameters(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-1', 'expires_in' => 3600]),
            'https://www.googleapis.com/drive/v3/files*' => Http::response([
                'nextPageToken' => 'seite2',
                'files' => [
                    ['id' => 'f1', 'name' => 'Protokoll ETV 2026.gdoc', 'mimeType' => 'application/vnd.google-apps.document', 'modifiedTime' => '2026-09-01T10:00:00Z', 'webViewLink' => 'https://docs.google.com/x', 'parents' => ['ordner1']],
                    ['id' => 'f2', 'name' => 'alt.pdf', 'mimeType' => 'application/pdf', 'trashed' => true],
                ],
            ]),
        ]);

        $result = $this->provider()->search("Protokoll 'ETV'", ['folder_id' => 'ordner1', 'page_token' => 'abc']);

        $this->assertCount(1, $result['files'], 'Papierkorb wird ausgeblendet.');
        $this->assertSame('f1', $result['files'][0]['id']);
        $this->assertSame(['ordner1'], $result['files'][0]['parents']);
        $this->assertSame('seite2', $result['next_page_token']);

        $fresh = $this->connection->fresh();
        $this->assertSame('access-1', $fresh?->getAttribute('oauth_access_token'));
        $this->assertSame('active', $fresh?->getAttribute('status'));

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), '/drive/v3/files')) {
                return false;
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $this->assertSame("fullText contains 'Protokoll \\'ETV\\'' and 'ordner1' in parents and trashed = false", $query['q']);
            $this->assertSame('true', $query['supportsAllDrives']);
            $this->assertSame('true', $query['includeItemsFromAllDrives']);
            $this->assertSame('abc', $query['pageToken']);
            $this->assertStringContainsString('files(id,name,mimeType', $query['fields']);
            $this->assertSame('GET', $request->method());
            $this->assertSame('Bearer access-1', $request->header('Authorization')[0]);

            return true;
        });

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'oauth2.googleapis.com/token')
            && $request['grant_type'] === 'refresh_token' && $request['refresh_token'] === 'refresh-geheim');
    }

    public function test_get_includes_permissions_and_export_uses_text_plain(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-1', 'expires_in' => 3600]),
            'https://www.googleapis.com/drive/v3/files/f1/permissions*' => Http::response(['permissions' => [
                ['id' => 'p1', 'type' => 'user', 'role' => 'reader', 'emailAddress' => 'Anna@MuellerHV.de'],
                ['id' => 'p2', 'type' => 'domain', 'role' => 'reader', 'domain' => 'muellerhv.de'],
            ]]),
            'https://www.googleapis.com/drive/v3/files/f1/export*' => Http::response('Protokoll der Eigentümerversammlung'),
            'https://www.googleapis.com/drive/v3/files/f1*' => Http::response(['id' => 'f1', 'name' => 'Protokoll', 'mimeType' => 'application/vnd.google-apps.document', 'size' => '1234', 'parents' => ['o']]),
        ]);

        $file = $this->provider()->get('f1');

        $this->assertSame(1234, $file['size']);
        $this->assertSame([['type' => 'user', 'role' => 'reader', 'email' => 'anna@muellerhv.de'], ['type' => 'domain', 'role' => 'reader', 'email' => null]], $file['permissions_summary']);

        $this->assertSame('Protokoll der Eigentümerversammlung', $this->provider()->textContent('f1', 'application/vnd.google-apps.document'));
        $this->assertNull($this->provider()->textContent('f1', 'application/pdf'), 'PDF ohne OCR liefert keinen Auszug.');

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), '/export')) {
                return false;
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $query['mimeType'] === 'text/plain';
        });
    }

    public function test_401_triggers_one_token_refresh_and_5xx_is_retried_limited(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::sequence()
                ->push(['access_token' => 'alt', 'expires_in' => 3600])
                ->push(['access_token' => 'neu', 'expires_in' => 3600]),
            'https://www.googleapis.com/drive/v3/files*' => Http::sequence()
                ->push(['error' => 'unauth'], 401)
                ->push(['error' => 'busy'], 503)
                ->push(['files' => [['id' => 'f9', 'name' => 'x', 'mimeType' => 'text/plain']]]),
        ]);

        $result = $this->provider()->listFolder('ordner');

        $this->assertSame('f9', $result['files'][0]['id']);
        $this->assertSame('neu', $this->connection->fresh()?->getAttribute('oauth_access_token'));
        Http::assertSentCount(5);
    }

    public function test_client_error_marks_connection_degraded(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'a', 'expires_in' => 3600]),
            'https://www.googleapis.com/drive/v3/files*' => Http::response(['error' => 'notFound'], 404),
        ]);

        try {
            $this->provider()->get('fehlt');
            $this->fail('Erwartet MailRemoteException.');
        } catch (MailRemoteException $e) {
            $this->assertSame(404, $e->httpStatus);
            $this->assertSame('degraded', $this->connection->fresh()?->getAttribute('status'));
        }
    }

    public function test_invalid_grant_marks_connection_revoked_without_leaking_secret(): void
    {
        Http::fake(['https://oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);

        try {
            $this->provider()->get('f1');
            $this->fail('Erwartet MailRemoteException.');
        } catch (MailRemoteException $e) {
            $this->assertStringNotContainsString('refresh-geheim', $e->getMessage());
            $this->assertSame('revoked', $this->connection->fresh()?->getAttribute('status'));
        }
    }
}
