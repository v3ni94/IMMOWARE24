<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Core\Enums\Role;
use App\Modules\Connector\Models\Organization;
use App\Modules\Security\Models\AuditLog;
use App\Modules\Security\Models\User;
use App\Modules\Security\Services\LoginService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class UsersTest extends TestCase
{
    use RefreshDatabase;

    private function login(User $user): static
    {
        return $this->actingAs($user)->withSession([LoginService::SESSION_TWO_FACTOR_VERIFIED => now()->toIso8601String()]);
    }

    public function test_index_and_edit_render_for_administrator(): void
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->role(Role::Administrator)->for($organization)->create();
        $target = User::factory()->role(Role::Operator)->for($organization)->create(['name' => 'Ute Operator', 'locked_until' => now()->addMinutes(10), 'failed_login_count' => 0]);

        $this->login($admin)->get('/admin/users')->assertOk()->assertSee('Ute Operator')->assertSee('Gesperrt bis');
        $this->login($admin)->get('/admin/users?state=locked')->assertOk()->assertSee('Ute Operator');
        $this->login($admin)->get('/admin/users/'.$target->getKey().'/edit')->assertOk()->assertSee('Sperre aufheben')->assertSee('2FA zurücksetzen');
    }

    public function test_read_only_is_forbidden(): void
    {
        $user = User::factory()->role(Role::ReadOnly)->withoutTotp()->create();

        $this->actingAs($user)->get('/admin/users')->assertForbidden();
        $this->actingAs($user)->post('/admin/users', ['name' => 'x', 'email' => 'x@example.test', 'role' => 'operator', 'password' => 'Sicher12345678', 'password_confirmation' => 'Sicher12345678'])->assertForbidden();
    }

    public function test_administrator_creates_operator_but_not_administrator(): void
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->role(Role::Administrator)->for($organization)->create();

        $this->login($admin)->post('/admin/users', [
            'name' => 'Neuer Operator',
            'email' => 'Operator@Example.test',
            'role' => 'operator',
            'password' => 'Sicher12345678',
            'password_confirmation' => 'Sicher12345678',
        ])->assertRedirect('/admin/users');

        $created = User::query()->where('email', 'operator@example.test')->firstOrFail();
        $this->assertSame(Role::Operator, $created->role);
        $this->assertSame((int) $organization->getKey(), (int) $created->organization_id);
        $this->assertNull($created->totp_confirmed_at);
        $this->assertNotNull(AuditLog::query()->where('action', 'admin.users.created')->first());

        $this->login($admin)->post('/admin/users', [
            'name' => 'Zweiter Admin',
            'email' => 'admin2@example.test',
            'role' => 'administrator',
            'password' => 'Sicher12345678',
            'password_confirmation' => 'Sicher12345678',
        ])->assertForbidden();
    }

    public function test_owner_assigns_administrator_role_and_cannot_change_own_role(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->role(Role::Owner)->for($organization)->create();
        $target = User::factory()->role(Role::Operator)->for($organization)->create();

        $this->login($owner)->put('/admin/users/'.$target->getKey(), ['name' => $target->name, 'role' => 'administrator', 'disabled' => '0'])->assertRedirect('/admin/users');
        $this->assertSame(Role::Administrator, $target->fresh()->role);

        $log = AuditLog::query()->where('action', 'admin.users.updated')->firstOrFail();
        $this->assertSame('operator', $log->before_json['role']);
        $this->assertSame('administrator', $log->after_json['role']);

        $this->login($owner)->put('/admin/users/'.$owner->getKey(), ['name' => $owner->name, 'role' => 'read_only'])->assertForbidden();
        $this->login($owner)->put('/admin/users/'.$owner->getKey(), ['name' => $owner->name, 'disabled' => '1'])->assertForbidden();
    }

    public function test_disable_reset_two_factor_and_unlock(): void
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->role(Role::Administrator)->for($organization)->create();
        $target = User::factory()->role(Role::ReadOnly)->for($organization)->create(['totp_secret' => 'JBSWY3DPEHPK3PXP', 'locked_until' => now()->addMinutes(5), 'failed_login_count' => 3]);

        $this->login($admin)->post('/admin/users/'.$target->getKey().'/reset-two-factor', ['confirmation' => 'nein'])->assertStatus(422);
        $this->assertTrue($target->fresh()->hasConfirmedTotp());

        $this->login($admin)->post('/admin/users/'.$target->getKey().'/reset-two-factor', ['confirmation' => 'BESTÄTIGEN', 'reason' => 'Gerät verloren'])->assertRedirect('/admin/users/'.$target->getKey().'/edit');
        $fresh = $target->fresh();
        $this->assertFalse($fresh->hasConfirmedTotp());
        $this->assertNull($fresh->totp_secret);
        $this->assertNotNull(AuditLog::query()->where('action', 'admin.users.two_factor_reset')->first());

        $this->login($admin)->post('/admin/users/'.$target->getKey().'/unlock')->assertRedirect('/admin/users/'.$target->getKey().'/edit');
        $this->assertFalse($target->fresh()->isLocked());
        $this->assertSame(0, (int) $target->fresh()->failed_login_count);
        $this->assertNotNull(AuditLog::query()->where('action', 'admin.users.unlocked')->first());

        $this->login($admin)->put('/admin/users/'.$target->getKey(), ['name' => 'Deaktiviert', 'disabled' => '1'])->assertRedirect('/admin/users');
        $this->assertTrue($target->fresh()->isDisabled());
    }

    public function test_roles_matrix_is_read_only_and_reflects_config(): void
    {
        $user = User::factory()->role(Role::Administrator)->create();

        $response = $this->login($user)->get('/admin/roles')->assertOk();
        $response->assertSee('data-role-matrix', false);
        $response->assertSee('users.manage');
        $response->assertSee('data-cell="administrator:users.manage"', false);
        $response->assertSee('data-cell="read_only:audit.view"', false);
        $this->assertStringNotContainsString('<form', substr((string) $response->getContent(), (int) strpos((string) $response->getContent(), 'data-role-matrix')));

        $html = (string) $response->getContent();
        $this->assertMatchesRegularExpression('/data-cell="read_only:users\.manage">\s*<span class="hub-muted">nein/', $html);
        $this->assertMatchesRegularExpression('/data-cell="owner:users\.manage">\s*<span class="hub-badge hub-badge-ok">ja/', $html);

        $this->actingAs(User::factory()->role(Role::ReadOnly)->withoutTotp()->create())->get('/admin/roles')->assertForbidden();
    }
}
