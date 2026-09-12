<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Core\Enums\Role;
use App\Modules\Security\Models\User;
use App\Modules\Security\Services\LoginService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SystemTest extends TestCase
{
    use RefreshDatabase;

    private function login(User $user): static
    {
        return $this->actingAs($user)->withSession([LoginService::SESSION_TWO_FACTOR_VERIFIED => now()->toIso8601String()]);
    }

    public function test_system_page_shows_doctor_flags_versions_and_health(): void
    {
        $user = User::factory()->role(Role::Administrator)->create();

        $response = $this->login($user)->get('/admin/system')->assertOk();
        $response->assertSee('hub:doctor')
            ->assertSee('data-doctor="database"', false)
            ->assertSee('data-doctor="boot_guard"', false)
            ->assertSee('data-flag="write.webdav_delete_enabled"', false)
            ->assertSee('IMMOWARE_WRITE_WEBDAV_CREATE_ENABLED')
            ->assertSee('immoware_connector_version')
            ->assertSee((string) config('hub.connector.version'))
            ->assertSee('api_version')
            ->assertSee('Health-Übersicht')
            ->assertSee('Zeitpläne')
            ->assertSee((string) config('hub.sync.schedule.full'));

        $html = (string) $response->getContent();
        $this->assertMatchesRegularExpression('/data-flag="write\.enabled">.*?hub-badge-ok/s', $html);
    }

    public function test_active_write_flag_is_marked_as_warning(): void
    {
        config()->set('hub.core.write.enabled', true);
        config()->set('hub.core.write.webdav_create_enabled', true);
        $user = User::factory()->role(Role::Owner)->create();

        $html = (string) $this->login($user)->get('/admin/system')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/data-flag="write\.enabled">.*?Schreibpfad aktiv/s', $html);
        $this->assertMatchesRegularExpression('/data-flag="write\.webdav_create_enabled">.*?hub-badge-warn/s', $html);
    }

    public function test_operator_and_read_only_are_forbidden(): void
    {
        $this->login(User::factory()->role(Role::Operator)->create())->get('/admin/system')->assertForbidden();
        $this->login(User::factory()->role(Role::ReadOnly)->create())->get('/admin/system')->assertForbidden();
        // Ohne eingerichtete 2FA gibt es keine Admin-Seite, auch keine 403-Antwort mit Inhalt (Einrichtung zuerst).
        $this->actingAs(User::factory()->role(Role::ReadOnly)->withoutTotp()->create())->get('/admin/system')->assertRedirect(route('security.two-factor.setup'));
    }

    public function test_write_flag_page_requires_fresh_reauthentication(): void
    {
        $user = User::factory()->role(Role::Owner)->create();
        $stale = now()->subMinutes((int) config('hub.security.totp.fresh_minutes') + 1)->toIso8601String();

        $this->actingAs($user)->withSession([LoginService::SESSION_TWO_FACTOR_VERIFIED => $stale])
            ->get('/admin/system')->assertRedirect(route('security.confirm.show'));

        $this->actingAs($user)->withSession([LoginService::SESSION_TWO_FACTOR_VERIFIED => $stale, LoginService::SESSION_REAUTHENTICATED_AT => now()->toIso8601String()])
            ->get('/admin/system')->assertOk();
    }
}
