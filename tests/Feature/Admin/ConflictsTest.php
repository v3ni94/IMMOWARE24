<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Core\Enums\Role;
use App\Modules\Connector\Models\ImmowareConnection;
use App\Modules\Connector\Models\Organization;
use App\Modules\Security\Models\AuditLog;
use App\Modules\Security\Models\User;
use App\Modules\Security\Services\LoginService;
use App\Modules\Sync\Models\Conflict;
use App\Modules\Sync\Models\ExternalPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ConflictsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private ImmowareConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organization = Organization::factory()->create();
        $this->connection = ImmowareConnection::factory()->for($this->organization)->carddav()->active()->create();
    }

    private function loginAs(Role $role): User
    {
        $user = User::factory()->role($role)->for($this->organization)->create();
        $this->actingAs($user)->withSession([LoginService::SESSION_TWO_FACTOR_VERIFIED => now()->toIso8601String()]);

        return $user;
    }

    private function conflict(array $attributes = []): Conflict
    {
        return Conflict::query()->create(array_merge([
            'connection_id' => $this->connection->getKey(),
            'entity_type' => 'contact',
            'entity_id' => 11,
            'conflict_type' => 'field_mismatch',
            'conflict_state' => 'both_changed',
            'local_snapshot_json' => ['last_name' => 'Müller-Lokal', 'phone' => '+49 30 1234'],
            'status' => 'open',
        ], $attributes));
    }

    public function test_queue_lists_open_conflicts_of_own_organization(): void
    {
        $this->loginAs(Role::ReadOnly);
        $open = $this->conflict();
        $resolved = $this->conflict(['entity_id' => 12, 'status' => 'resolved_keep_remote']);
        $foreign = Conflict::query()->create([
            'connection_id' => ImmowareConnection::factory()->create()->getKey(),
            'entity_type' => 'contact',
            'entity_id' => 99,
            'conflict_type' => 'field_mismatch',
            'status' => 'open',
        ]);

        $this->get('/admin/conflicts')->assertOk()
            ->assertSee('href="'.url('/admin/conflicts/'.$open->getKey()).'"', false)
            ->assertDontSee('href="'.url('/admin/conflicts/'.$resolved->getKey()).'"', false)
            ->assertDontSee('href="'.url('/admin/conflicts/'.$foreign->getKey()).'"', false);

        $this->get('/admin/conflicts?status=resolved')->assertOk()->assertSee('href="'.url('/admin/conflicts/'.$resolved->getKey()).'"', false);
        $this->get('/admin/conflicts/'.$foreign->getKey())->assertNotFound();
    }

    public function test_detail_shows_local_and_remote_side_by_side(): void
    {
        $this->loginAs(Role::Operator);
        $payload = ExternalPayload::query()->create([
            'connection_id' => $this->connection->getKey(),
            'payload_type' => 'vcard',
            'external_id_hash' => hash('sha256', 'uid-11'),
            'content_hash' => hash('sha256', 'remote'),
            'content_inline' => json_encode(['last_name' => 'Müller-Remote', 'phone' => '+49 30 9999'], JSON_THROW_ON_ERROR),
            'size_bytes' => 60,
            'received_at' => now(),
        ]);
        $conflict = $this->conflict(['remote_payload_id' => $payload->getKey()]);

        $this->get('/admin/conflicts/'.$conflict->getKey())->assertOk()
            ->assertSee('Lokal (Spiegel im Hub)')
            ->assertSee('Müller-Lokal')
            ->assertSee('Remote (Immoware24, archivierte Nutzlast)')
            ->assertSee('Müller-Remote')
            ->assertSee('Konflikt auflösen');
    }

    public function test_operator_resolves_with_note_and_audit(): void
    {
        $user = $this->loginAs(Role::Operator);
        $conflict = $this->conflict();

        $this->post('/admin/conflicts/'.$conflict->getKey().'/resolve', ['resolution' => 'remote', 'note' => 'Immoware24 ist Master.'])->assertRedirect()->assertSessionHasErrors('confirmation');
        $this->assertSame('open', $conflict->refresh()->getAttribute('status'));

        $this->post('/admin/conflicts/'.$conflict->getKey().'/resolve', ['resolution' => 'remote', 'note' => 'Immoware24 ist Master.', 'confirmation' => 'BESTÄTIGEN'])
            ->assertRedirect('/admin/conflicts/'.$conflict->getKey());

        $conflict->refresh();
        $this->assertSame('resolved_keep_remote', $conflict->getAttribute('status'));
        $this->assertSame((int) $user->getKey(), (int) $conflict->getAttribute('resolved_by'));
        $this->assertSame('Immoware24 ist Master.', $conflict->getAttribute('resolution_note'));

        $audit = AuditLog::query()->where('action', 'admin.conflicts.resolved')->firstOrFail();
        $this->assertSame('remote', $audit->getAttribute('after_json')['resolution']);
        $this->assertSame('open', $audit->getAttribute('before_json')['status']);

        $this->post('/admin/conflicts/'.$conflict->getKey().'/resolve', ['resolution' => 'local', 'note' => 'noch einmal', 'confirmation' => 'BESTÄTIGEN'])->assertRedirect()->assertSessionHas('warning');
    }

    public function test_read_only_cannot_resolve(): void
    {
        $this->loginAs(Role::ReadOnly);
        $conflict = $this->conflict();

        $this->get('/admin/conflicts/'.$conflict->getKey())->assertOk()->assertSee('erfordert das Recht conflicts.resolve');
        $this->post('/admin/conflicts/'.$conflict->getKey().'/resolve', ['resolution' => 'remote', 'note' => 'Versuch ohne Recht', 'confirmation' => 'BESTÄTIGEN'])->assertForbidden();
        $this->assertSame('open', $conflict->refresh()->getAttribute('status'));
        $this->assertSame(0, AuditLog::query()->where('action', 'admin.conflicts.resolved')->count());
    }
}
