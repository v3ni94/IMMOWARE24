<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Core\Enums\Role;
use App\Modules\Security\Models\User;
use App\Modules\Security\Services\LoginService;
use App\Modules\Security\Services\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Middleware 2fa.fresh (Äquivalent zu password.confirm): sicherheitskritische Aktionen verlangen eine Bestätigung
 * per Passwort oder TOTP-Code, die höchstens hub.security.totp.fresh_minutes alt ist (08-security.md 3.1).
 */
final class ReauthenticationTest extends TestCase
{
    use RefreshDatabase;

    private function staleVerified(): string
    {
        return now()->subMinutes((int) config('hub.security.totp.fresh_minutes') + 1)->toIso8601String();
    }

    public function test_fresh_window_defaults_to_fifteen_minutes(): void
    {
        $this->assertSame(15, (int) config('hub.security.totp.fresh_minutes'));
    }

    public function test_stale_session_is_redirected_to_confirmation_and_intended_url_is_kept(): void
    {
        $user = User::factory()->role(Role::Administrator)->create();

        $this->actingAs($user)->withSession([LoginService::SESSION_TWO_FACTOR_VERIFIED => $this->staleVerified()])
            ->get('/admin/api/create')->assertOk();

        $response = $this->actingAs($user)->withSession([LoginService::SESSION_TWO_FACTOR_VERIFIED => $this->staleVerified()])
            ->post('/admin/api', ['name' => 'Key', 'scopes' => ['properties:read'], 'expires_at' => now()->addMonth()->format('Y-m-d')]);

        $response->assertRedirect(route('security.confirm.show'));
        $this->assertDatabaseCount('api_keys', 0);

        $this->get('/security/confirm')->assertOk()->assertSee('Erneute Bestätigung')->assertSee('15 Minuten');
    }

    public function test_password_confirmation_unlocks_critical_action_and_is_audited(): void
    {
        $user = User::factory()->role(Role::Administrator)->create();
        $session = [LoginService::SESSION_TWO_FACTOR_VERIFIED => $this->staleVerified()];

        $this->actingAs($user)->withSession($session)->from('/security/confirm')
            ->post('/security/confirm', ['password' => 'falsch'])
            ->assertRedirect('/security/confirm')->assertSessionHasErrors('password');
        $this->assertDatabaseHas('audit_logs', ['action' => 'security.reauthentication.failed', 'entity_id' => $user->getKey()]);
        $this->assertFalse(session()->has(LoginService::SESSION_REAUTHENTICATED_AT));

        $this->post('/security/confirm', ['password' => 'password'])->assertRedirect();
        $this->assertTrue(session()->has(LoginService::SESSION_REAUTHENTICATED_AT));
        $this->assertDatabaseHas('audit_logs', ['action' => 'security.reauthentication.succeeded', 'entity_id' => $user->getKey()]);

        $this->post('/admin/api', ['name' => 'Key', 'scopes' => ['properties:read'], 'expires_at' => now()->addMonth()->format('Y-m-d')])
            ->assertRedirect('/admin/api');
        $this->assertDatabaseCount('api_keys', 1);
    }

    public function test_totp_code_confirmation_unlocks_critical_action(): void
    {
        $totp = $this->app->make(Totp::class);
        $secret = $totp->generateSecret();
        $user = User::factory()->role(Role::Administrator)->create(['totp_secret' => $secret]);

        $this->actingAs($user)->withSession([LoginService::SESSION_TWO_FACTOR_VERIFIED => $this->staleVerified()]);

        $this->post('/security/confirm', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->post('/security/confirm', ['code' => $totp->code($secret)])->assertRedirect();
        $this->assertTrue(session()->has(LoginService::SESSION_REAUTHENTICATED_AT));

        $this->post('/security/confirm', [])->assertSessionHasErrors('password');
    }

    public function test_confirmation_expires_after_window(): void
    {
        $user = User::factory()->role(Role::Administrator)->create();

        $this->actingAs($user)->withSession([
            LoginService::SESSION_TWO_FACTOR_VERIFIED => $this->staleVerified(),
            LoginService::SESSION_REAUTHENTICATED_AT => $this->staleVerified(),
        ])->post('/admin/api', ['name' => 'Key', 'scopes' => ['properties:read'], 'expires_at' => now()->addMonth()->format('Y-m-d')])
            ->assertRedirect(route('security.confirm.show'));
    }

    public function test_critical_routes_require_fresh_confirmation(): void
    {
        $owner = User::factory()->role(Role::Owner)->create();
        $target = User::factory()->role(Role::Operator)->for($owner->organization)->create();
        $connection = $this->createConnection($owner->organization);
        $stale = [LoginService::SESSION_TWO_FACTOR_VERIFIED => $this->staleVerified()];

        $this->actingAs($owner)->withSession($stale)->put('/admin/users/'.$target->getKey(), ['name' => $target->name, 'role' => 'read_only'])->assertRedirect(route('security.confirm.show'));
        $this->actingAs($owner)->withSession($stale)->post('/admin/users/'.$target->getKey().'/reset-two-factor', ['confirmation' => 'BESTÄTIGEN'])->assertRedirect(route('security.confirm.show'));
        $this->actingAs($owner)->withSession($stale)->post('/admin/webhooks', ['name' => 'x', 'url' => 'https://hook.example.test/h', 'events' => ['document.created']])->assertRedirect(route('security.confirm.show'));
        $this->actingAs($owner)->withSession($stale)->put('/admin/connections/'.$connection->getKey(), ['name' => 'x'])->assertRedirect(route('security.confirm.show'));
        $this->actingAs($owner)->withSession($stale)->post('/admin/connections/'.$connection->getKey().'/status', ['transition' => 'activate', 'confirmation' => 'BESTÄTIGEN'])->assertRedirect(route('security.confirm.show'));
        $this->actingAs($owner)->withSession($stale)->get('/admin/connections/'.$connection->getKey())->assertRedirect(route('security.confirm.show'));
        $this->actingAs($owner)->withSession($stale)->get('/admin/system')->assertRedirect(route('security.confirm.show'));

        $this->assertSame(Role::Operator, $target->fresh()->role);
    }

    public function test_absolute_session_lifetime_logs_out_after_limit(): void
    {
        $user = User::factory()->role(Role::Administrator)->create();
        $limit = (int) config('hub.security.sessions.absolute_minutes');
        $this->assertSame(480, $limit);

        $this->actingAs($user)->withSession([
            LoginService::SESSION_TWO_FACTOR_VERIFIED => now()->toIso8601String(),
            LoginService::SESSION_LOGIN_AT => now()->subMinutes($limit - 1)->toIso8601String(),
        ])->get('/security/sessions')->assertOk();

        $this->actingAs($user)->withSession([
            LoginService::SESSION_TWO_FACTOR_VERIFIED => now()->toIso8601String(),
            LoginService::SESSION_LOGIN_AT => now()->subMinutes($limit + 1)->toIso8601String(),
        ])->get('/security/sessions')->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_session_cookie_is_strict_and_secure_outside_local(): void
    {
        $this->assertSame('strict', config('session.same_site'));
        $this->assertTrue((bool) config('session.secure'));
        $this->assertTrue((bool) config('session.http_only'));
        $this->assertNull(config('session.domain'));
    }
}
