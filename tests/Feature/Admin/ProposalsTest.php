<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Core\Enums\Role;
use App\Modules\Connector\Models\ImmowareConnection;
use App\Modules\Connector\Models\Organization;
use App\Modules\Security\Models\AuditLog;
use App\Modules\Security\Models\User;
use App\Modules\Security\Services\LoginService;
use App\Modules\Sync\Enums\ProposedChangeStatus;
use App\Modules\Sync\Models\ProposedChange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ProposalsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private ImmowareConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organization = Organization::factory()->create();
        $this->connection = ImmowareConnection::factory()->for($this->organization)->carddav()->create();
    }

    private function loginAs(Role $role): User
    {
        $user = User::factory()->role($role)->for($this->organization)->create();
        $this->actingAs($user)->withSession([LoginService::SESSION_TWO_FACTOR_VERIFIED => now()->toIso8601String()]);

        return $user;
    }

    private function proposal(array $attributes = []): ProposedChange
    {
        return ProposedChange::query()->create(array_merge([
            'organization_id' => $this->organization->getKey(),
            'connection_id' => $this->connection->getKey(),
            'entity_type' => 'contact',
            'entity_id' => 5,
            'field' => 'phone',
            'old_value' => '+49 30 1234',
            'new_value' => '+49 30 5678',
            'reason' => 'Mieter hat neue Nummer gemeldet.',
            'status' => ProposedChangeStatus::Open,
        ], $attributes));
    }

    public function test_list_filters_open_and_transferred(): void
    {
        $this->loginAs(Role::ReadOnly);
        $open = $this->proposal();
        $transferred = $this->proposal(['entity_id' => 6, 'status' => ProposedChangeStatus::Transferred, 'transferred_at' => now()]);
        $foreign = ProposedChange::query()->create([
            'organization_id' => Organization::factory()->create()->getKey(),
            'entity_type' => 'contact',
            'entity_id' => 7,
            'field' => 'phone',
            'status' => ProposedChangeStatus::Open,
        ]);

        $this->get('/admin/proposals')->assertOk()
            ->assertSee('href="'.url('/admin/proposals/'.$open->getKey()).'"', false)
            ->assertDontSee('href="'.url('/admin/proposals/'.$transferred->getKey()).'"', false)
            ->assertDontSee('href="'.url('/admin/proposals/'.$foreign->getKey()).'"', false);

        $this->get('/admin/proposals?status=transferred')->assertOk()->assertSee('href="'.url('/admin/proposals/'.$transferred->getKey()).'"', false);
        $this->get('/admin/proposals/'.$open->getKey())->assertOk()->assertSee('+49 30 5678')->assertSee('Mieter hat neue Nummer gemeldet.');
        $this->get('/admin/proposals/'.$foreign->getKey())->assertNotFound();
    }

    public function test_operator_marks_transferred_with_user_and_time(): void
    {
        $user = $this->loginAs(Role::Operator);
        $proposal = $this->proposal();

        $this->get('/admin/proposals/'.$proposal->getKey())->assertOk()->assertSee('Als in Immoware24 übertragen markieren');

        $this->post('/admin/proposals/'.$proposal->getKey().'/transfer')->assertStatus(422);

        $this->post('/admin/proposals/'.$proposal->getKey().'/transfer', ['confirmation' => 'BESTÄTIGEN', 'note' => 'In Immoware24 am 12.09.2026 geändert.'])
            ->assertRedirect('/admin/proposals/'.$proposal->getKey());

        $proposal->refresh();
        $this->assertSame(ProposedChangeStatus::Transferred, $proposal->getAttribute('status'));
        $this->assertSame((int) $user->getKey(), (int) $proposal->getAttribute('transferred_by'));
        $this->assertNotNull($proposal->getAttribute('transferred_at'));

        $audit = AuditLog::query()->where('action', 'admin.proposals.transferred')->firstOrFail();
        $this->assertSame((int) $user->getKey(), (int) $audit->getAttribute('after_json')['transferred_by']);
        $this->assertSame('In Immoware24 am 12.09.2026 geändert.', $audit->getAttribute('after_json')['note']);

        $this->post('/admin/proposals/'.$proposal->getKey().'/transfer', ['confirmation' => 'BESTÄTIGEN'])->assertRedirect()->assertSessionHas('warning');
        $this->get('/admin/proposals/'.$proposal->getKey())->assertOk()->assertSee($user->name)->assertSee('Der Vorschlag ist nicht mehr offen.');
    }

    public function test_read_only_cannot_mark_transferred(): void
    {
        $this->loginAs(Role::ReadOnly);
        $proposal = $this->proposal();

        $this->get('/admin/proposals/'.$proposal->getKey())->assertOk()->assertSee('erfordert das Recht conflicts.resolve');
        $this->post('/admin/proposals/'.$proposal->getKey().'/transfer', ['confirmation' => 'BESTÄTIGEN'])->assertForbidden();
        $this->assertSame(ProposedChangeStatus::Open, $proposal->refresh()->getAttribute('status'));
        $this->assertSame(0, AuditLog::query()->where('action', 'admin.proposals.transferred')->count());
    }
}
