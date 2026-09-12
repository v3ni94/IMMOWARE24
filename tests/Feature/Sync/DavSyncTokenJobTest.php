<?php

declare(strict_types=1);

namespace Tests\Feature\Sync;

use App\Core\Enums\SyncMode;
use App\Core\Enums\SyncStatus;
use App\Modules\Connector\Models\ImmowareConnection;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Sync\Enums\SyncEntity;
use App\Modules\Sync\Jobs\FetchImmowareContactsJob;
use App\Modules\Sync\Jobs\RunSyncJob;
use App\Modules\Sync\Models\SyncRun;
use App\Modules\Sync\Models\SyncState;
use App\Modules\Sync\Services\BootstrapService;
use App\Modules\Sync\Services\SyncStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Contacts\FakeDavServer;
use Tests\TestCase;

/**
 * Reproduziert das Finding "sync-token als Cursor": RunSyncJob und BootstrapService gegen einen CardDAV-Server,
 * der einen sync-token liefert, müssen den Lauf nach einem Durchlauf abschließen (kein Re-Dispatch, kein Endloslauf).
 */
final class DavSyncTokenJobTest extends TestCase
{
    use RefreshDatabase;

    private const string PATH = '/carddav/addressbooks/hub-read/kontakte/';

    private FakeDavServer $server;

    private ImmowareConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->server = new FakeDavServer('dav.immoware.test', self::PATH);
        $this->server->supportsSyncCollection = true;
        $this->server->syncToken = 'tok-1';
        $this->server->put(self::PATH.'c1.vcf', 'e1', (string) file_get_contents(base_path('tests/Unit/Contacts/Fixtures/umlaute.vcf')));
        $this->server->put(self::PATH.'c2.vcf', 'e2', (string) file_get_contents(base_path('tests/Unit/Contacts/Fixtures/quoted-printable.vcf')));
        $this->server->install();

        $this->connection = $this->createConnection(null, [
            'connector_type' => 'carddav_contacts',
            'base_url' => $this->server->url(),
            'base_url_hash' => hash('sha256', $this->server->url()),
            'status' => 'active',
            'probe_result' => ['sync_token_supported' => true],
            'rate_limit_rps' => 50,
            'last_health_ok' => true,
        ]);
        config()->set('hub.sync.chunks.max_per_run', 3);
    }

    private function requestCount(string $method): int
    {
        $count = 0;
        Http::assertSent(function (Request $request) use ($method, &$count): bool {
            if ($request->method() === $method) {
                $count++;
            }

            return true;
        });

        return $count;
    }

    public function test_incremental_run_with_sync_token_server_finishes_and_commits_state(): void
    {
        Queue::fake();
        $connectionId = (int) $this->connection->getKey();

        $this->app->call([new FetchImmowareContactsJob($connectionId), 'handle']);

        Queue::assertNotPushed(RunSyncJob::class);
        Queue::assertNotPushed(FetchImmowareContactsJob::class);
        $this->assertSame(2, Contact::query()->withoutGlobalScopes()->count());
        $this->assertSame(1, $this->requestCount('PROPFIND'), 'genau eine Enumeration je Lauf, keine 20-fache Wiederholung');

        $run = SyncRun::query()->firstOrFail();
        $this->assertSame(SyncStatus::Succeeded, $run->getAttribute('status'));
        $this->assertSame(1, (int) $run->getAttribute('chunks'));

        $entityState = $this->app->make(SyncStateService::class)->find($connectionId, SyncEntity::Contact->value);
        $this->assertNotNull($entityState);
        $this->assertNotNull($entityState->getAttribute('last_success_at'), 'commitSuccess muss erreicht werden');
        $this->assertNull($entityState->getAttribute('stale_since'));

        $collectionState = SyncState::query()->where('connection_id', $connectionId)->whereNotNull('sync_token')->first();
        $this->assertNotNull($collectionState, 'Token wird ausschließlich über den Collection-State persistiert');
        $this->assertSame('tok-1', $collectionState->getAttribute('sync_token'));

        // Zweiter Lauf nutzt sync-collection und endet ebenfalls ohne Re-Dispatch.
        Http::fake();
        $this->server->install();
        $this->app->call([new FetchImmowareContactsJob($connectionId), 'handle']);
        Queue::assertNotPushed(FetchImmowareContactsJob::class);
        $this->assertSame(2, SyncRun::query()->where('status', SyncStatus::Succeeded->value)->count());
    }

    public function test_full_run_with_sync_token_server_enumerates_once(): void
    {
        Queue::fake();

        $this->app->call([new FetchImmowareContactsJob((int) $this->connection->getKey(), SyncMode::Full), 'handle']);

        Queue::assertNotPushed(FetchImmowareContactsJob::class);
        $this->assertSame(1, $this->requestCount('PROPFIND'));
        $this->assertSame(SyncStatus::Succeeded, SyncRun::query()->firstOrFail()->getAttribute('status'));
    }

    public function test_bootstrap_stage_alle_terminates_with_sync_token_server(): void
    {
        $report = $this->app->make(BootstrapService::class)->run($this->connection, SyncEntity::Contact->value, [null]);

        $this->assertTrue($report['completed']);
        $this->assertSame(2, $report['stages'][0]['processed']);
        $this->assertSame(1, $this->requestCount('PROPFIND'));
    }
}
