<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Core\Enums\Role;
use App\Modules\Security\Models\User;
use App\Modules\Security\Services\LoginService;
use App\Modules\Security\Services\Totp;
use App\Modules\Security\Services\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    public function test_setup_confirm_and_challenge_flow(): void
    {
        $user = User::factory()->withoutTotp()->role(Role::Administrator)->create();
        $totp = $this->app->make(Totp::class);

        $this->actingAs($user);

        $this->get('/security/sessions')->assertRedirect(route('security.two-factor.setup'));

        $this->get('/security/two-factor')->assertOk()->assertSee('otpauth://totp/');
        $user->refresh();
        $secret = $user->totp_secret;
        $this->assertIsString($secret);

        $this->post('/security/two-factor/confirm', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertNull($user->fresh()->totp_confirmed_at);

        $response = $this->post('/security/two-factor/confirm', ['code' => $totp->code($secret)]);
        $response->assertOk()->assertSee('Wiederherstellungscodes');
        $this->assertNotNull($user->fresh()->totp_confirmed_at);
        $this->assertCount(10, $user->fresh()->recovery_codes);
        $this->assertDatabaseHas('audit_logs', ['action' => 'security.two_factor.enabled', 'entity_id' => $user->id]);

        // Neue Sitzung ohne 2FA-Bestätigung: Admin-Route verlangt Code.
        $this->flushSession();
        $this->actingAs($user->fresh());
        $this->get('/security/sessions')->assertRedirect(route('security.two-factor.challenge'));

        $this->post('/two-factor/challenge', ['code' => '123456'])->assertSessionHasErrors('code');
        $this->post('/two-factor/challenge', ['code' => $totp->code($secret)])->assertRedirect(route('security.sessions.index'));
        $this->assertTrue(session()->has(LoginService::SESSION_TWO_FACTOR_VERIFIED));
        $this->get('/security/sessions')->assertOk();
    }

    public function test_recovery_code_can_only_be_used_once(): void
    {
        $user = User::factory()->withoutTotp()->role(Role::Operator)->create();
        $service = $this->app->make(TwoFactorService::class);
        $totp = $this->app->make(Totp::class);

        $secret = $service->beginSetup($user);
        $codes = $service->confirmSetup($user, $totp->code($secret));
        $this->assertNotNull($codes);
        $this->assertCount(10, $codes);

        $this->actingAs($user->fresh());

        $this->post('/two-factor/challenge', ['recovery_code' => $codes[0]])->assertRedirect(route('security.sessions.index'));
        $this->assertSame(9, $service->remainingRecoveryCodes($user->fresh()));
        $this->assertDatabaseHas('audit_logs', ['action' => 'security.two_factor.recovery_code_used', 'entity_id' => $user->id]);

        $this->flushSession();
        $this->actingAs($user->fresh());

        $this->post('/two-factor/challenge', ['recovery_code' => $codes[0]])->assertSessionHasErrors('code');
        $this->assertSame(9, $service->remainingRecoveryCodes($user->fresh()));

        $this->post('/two-factor/challenge', ['recovery_code' => strtolower($codes[1])])->assertRedirect();
        $this->assertSame(8, $service->remainingRecoveryCodes($user->fresh()));
    }

    public function test_read_only_role_is_exempt_from_two_factor_requirement(): void
    {
        $user = User::factory()->withoutTotp()->role(Role::ReadOnly)->create();

        $this->actingAs($user);

        $this->get('/security/sessions')->assertOk();
    }

    public function test_totp_secret_and_recovery_codes_are_stored_encrypted_and_hidden(): void
    {
        $user = User::factory()->withoutTotp()->create();
        $service = $this->app->make(TwoFactorService::class);
        $totp = $this->app->make(Totp::class);

        $secret = $service->beginSetup($user);
        $codes = $service->confirmSetup($user, $totp->code($secret));

        $raw = $user->fresh()->getRawOriginal('totp_secret');
        $this->assertNotSame($secret, $raw);
        $this->assertStringNotContainsString($secret, (string) $raw);
        $this->assertArrayNotHasKey('totp_secret', $user->fresh()->toArray());
        $this->assertArrayNotHasKey('recovery_codes', $user->fresh()->toArray());

        foreach ((array) $codes as $code) {
            $this->assertNotContains($code, $user->fresh()->recovery_codes);
        }
    }
}
