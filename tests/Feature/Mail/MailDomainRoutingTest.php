<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use App\Core\Enums\Role;
use App\Modules\Security\Models\User;
use App\Modules\Security\Services\LoginService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class MailDomainRoutingTest extends TestCase
{
    use RefreshDatabase;

    public function test_mail_routes_are_bound_to_the_mail_domain(): void
    {
        $route = Route::getRoutes()->getByName('mail.dashboard');

        $this->assertNotNull($route);
        $this->assertSame('mail.muellerhv.de', $route->getDomain());
        $this->assertContains('mail', $route->middleware());
    }

    public function test_request_to_mail_domain_hits_mail_dashboard(): void
    {
        $this->actingAsMailRole('agent');

        $this->get('https://mail.muellerhv.de/')
            ->assertOk()
            ->assertSee('Mail und Vorgänge')
            ->assertSee('Nicht eingerichtet')
            ->assertSee('Übersicht');
    }

    public function test_request_to_immoware_domain_does_not_hit_mail_routes(): void
    {
        $this->actingAsMailRole('agent');

        $response = $this->get('https://immoware.muellerhv.de/');

        $this->assertNotSame('mail.dashboard', $response->baseRequest->route()?->getName());
        $response->assertDontSee('Nicht eingerichtet');
    }

    public function test_admin_routes_still_work_on_immoware_domain(): void
    {
        $user = User::factory()->role(Role::Administrator)->create();

        $this->actingAs($user)
            ->withSession([LoginService::SESSION_TWO_FACTOR_VERIFIED => now()->toIso8601String()])
            ->get('https://immoware.muellerhv.de/admin')
            ->assertOk()
            ->assertSee('Immoware Hub');
    }

    public function test_guest_on_mail_domain_is_redirected_to_login(): void
    {
        $this->get('https://mail.muellerhv.de/')->assertRedirect('https://mail.muellerhv.de/login');
    }

    public function test_api_client_is_forbidden_on_mail_domain(): void
    {
        $user = User::factory()->role(Role::ApiClient)->withoutTotp()->create();

        $this->actingAs($user)->get('https://mail.muellerhv.de/')->assertForbidden();
    }

    public function test_user_without_two_factor_session_is_redirected_to_challenge(): void
    {
        $user = User::factory()->role(Role::Operator)->create();

        $this->actingAs($user)->get('https://mail.muellerhv.de/')->assertRedirect('https://mail.muellerhv.de/two-factor/challenge');
    }

    public function test_session_cookie_is_host_bound_without_domain(): void
    {
        $this->assertNull(config('session.domain'), 'SESSION_DOMAIN muss leer bleiben, damit das Cookie hostgebunden ist.');

        $this->actingAsMailRole('agent');
        $response = $this->get('https://mail.muellerhv.de/');
        $response->assertOk();

        $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === config('session.cookie'));

        $this->assertNotNull($cookie, 'Session-Cookie fehlt.');
        $this->assertNull($cookie->getDomain());
    }

    public function test_mail_middleware_groups_are_registered(): void
    {
        $groups = Route::getMiddlewareGroups();

        $this->assertSame(['web', 'auth', 'mail.domain', 'mail.headers', 'mail.access', '2fa'], $groups['mail']);
        $this->assertSame(['mail', '2fa.fresh'], $groups['mail.fresh']);
        $this->assertContains('mail.domain', $groups['mail.push']);
        $this->assertSame(['web', 'auth', 'admin.access', '2fa'], $groups['admin'], 'Gruppe admin darf unverändert bleiben.');
    }
}
