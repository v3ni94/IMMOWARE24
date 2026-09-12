<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Core\Enums\Role;
use App\Modules\Connector\Models\Organization;
use App\Modules\Security\Models\AuditLog;
use App\Modules\Security\Models\User;
use App\Modules\Security\Services\LoginService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        $user = User::factory()->role(Role::ReadOnly)->create();

        $this->login($user)->get('/admin/users')->assertForbidden();
        $this->login($user)->post('/admin/users', ['name' => 'x', 'email' => 'x@example.test', 'role' => 'operator', 'password' => 'Sicher12345678', 'password_confirmation' => 'Sicher12345678'])->assertForbidden();
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

        $this->login(User::factory()->role(Role::ReadOnly)->create())->get('/admin/roles')->assertForbidden();
    }

    public function test_administrator_cannot_set_password_of_owner_or_administrator(): void
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->role(Role::Administrator)->for($organization)->create();
        $owner = User::factory()->role(Role::Owner)->for($organization)->create();
        $otherAdmin = User::factory()->role(Role::Administrator)->for($organization)->create();
        $ownerHash = (string) $owner->getAttribute('password');

        $payload = ['password' => 'NeuesPasswort2026', 'password_confirmation' => 'NeuesPasswort2026'];

        $this->login($admin)->put('/admin/users/'.$owner->getKey(), ['name' => $owner->name] + $payload)->assertForbidden();
        $this->login($admin)->put('/admin/users/'.$otherAdmin->getKey(), ['name' => $otherAdmin->name] + $payload)->assertForbidden();

        $this->assertSame($ownerHash, (string) $owner->fresh()->getAttribute('password'));
        $this->assertDatabaseMissing('audit_logs', ['action' => 'admin.users.updated']);
    }

    public function test_password_change_of_other_user_terminates_their_sessions(): void
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->role(Role::Administrator)->for($organization)->create();
        $target = User::factory()->role(Role::Operator)->for($organization)->create();

        DB::table('sessions')->insert([
            ['id' => 'target-a', 'user_id' => $target->getKey(), 'ip_address' => '10.0.0.1', 'user_agent' => 'Firefox', 'payload' => '', 'last_activity' => time()],
            ['id' => 'target-b', 'user_id' => $target->getKey(), 'ip_address' => '10.0.0.2', 'user_agent' => 'Safari', 'payload' => '', 'last_activity' => time()],
            ['id' => 'admin-s', 'user_id' => $admin->getKey(), 'ip_address' => '10.0.0.3', 'user_agent' => 'Chrome', 'payload' => '', 'last_activity' => time()],
        ]);

        $this->login($admin)->put('/admin/users/'.$target->getKey(), [
            'name' => $target->name,
            'password' => 'NeuesPasswort2026',
            'password_confirmation' => 'NeuesPasswort2026',
        ])->assertRedirect('/admin/users');

        $this->assertDatabaseMissing('sessions', ['id' => 'target-a']);
        $this->assertDatabaseMissing('sessions', ['id' => 'target-b']);
        $this->assertDatabaseHas('sessions', ['id' => 'admin-s']);

        $log = AuditLog::query()->where('action', 'admin.users.updated')->firstOrFail();
        $this->assertTrue($log->after_json['login_reset']);
        $this->assertSame(2, $log->after_json['sessions_terminated']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'security.sessions.all_terminated', 'entity_id' => $target->getKey()]);
    }

    public function test_last_active_owner_cannot_be_demoted_or_disabled(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->role(Role::Owner)->for($organization)->create();
        $disabledOwner = User::factory()->role(Role::Owner)->for($organization)->create(['disabled_at' => now()]);

        // Eigene Rolle und eigenes Konto sind ohnehin geschützt; der Schutz des letzten Owners greift zusätzlich.
        $this->login($owner)->put('/admin/users/'.$owner->getKey(), ['name' => $owner->name, 'role' => 'administrator'])->assertForbidden();
        $this->assertSame(Role::Owner, $owner->fresh()->role);

        // Zweiter Owner vorhanden: Herabstufung des anderen Owners ist zulässig, danach ist der verbliebene Owner der letzte.
        $second = User::factory()->role(Role::Owner)->for($organization)->create();
        $this->login($owner)->put('/admin/users/'.$second->getKey(), ['name' => $second->name, 'role' => 'administrator'])->assertRedirect('/admin/users');
        $this->assertSame(Role::Administrator, $second->fresh()->role);

        // Ein deaktivierter Owner zählt nicht: der letzte aktive Owner bleibt geschützt.
        $this->login($second->fresh())->put('/admin/users/'.$owner->getKey(), ['name' => $owner->name, 'disabled' => '1'])->assertForbidden();
        $this->assertFalse($owner->fresh()->isDisabled());
        $this->assertTrue($disabledOwner->fresh()->isDisabled());
    }
}
