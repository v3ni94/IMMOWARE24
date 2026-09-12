<?php

declare(strict_types=1);

namespace Tests\Feature\EndToEnd;

use App\Core\Enums\SyncMode;
use App\Modules\Connector\Models\ImmowareConnection;
use App\Modules\Connector\Models\Organization;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Documents\Models\Document;
use App\Modules\Security\Models\ApiKey;
use App\Modules\Sync\Enums\SyncEntity;
use App\Modules\Sync\Jobs\RunSyncJob;
use App\Modules\Sync\Models\SyncRun;
use App\Modules\Webhooks\Models\WebhookOutbox;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Contacts\FakeDavServer;
use Tests\Feature\Documents\DocumentsTestHelpers;
use Tests\TestCase;

/**
 * Durchstich: simulierter Immoware24-DAV-Server (Http::fake) → RunSyncJob (synchron) → REST-API mit API-Key.
 */
final class WebDavToApiTest extends TestCase
{
    use DocumentsTestHelpers, RefreshDatabase;

    private const string CARDDAV_PATH = '/carddav/addressbooks/hub-read/kontakte/';

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->createOrganization();
        config()->set('hub.documents.scan.roots', ['/Posteingang/']);
        config()->set('hub.documents.content_hash.enabled', false);
        config()->set('hub.webhooks.enabled', true);
    }

    public function test_webdav_documents_reach_the_api_with_provenance(): void
    {
        $connection = $this->createConnection($this->organization, ['rate_limit_rps' => 50, 'status' => 'active']);
        $base = $this->basePathOf($connection);

        $listing = $this->multistatus($base, [
            ['href' => '/Posteingang/', 'collection' => true],
            ['href' => '/Posteingang/Scan_001.pdf', 'etag' => 'e1', 'length' => 100, 'modified' => 'Fri, 11 Sep 2026 10:00:00 GMT', 'type' => 'application/pdf'],
            ['href' => '/Posteingang/Rechnung Müller.pdf', 'etag' => 'e2', 'length' => 200, 'modified' => 'Fri, 11 Sep 2026 10:05:00 GMT', 'type' => 'application/pdf'],
            ['href' => '/Posteingang/Protokoll ETV 2026.pdf', 'etag' => 'e3', 'length' => 300, 'modified' => 'Fri, 11 Sep 2026 10:10:00 GMT', 'type' => 'application/pdf'],
        ]);

        Http::fake(function (Request $request) use ($listing): PromiseInterface {
            if ($request->method() === 'PROPFIND') {
                return Http::response($listing, 207, ['Content-Type' => 'application/xml']);
            }

            return Http::response('', 405);
        });

        $this->app->call([new RunSyncJob((int) $connection->getKey(), SyncEntity::Document->value, SyncMode::Incremental), 'handle']);

        $this->assertSame(3, Document::query()->withoutGlobalScopes()->where('connection_id', $connection->getKey())->count());
        $this->assertSame(1, SyncRun::query()->where('connection_id', $connection->getKey())->where('status', 'succeeded')->count());
        $this->assertSame(3, WebhookOutbox::query()->where('event_type', 'document.created')->count());

        Http::assertNotSent(static fn (Request $request): bool => in_array($request->method(), ['PUT', 'DELETE', 'MOVE', 'MKCOL'], true));

        $key = $this->issueKey(['documents:read']);
        $response = $this->getJson('/api/v1/documents?sort=id', ['Authorization' => 'Bearer '.$key, 'Accept' => 'application/json']);

        $response->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure(['data' => [['id', 'path', 'filename', 'provenance' => ['source_system', 'external_id', 'last_synced_at', 'connector', 'mapping_version', 'data_age_seconds', 'stale']]]]);

        $filenames = array_column($response->json('data'), 'filename');
        $this->assertEqualsCanonicalizing(['Scan_001.pdf', 'Rechnung Müller.pdf', 'Protokoll ETV 2026.pdf'], $filenames);
        $this->assertSame('immoware24', $response->json('data.0.provenance.source_system'));
        $this->assertSame('/Posteingang/Scan_001.pdf', $response->json('data.0.provenance.external_id'));
        $this->assertSame('webdav_documents', $response->json('data.0.provenance.connector'));

        // Ohne passenden Scope wird der Zugriff verweigert.
        $this->getJson('/api/v1/documents', ['Authorization' => 'Bearer '.$this->issueKey(['contacts:read'])])->assertStatus(403);
    }

    public function test_carddav_contacts_reach_contacts_and_directory_endpoints(): void
    {
        $server = new FakeDavServer('dav.immoware.test', self::CARDDAV_PATH);
        $connection = $this->createConnection($this->organization, [
            'connector_type' => 'carddav_contacts',
            'base_url' => $server->url(),
            'base_url_hash' => hash('sha256', $server->url()),
            'status' => 'active',
        ]);

        $server->put(self::CARDDAV_PATH.'c1.vcf', 'e1', (string) file_get_contents(base_path('tests/Unit/Contacts/Fixtures/umlaute.vcf')));
        $server->put(self::CARDDAV_PATH.'c2.vcf', 'e2', (string) file_get_contents(base_path('tests/Unit/Contacts/Fixtures/quoted-printable.vcf')));
        $server->install();

        $this->app->call([new RunSyncJob((int) $connection->getKey(), SyncEntity::Contact->value, SyncMode::Incremental), 'handle']);

        $this->assertSame(2, Contact::query()->withoutGlobalScopes()->where('connection_id', $connection->getKey())->count());
        $this->assertSame(2, WebhookOutbox::query()->where('event_type', 'contact.created')->count());
        Http::assertNotSent(static fn (Request $request): bool => in_array($request->method(), ['PUT', 'DELETE', 'POST'], true));

        $key = $this->issueKey(['contacts:read', 'directory:read']);
        $headers = ['Authorization' => 'Bearer '.$key, 'Accept' => 'application/json'];

        $contacts = $this->getJson('/api/v1/contacts?sort=last_name', $headers);
        $contacts->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonStructure(['data' => [['id', 'first_name', 'last_name', 'provenance' => ['source_system', 'external_id', 'connector']]]]);
        $this->assertSame('carddav_contacts', $contacts->json('data.0.provenance.connector'));
        $this->assertContains('Müller-Lüdenscheidt', array_column($contacts->json('data'), 'last_name'));

        $directory = $this->getJson('/api/v1/directory', $headers);
        $directory->assertOk()->assertJsonPath('meta.total', 2);
        $this->assertStringContainsString('Müller-Lüdenscheidt', (string) $directory->getContent());

        $this->get('/api/v1/directory/search?q=Müller&format=vcf', $headers)
            ->assertOk()
            ->assertSee('BEGIN:VCARD', false);
    }

    public function test_failed_sync_run_emits_sync_failed_webhook_event(): void
    {
        // Der REST-API-Slot hat keine Endpunkte (WAITING_FOR_VENDOR_ACCESS), pull() schlägt deterministisch fehl.
        $connection = $this->createConnection($this->organization, ['connector_type' => 'rest_api_slot', 'name' => 'API-Slot', 'status' => 'active']);
        Http::fake();

        $job = new RunSyncJob((int) $connection->getKey(), SyncEntity::Document->value, SyncMode::Incremental);

        try {
            $this->app->call([$job, 'handle']);
        } catch (\Throwable $exception) {
            $job->failed($exception);
        }

        $this->assertSame(1, SyncRun::query()->where('connection_id', $connection->getKey())->where('status', 'failed')->count());
        $this->assertSame(1, WebhookOutbox::query()->where('event_type', 'sync.failed')->count());
        $this->assertDatabaseCount('dlq_items', 1);
    }

    /**
     * @param  array<int, string>  $scopes
     */
    private function issueKey(array $scopes): string
    {
        $plain = 'hub_live_'.Str::random(8).'_'.Str::random(43);

        ApiKey::factory()->for($this->organization)->withPlainKey($plain)->scopes($scopes)->create();

        return $plain;
    }

    protected function readConnection(?Organization $organization = null): ImmowareConnection
    {
        return $this->createConnection($organization ?? $this->organization, ['rate_limit_rps' => 50, 'status' => 'active']);
    }
}
