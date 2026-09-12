<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Core\Enums\Role;
use App\Modules\Security\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

final class PermissionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_gates_follow_permission_map(): void
    {
        $organization = $this->createOrganization();
        $owner = User::factory()->role(Role::Owner)->for($organization)->create();
        $admin = User::factory()->role(Role::Administrator)->for($organization)->create();
        $operator = User::factory()->role(Role::Operator)->for($organization)->create();
        $viewer = User::factory()->role(Role::ReadOnly)->for($organization)->create();

        $this->assertTrue(Gate::forUser($owner)->allows('users.manage'));
        $this->assertTrue(Gate::forUser($owner)->allows('writes.approve'));
        $this->assertTrue(Gate::forUser($admin)->allows('connections.manage'));
        $this->assertTrue(Gate::forUser($admin)->allows('api_keys.manage'));
        $this->assertFalse(Gate::forUser($admin)->allows('writes.approve'));
        $this->assertTrue(Gate::forUser($operator)->allows('imports.run'));
        $this->assertFalse(Gate::forUser($operator)->allows('sync.run'));
        $this->assertFalse(Gate::forUser($operator)->allows('users.manage'));
        $this->assertTrue(Gate::forUser($viewer)->allows('audit.view'));
        $this->assertTrue(Gate::forUser($viewer)->allows('exports.run'));
        $this->assertFalse(Gate::forUser($viewer)->allows('connections.manage'));
        $this->assertTrue(Gate::forUser($admin)->allows('role', [Role::Administrator, Role::Owner]));
        $this->assertFalse(Gate::forUser($viewer)->allows('role', [Role::Administrator]));
    }

    public function test_locked_or_disabled_users_are_denied_everything(): void
    {
        $owner = User::factory()->role(Role::Owner)->create(['disabled_at' => now()]);
        $this->assertFalse(Gate::forUser($owner)->allows('audit.view'));

        $admin = User::factory()->role(Role::Administrator)->create(['locked_until' => now()->addMinutes(10)]);
        $this->assertFalse(Gate::forUser($admin)->allows('audit.view'));
    }

    public function test_user_policy_restricts_role_assignment(): void
    {
        $organization = $this->createOrganization();
        $owner = User::factory()->role(Role::Owner)->for($organization)->create();
        $admin = User::factory()->role(Role::Administrator)->for($organization)->create();
        $operator = User::factory()->role(Role::Operator)->for($organization)->create();
        $foreign = User::factory()->role(Role::Operator)->create();

        $this->assertTrue(Gate::forUser($owner)->allows('assignRole', [$operator, Role::Administrator]));
        $this->assertTrue(Gate::forUser($admin)->allows('assignRole', [$operator, Role::ReadOnly]));
        $this->assertFalse(Gate::forUser($admin)->allows('assignRole', [$operator, Role::Administrator]));
        $this->assertFalse(Gate::forUser($admin)->allows('assignRole', [$operator, Role::Owner]));
        $this->assertFalse(Gate::forUser($admin)->allows('assignRole', [$admin, Role::Owner]));
        $this->assertFalse(Gate::forUser($admin)->allows('update', $owner));
        $this->assertFalse(Gate::forUser($admin)->allows('update', $foreign));
        $this->assertFalse(Gate::forUser($operator)->allows('viewAny', User::class));
        $this->assertTrue(Gate::forUser($operator)->allows('view', $operator));
        $this->assertFalse(Gate::forUser($admin)->allows('disable', $admin));
        $this->assertTrue(Gate::forUser($admin)->allows('disable', $operator));
    }
}
