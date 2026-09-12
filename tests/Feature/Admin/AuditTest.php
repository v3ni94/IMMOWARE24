<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Core\Enums\AuditSource;
use App\Core\Enums\Role;
use App\Modules\Connector\Models\Organization;
use App\Modules\Security\DTO\AuditActor;
use App\Modules\Security\Models\AuditLog;
use App\Modules\Security\Models\User;
use App\Modules\Security\Services\AuditLogger;
use App\Modules\Security\Services\LoginService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AuditTest extends TestCase
{
    use RefreshDatabase;

    private function login(User $user): static
    {
        return $this->actingAs($user)->withSession([LoginService::SESSION_TWO_FACTOR_VERIFIED => now()->toIso8601String()]);
    }

    private function seedEntry(User $actor, string $action, string $correlation = 'corr-1'): AuditLog
    {
        /** @var AuditLogger $logger */
        $logger = $this->app->make(AuditLogger::class);

        return $logger->record(
            action: $action,
            entity: $actor,
            before: ['role' => 'operator', 'password' => 'geheim-alt'],
            after: ['role' => 'administrator', 'password' => 'geheim-neu'],
            source: AuditSource::User,
            correlationId: $correlation,
            actor: AuditActor::user((int) $actor->getKey(), (int) $actor->organization_id),
        );
    }

    public function test_index_filters_and_detail_show_masked_diff_for_administrator(): void
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->role(Role::Administrator)->for($organization)->create();
        $entry = $this->seedEntry($admin, 'security.role_changed', 'corr-abc');
        $this->seedEntry($admin, 'connections.paused', 'corr-def');

        $this->login($admin)->get('/admin/audit')->assertOk()->assertSee('security.role_changed')->assertSee('connections.paused')->assertSee('Kette prüfen');
        $this->login($admin)->get('/admin/audit?action=security.')->assertOk()->assertSee('security.role_changed')->assertDontSee('connections.paused');
        $this->login($admin)->get('/admin/audit?correlation_id=corr-def')->assertOk()->assertDontSee('security.role_changed')->assertSee('connections.paused');
        $this->login($admin)->get('/admin/audit?from='.now()->addDay()->format('d.m.Y'))->assertOk()->assertSee('Keine Auditeinträge');
        $this->login($admin)->get('/admin/audit?actor_id='.$admin->getKey().'&source=user&entity_type=User')->assertOk()->assertSee('security.role_changed');

        $detail = $this->login($admin)->get('/admin/audit/'.$entry->getKey())->assertOk();
        $detail->assertSee('Vorher (maskiert)')->assertSee('administrator')->assertSee('***')->assertDontSee('geheim-alt')->assertDontSee('geheim-neu');
        $detail->assertSee('Zeile konsistent mit Vorgänger');
    }

    public function test_read_only_sees_entries_without_before_after(): void
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->role(Role::Administrator)->for($organization)->create();
        $viewer = User::factory()->role(Role::ReadOnly)->for($organization)->create();
        $entry = $this->seedEntry($admin, 'security.role_changed');

        $this->actingAs($viewer)->withSession([LoginService::SESSION_TWO_FACTOR_VERIFIED => now()->toIso8601String()])->get('/admin/audit')->assertOk()->assertSee('security.role_changed');
        $this->actingAs($viewer)->withSession([LoginService::SESSION_TWO_FACTOR_VERIFIED => now()->toIso8601String()])->get('/admin/audit/'.$entry->getKey())->assertOk()->assertDontSee('Vorher (maskiert)')->assertDontSee('administrator');
    }

    public function test_operator_without_audit_permission_is_forbidden(): void
    {
        $user = User::factory()->role(Role::Operator)->create();

        $this->login($user)->get('/admin/audit')->assertForbidden();
    }

    public function test_verify_reports_intact_chain_and_writes_audit_entry(): void
    {
        $admin = User::factory()->role(Role::Administrator)->create();
        $this->seedEntry($admin, 'security.role_changed');

        $this->login($admin)->post('/admin/audit/verify')->assertRedirect('/admin/audit')->assertSessionHas('status');

        $log = AuditLog::query()->where('action', 'admin.audit.chain_verified')->firstOrFail();
        $this->assertTrue((bool) $log->after_json['valid']);

        $this->login($admin)->get('/admin/audit')->assertOk()->assertSee('Kette intakt');
    }

    public function test_verify_detects_manipulated_row(): void
    {
        $admin = User::factory()->role(Role::Administrator)->create();
        $entry = $this->seedEntry($admin, 'security.role_changed');

        DB::table('audit_logs')->where('id', $entry->getKey())->update(['action' => 'manipuliert']);

        $this->login($admin)->post('/admin/audit/verify')->assertRedirect('/admin/audit')->assertSessionHas('error');
        $this->login($admin)->get('/admin/audit')->assertOk()->assertSee('Kette unterbrochen');
    }

    public function test_no_delete_or_update_routes_exist_for_audit(): void
    {
        $admin = User::factory()->role(Role::Owner)->create();
        $entry = $this->seedEntry($admin, 'security.role_changed');

        $this->login($admin)->delete('/admin/audit/'.$entry->getKey())->assertStatus(405);
        $this->login($admin)->put('/admin/audit/'.$entry->getKey(), ['action' => 'x'])->assertStatus(405);
    }
}
