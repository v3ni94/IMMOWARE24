<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use App\Core\Enums\Role;
use App\Modules\Mail\Http\Middleware\MailSecurityHeaders;
use App\Modules\Security\Models\User;
use App\Modules\Security\Services\LoginService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pfadtrennung auf dem Mail-Host (docs/mail/09 Abschnitt 3): Hub-Admin und /api liefern unter mail.muellerhv.de 404,
 * Anmeldung und Health bleiben erreichbar. Mail-Routen tragen eine Content-Security-Policy.
 */
final class MailHostGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_hub_admin_and_api_are_not_reachable_on_mail_host(): void
    {
        $admin = User::factory()->role(Role::Administrator)->create();
        $this->actingAs($admin)->withSession([LoginService::SESSION_TWO_FACTOR_VERIFIED => now()->toIso8601String()]);

        $this->get('https://mail.muellerhv.de/admin/connections')->assertNotFound();
        $this->get('https://mail.muellerhv.de/admin/users')->assertNotFound();
        $this->get('https://mail.muellerhv.de/api/v1')->assertNotFound();
        $this->get('https://mail.muellerhv.de/api/docs')->assertNotFound();

        // Auf der Hub-Domain unverändert erreichbar.
        $this->get('https://immoware.muellerhv.de/admin/connections')->assertOk();
    }

    public function test_login_and_health_stay_reachable_on_mail_host(): void
    {
        $this->get('https://mail.muellerhv.de/login')->assertOk();
        $this->get('https://mail.muellerhv.de/up')->assertOk();
        $this->assertNotSame(404, $this->get('https://mail.muellerhv.de/health')->getStatusCode());
    }

    public function test_mail_routes_carry_content_security_policy(): void
    {
        $this->actingAsMailRole('agent');

        $this->get('https://mail.muellerhv.de/')
            ->assertOk()
            ->assertHeader('Content-Security-Policy', MailSecurityHeaders::POLICY)
            ->assertHeader('X-Frame-Options', 'DENY');
    }
}
