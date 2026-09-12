<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Core\Enums\Role;
use App\Modules\Security\Models\User;
use App\Modules\Security\Services\SessionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SessionManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_sessions_are_listed_and_others_can_be_terminated(): void
    {
        $user = User::factory()->role(Role::ReadOnly)->create();
        $other = User::factory()->create();

        DB::table('sessions')->insert([
            ['id' => 'current-session', 'user_id' => $user->id, 'ip_address' => '10.0.0.1', 'user_agent' => 'Firefox', 'payload' => '', 'last_activity' => time()],
            ['id' => 'other-session', 'user_id' => $user->id, 'ip_address' => '10.0.0.2', 'user_agent' => 'Safari', 'payload' => '', 'last_activity' => time() - 60],
            ['id' => 'stale-session', 'user_id' => $user->id, 'ip_address' => '10.0.0.3', 'user_agent' => 'Alt', 'payload' => '', 'last_activity' => time() - 100000],
            ['id' => 'foreign-session', 'user_id' => $other->id, 'ip_address' => '10.0.0.4', 'user_agent' => 'Chrome', 'payload' => '', 'last_activity' => time()],
        ]);

        $manager = $this->app->make(SessionManager::class);
        $sessions = $manager->activeSessions($user, 'current-session');

        $this->assertCount(2, $sessions);
        $this->assertTrue($sessions->firstWhere('id', 'current-session')['is_current']);
        $this->assertFalse($sessions->firstWhere('id', 'other-session')['is_current']);

        $terminated = $manager->logoutOtherSessions($user, 'current-session');

        $this->assertSame(2, $terminated);
        $this->assertDatabaseHas('sessions', ['id' => 'current-session']);
        $this->assertDatabaseHas('sessions', ['id' => 'foreign-session']);
        $this->assertDatabaseMissing('sessions', ['id' => 'other-session']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'security.sessions.others_terminated', 'entity_id' => $user->id]);
    }

    public function test_sessions_page_renders_for_authenticated_user(): void
    {
        $user = User::factory()->withoutTotp()->role(Role::ReadOnly)->create();

        $this->actingAs($user)->get('/security/sessions')->assertOk()->assertSee('Aktive Sitzungen');
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/security/sessions')->assertRedirect('/login');
    }
}
