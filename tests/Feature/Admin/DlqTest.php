<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Core\Enums\Role;
use App\Modules\Connector\Models\ImmowareConnection;
use App\Modules\Connector\Models\Organization;
use App\Modules\Security\Models\AuditLog;
use App\Modules\Security\Models\User;
use App\Modules\Security\Services\LoginService;
use App\Modules\Sync\Enums\DlqStatus;
use App\Modules\Sync\Jobs\ProcessDlqRetryJob;
use App\Modules\Sync\Models\DlqItem;
use App\Modules\Sync\Services\FieldMappingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

final class DlqTest extends TestCase
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

    private function item(array $attributes = []): DlqItem
    {
        return DlqItem::query()->create(array_merge([
            'job_class' => 'App\\Modules\\Sync\\Jobs\\RunSyncJob',
            'connection_id' => $this->connection->getKey(),
            'entity_type' => 'document',
            'queue' => 'sync',
            'correlation_id' => 'corr-1',
            'payload_json' => ['job' => 'App\\Modules\\Sync\\Jobs\\RunSyncJob', 'arguments' => ['connectionId' => $this->connection->getKey(), 'entityType' => 'document', 'singleRecord' => true, 'password' => 'streng-geheim']],
            'exception' => "RuntimeException: PROPFIND 503 Service Unavailable\n#0 trace",
            'failed_at' => now(),
            'status' => DlqStatus::Open,
            'attempts' => 0,
        ], $attributes));
    }

    public function test_list_and_detail_render_with_masked_payload(): void
    {
        $this->app->make(FieldMappingService::class)->seedDefaults();
        $this->loginAs(Role::ReadOnly);
        $item = $this->item();
        $ignored = $this->item(['status' => DlqStatus::Ignored, 'exception' => 'RuntimeException: ignoriert']);
        $foreign = DlqItem::query()->create([
            'job_class' => 'App\\Modules\\Sync\\Jobs\\RunSyncJob',
            'connection_id' => ImmowareConnection::factory()->create()->getKey(),
            'queue' => 'sync',
            'payload_json' => ['job' => 'x'],
            'exception' => 'RuntimeException: fremd',
            'failed_at' => now(),
            'status' => DlqStatus::Open,
        ]);

        $this->get('/admin/dlq')->assertOk()
            ->assertSee('href="'.url('/admin/dlq/'.$item->getKey()).'"', false)
            ->assertDontSee('href="'.url('/admin/dlq/'.$ignored->getKey()).'"', false)
            ->assertDontSee('href="'.url('/admin/dlq/'.$foreign->getKey()).'"', false);

        $this->get('/admin/dlq?status=ignored')->assertOk()->assertSee('href="'.url('/admin/dlq/'.$ignored->getKey()).'"', false);

        $this->get('/admin/dlq/'.$item->getKey())->assertOk()
            ->assertSee('Payload ansehen')
            ->assertSee('Fehler ansehen')
            ->assertSee('Mapping ansehen')
            ->assertSee('PROPFIND 503 Service Unavailable')
            ->assertSee('ein einzelner Datensatz (singleRecord)')
            ->assertSee('getetag')
            ->assertDontSee('streng-geheim')
            ->assertSee('erfordern das Recht sync.run');

        $this->get('/admin/dlq/'.$foreign->getKey())->assertNotFound();
    }

    public function test_retry_dispatches_single_item_job_with_audit(): void
    {
        Bus::fake();
        $user = $this->loginAs(Role::Administrator);
        $item = $this->item();

        $this->post('/admin/dlq/'.$item->getKey().'/retry')->assertStatus(422);

        $this->post('/admin/dlq/'.$item->getKey().'/retry', ['confirmation' => 'BESTÄTIGEN'])->assertRedirect('/admin/dlq/'.$item->getKey());

        $item->refresh();
        $this->assertSame(DlqStatus::Retrying, $item->getAttribute('status'));
        $this->assertSame((int) $user->getKey(), (int) $item->getAttribute('replayed_by'));

        Bus::assertDispatched(ProcessDlqRetryJob::class, fn (ProcessDlqRetryJob $job): bool => $job->dlqItemId === (int) $item->getKey());
        Bus::assertDispatchedTimes(ProcessDlqRetryJob::class, 1);

        $audit = AuditLog::query()->where('action', 'admin.dlq.retried')->firstOrFail();
        $this->assertSame('open', $audit->getAttribute('before_json')['status']);
        $this->assertSame('retrying', $audit->getAttribute('after_json')['status']);
    }

    public function test_ignore_requires_note_and_is_audited(): void
    {
        Bus::fake();
        $this->loginAs(Role::Administrator);
        $item = $this->item();

        $this->post('/admin/dlq/'.$item->getKey().'/ignore', ['confirmation' => 'BESTÄTIGEN'])->assertRedirect()->assertSessionHasErrors('note');
        $this->assertSame(DlqStatus::Open, $item->refresh()->getAttribute('status'));

        $this->post('/admin/dlq/'.$item->getKey().'/ignore', ['confirmation' => 'BESTÄTIGEN', 'note' => 'Datei wurde in Immoware24 gelöscht.'])->assertRedirect();

        $this->assertSame(DlqStatus::Ignored, $item->refresh()->getAttribute('status'));
        $audit = AuditLog::query()->where('action', 'admin.dlq.ignored')->firstOrFail();
        $this->assertSame('Datei wurde in Immoware24 gelöscht.', $audit->getAttribute('after_json')['note']);

        $this->post('/admin/dlq/'.$item->getKey().'/retry', ['confirmation' => 'BESTÄTIGEN'])->assertRedirect()->assertSessionHas('warning');
        Bus::assertNotDispatched(ProcessDlqRetryJob::class);
    }

    public function test_read_only_and_operator_cannot_retry_or_ignore(): void
    {
        Bus::fake();
        $item = $this->item();

        $this->loginAs(Role::ReadOnly);
        $this->post('/admin/dlq/'.$item->getKey().'/retry', ['confirmation' => 'BESTÄTIGEN'])->assertForbidden();

        $this->loginAs(Role::Operator);
        $this->post('/admin/dlq/'.$item->getKey().'/ignore', ['confirmation' => 'BESTÄTIGEN', 'note' => 'ohne Recht'])->assertForbidden();

        $this->assertSame(DlqStatus::Open, $item->refresh()->getAttribute('status'));
        Bus::assertNothingDispatched();
    }
}
