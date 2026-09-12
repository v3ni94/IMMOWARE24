<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Core\Enums\Role;
use App\Core\Enums\SyncMode;
use App\Modules\Connector\Models\ImmowareConnection;
use App\Modules\Connector\Models\Organization;
use App\Modules\Security\Models\AuditLog;
use App\Modules\Security\Models\User;
use App\Modules\Security\Services\LoginService;
use App\Modules\Sync\Jobs\FetchImmowareDocumentsJob;
use App\Modules\Sync\Models\SyncEvent;
use App\Modules\Sync\Models\SyncRun;
use App\Modules\Sync\Models\SyncState;
use App\Modules\Sync\Support\SyncLockManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class SyncTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private ImmowareConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organization = Organization::factory()->create();
        $this->connection = ImmowareConnection::factory()->for($this->organization)->active()->create(['name' => 'WebDAV Hauptmandant']);
    }

    private function loginAs(Role $role): User
    {
        $user = User::factory()->role($role)->for($this->organization)->create();
        $this->actingAs($user)->withSession([LoginService::SESSION_TWO_FACTOR_VERIFIED => now()->toIso8601String()]);

        return $user;
    }

    private function makeRun(array $attributes = []): SyncRun
    {
        return SyncRun::query()->create(array_merge([
            'connection_id' => $this->connection->getKey(),
            'run_type' => SyncRun::TYPE_INCREMENTAL,
            'entity_type' => 'document',
            'mode' => 'incremental',
            'trigger_source' => 'schedule',
            'status' => 'succeeded',
            'phase' => SyncRun::PHASE_DONE,
            'started_at' => now()->subMinutes(5),
            'finished_at' => now()->subMinutes(4),
            'duration_ms' => 60000,
            'counters' => ['processed' => 42, 'created' => 3, 'updated' => 5, 'deleted' => 1, 'failed' => 0],
        ], $attributes));
    }

    public function test_monitor_shows_last_run_counters_and_stale_hint(): void
    {
        $this->loginAs(Role::ReadOnly);
        $this->makeRun();
        SyncState::query()->create([
            'connection_id' => $this->connection->getKey(),
            'entity_type' => 'document',
            'scope' => SyncState::SCOPE_COLLECTION,
            'collection_path_hash' => hash('sha256', 'collection:document'),
            'last_success_at' => now()->subHours(5),
            'stale_since' => now()->subHours(3),
        ]);

        $response = $this->get('/admin/sync')->assertOk();
        $response->assertSee('WebDAV Hauptmandant')
            ->assertSee('data-sync-row="'.$this->connection->getKey().':document"', false)
            ->assertSee('Bestand veraltet (stale)')
            ->assertSee('Läufe starten erfordert das Recht sync.run')
            ->assertDontSee('Incremental Sync starten');
    }

    public function test_administrator_dispatches_incremental_sync_with_audit(): void
    {
        Bus::fake();
        $user = $this->loginAs(Role::Administrator);

        $this->get('/admin/sync')->assertOk()->assertSee('Incremental Sync starten')->assertSee('Bootstrap-Assistent');

        $this->post('/admin/sync/start', ['connection_id' => $this->connection->getKey(), 'entity_type' => 'document', 'mode' => 'incremental'])
            ->assertRedirect('/admin/sync')
            ->assertSessionHas('status');

        Bus::assertDispatched(FetchImmowareDocumentsJob::class, function (FetchImmowareDocumentsJob $job) use ($user): bool {
            return $job->connectionId === (int) $this->connection->getKey()
                && $job->mode === SyncMode::Incremental
                && $job->triggerSource === 'manual'
                && $job->startedBy === (int) $user->getKey();
        });

        $audit = AuditLog::query()->where('action', 'admin.sync.started')->firstOrFail();
        $this->assertSame('document', $audit->getAttribute('after_json')['entity_type']);
        $this->assertSame((int) $this->connection->getKey(), (int) $audit->getAttribute('connection_id'));
    }

    public function test_full_sync_requires_confirmation_and_respects_lock(): void
    {
        Bus::fake();
        $this->loginAs(Role::Administrator);

        $this->post('/admin/sync/start', ['connection_id' => $this->connection->getKey(), 'entity_type' => 'document', 'mode' => 'full'])->assertStatus(422);
        Bus::assertNothingDispatched();

        $locks = $this->app->make(SyncLockManager::class);
        $owner = $locks->acquire((int) $this->connection->getKey(), 'document');
        $this->assertNotNull($owner);

        $this->post('/admin/sync/start', ['connection_id' => $this->connection->getKey(), 'entity_type' => 'document', 'mode' => 'full', 'confirmation' => 'BESTÄTIGEN'])
            ->assertRedirect('/admin/sync')
            ->assertSessionHas('warning');
        Bus::assertNothingDispatched();

        $locks->release((int) $this->connection->getKey(), 'document', $owner);

        $this->post('/admin/sync/start', ['connection_id' => $this->connection->getKey(), 'entity_type' => 'document', 'mode' => 'full', 'confirmation' => 'BESTÄTIGEN'])
            ->assertRedirect('/admin/sync')
            ->assertSessionHas('status');
        Bus::assertDispatched(FetchImmowareDocumentsJob::class, fn (FetchImmowareDocumentsJob $job): bool => $job->mode === SyncMode::Full);
    }

    public function test_read_only_and_operator_cannot_start_sync(): void
    {
        Bus::fake();

        $this->loginAs(Role::ReadOnly);
        $this->post('/admin/sync/start', ['connection_id' => $this->connection->getKey(), 'entity_type' => 'document', 'mode' => 'incremental'])->assertForbidden();

        $this->loginAs(Role::Operator);
        $this->post('/admin/sync/bootstrap', ['connection_id' => $this->connection->getKey(), 'entity_type' => 'document', 'stage' => '1', 'confirmation' => 'BESTÄTIGEN'])->assertForbidden();

        Bus::assertNothingDispatched();
    }

    public function test_bootstrap_stage_runs_synchronously_and_is_audited(): void
    {
        Http::fake(fn () => Http::response('', 503));
        $this->loginAs(Role::Administrator);

        $this->post('/admin/sync/bootstrap', ['connection_id' => $this->connection->getKey(), 'entity_type' => 'document', 'stage' => '1', 'confirmation' => 'BESTÄTIGEN'])
            ->assertRedirect('/admin/sync');

        $audit = AuditLog::query()->where('action', 'admin.sync.bootstrap_stage')->firstOrFail();
        $this->assertSame('1', $audit->getAttribute('after_json')['stage']);

        $bootstrapRuns = SyncRun::query()->where('run_type', SyncRun::TYPE_BOOTSTRAP)->where('trigger_source', 'manual')->count();
        $this->assertGreaterThanOrEqual(1, $bootstrapRuns, 'Jede Stufe ist ein eigener sync_run');

        $this->assertFalse($audit->getAttribute('after_json')['passed'], 'Stufe ohne verarbeitete Datensätze und mit Fehlern gilt nicht als bestanden');
        $runId = (int) $audit->getAttribute('after_json')['run_id'];
        $this->get('/admin/sync')->assertOk()->assertSee('href="'.url('/admin/sync/runs/'.$runId).'"', false)->assertDontSee('Noch keine Bootstrap-Stufe ausgeführt.');
    }

    public function test_bootstrap_stage_all_dispatches_full_sync(): void
    {
        Bus::fake();
        $this->loginAs(Role::Administrator);

        $this->post('/admin/sync/bootstrap', ['connection_id' => $this->connection->getKey(), 'entity_type' => 'document', 'stage' => 'alle', 'confirmation' => 'BESTÄTIGEN'])
            ->assertRedirect('/admin/sync')
            ->assertSessionHas('status');

        Bus::assertDispatched(FetchImmowareDocumentsJob::class, fn (FetchImmowareDocumentsJob $job): bool => $job->mode === SyncMode::Full);
        $this->assertSame('alle', AuditLog::query()->where('action', 'admin.sync.bootstrap_stage')->firstOrFail()->getAttribute('after_json')['stage']);
    }

    public function test_history_and_run_detail_with_events(): void
    {
        $this->loginAs(Role::ReadOnly);
        $run = $this->makeRun(['error_summary' => null]);
        $failed = $this->makeRun(['status' => 'failed', 'phase' => SyncRun::PHASE_FAILED, 'error_summary' => 'PROPFIND 503 Service Unavailable', 'counters' => ['processed' => 1, 'failed' => 1]]);
        $foreignConnection = ImmowareConnection::factory()->create();
        $foreignRun = SyncRun::query()->create([
            'connection_id' => $foreignConnection->getKey(),
            'run_type' => SyncRun::TYPE_INCREMENTAL,
            'entity_type' => 'document',
            'status' => 'succeeded',
            'phase' => SyncRun::PHASE_DONE,
            'started_at' => now(),
            'counters' => [],
        ]);

        SyncEvent::query()->create([
            'sync_run_id' => $run->getKey(),
            'connection_id' => $this->connection->getKey(),
            'entity_type' => 'document',
            'entity_id' => 7,
            'action' => 'document.updated',
            'detected_by' => 'etag',
            'old_checksum' => str_repeat('a', 64),
            'new_checksum' => str_repeat('b', 64),
            'occurred_at' => now(),
        ]);

        $this->get('/admin/sync/runs')->assertOk()->assertSee('#'.$run->getKey())->assertSee('#'.$failed->getKey())->assertDontSee('#'.$foreignRun->getKey().'<', false);
        $this->get('/admin/sync/runs?status=failed')->assertOk()->assertSee('PROPFIND 503')->assertDontSee('href="'.url('/admin/sync/runs/'.$run->getKey()).'"', false);

        $this->get('/admin/sync/runs/'.$run->getKey())->assertOk()->assertSee('document.updated')->assertSee('aaaaaaaaaaaa…');
        $this->get('/admin/sync/runs/'.$foreignRun->getKey())->assertNotFound();
    }
}
