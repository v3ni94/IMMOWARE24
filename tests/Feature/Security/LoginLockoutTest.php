<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Modules\Security\Mail\NewLoginLocationMail;
use App\Modules\Security\Models\AuditLog;
use App\Modules\Security\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class LoginLockoutTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(): User
    {
        return User::factory()->withoutTotp()->create([
            'email' => 'timo@muellerhv.de',
            'password' => Hash::make('sehr-sicheres-passwort'),
        ]);
    }

    public function test_login_page_is_reachable_and_wrong_password_increments_counter(): void
    {
        $user = $this->makeUser();

        $this->get('/login')->assertOk()->assertSee('Anmeldung');

        $this->from('/login')->post('/login', ['email' => 'timo@muellerhv.de', 'password' => 'falsch'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertSame(1, $user->fresh()->failed_login_count);
        $this->assertDatabaseHas('audit_logs', ['action' => 'security.login.failed', 'entity_type' => 'User', 'entity_id' => $user->id]);
    }

    public function test_account_locks_after_five_failed_attempts_and_rejects_correct_password_while_locked(): void
    {
        $user = $this->makeUser();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => 'timo@muellerhv.de', 'password' => 'falsch']);
        }

        $user->refresh();
        $this->assertTrue($user->isLocked());
        $this->assertSame(0, $user->failed_login_count);
        $this->assertDatabaseHas('audit_logs', ['action' => 'security.account.locked', 'entity_id' => $user->id]);

        $this->post('/login', ['email' => 'timo@muellerhv.de', 'password' => 'sehr-sicheres-passwort'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->travel(16)->minutes();

        $this->post('/login', ['email' => 'timo@muellerhv.de', 'password' => 'sehr-sicheres-passwort'])
            ->assertRedirect(route('security.two-factor.setup'));
        $this->assertAuthenticatedAs($user);
        $this->assertNull($user->fresh()->locked_until);
    }

    public function test_successful_login_resets_counter_and_writes_audit_entry(): void
    {
        $user = $this->makeUser();
        $user->forceFill(['failed_login_count' => 3])->save();

        $this->post('/login', ['email' => 'Timo@MuellerHV.de', 'password' => 'sehr-sicheres-passwort'])
            ->assertRedirect();

        $this->assertAuthenticatedAs($user);
        $this->assertSame(0, $user->fresh()->failed_login_count);
        $this->assertNotNull($user->fresh()->last_login_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'security.login.succeeded', 'actor_type' => 'user', 'actor_id' => $user->id]);

        $entry = AuditLog::query()->where('action', 'security.login.succeeded')->firstOrFail();
        $this->assertNotNull($entry->ip_address_hash);
        $this->assertNotNull($entry->correlation_id);
    }

    public function test_disabled_user_cannot_login(): void
    {
        $user = $this->makeUser();
        $user->forceFill(['disabled_at' => now()])->save();

        $this->post('/login', ['email' => 'timo@muellerhv.de', 'password' => 'sehr-sicheres-passwort'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_login_from_new_ip_sends_security_notification(): void
    {
        Mail::fake();
        $user = $this->makeUser();

        $this->post('/login', ['email' => 'timo@muellerhv.de', 'password' => 'sehr-sicheres-passwort'], ['REMOTE_ADDR' => '10.0.0.1']);
        Mail::assertNothingSent();

        $this->post('/logout');

        $this->post('/login', ['email' => 'timo@muellerhv.de', 'password' => 'sehr-sicheres-passwort'], ['REMOTE_ADDR' => '10.0.0.1']);
        Mail::assertNothingSent();

        $this->post('/logout');

        $this->post('/login', ['email' => 'timo@muellerhv.de', 'password' => 'sehr-sicheres-passwort'], ['REMOTE_ADDR' => '203.0.113.9']);

        Mail::assertSent(NewLoginLocationMail::class, function (NewLoginLocationMail $mail) use ($user): bool {
            return $mail->hasTo($user->email) && $mail->maskedIp === '203.0.***.***';
        });

        $this->assertDatabaseCount('user_login_ips', 2);
        $this->assertDatabaseHas('audit_logs', ['action' => 'security.login.new_ip', 'entity_id' => $user->id]);
    }
}
