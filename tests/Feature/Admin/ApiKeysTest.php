<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Core\Enums\Role;
use App\Modules\Connector\Models\Organization;
use App\Modules\Security\Models\ApiKey;
use App\Modules\Security\Models\AuditLog;
use App\Modules\Security\Models\User;
use App\Modules\Security\Services\ApiKeyService;
use App\Modules\Security\Services\LoginService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ApiKeysTest extends TestCase
{
    use RefreshDatabase;

    private function login(User $user): static
    {
        return $this->actingAs($user)->withSession([LoginService::SESSION_TWO_FACTOR_VERIFIED => now()->toIso8601String()]);
    }

    public function test_index_lists_keys_with_prefix_scopes_and_rate_limits(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->role(Role::Administrator)->for($organization)->create();
        ApiKey::factory()->for($organization)->create(['name' => 'Telefonanlage', 'prefix' => 'abcdEFGH', 'scopes' => ['directory:read'], 'allowed_ips' => ['10.0.0.0/8']]);
        ApiKey::factory()->create(['name' => 'Fremder Key']);

        $this->login($user)->get('/admin/api')
            ->assertOk()
            ->assertSee('Telefonanlage')
            ->assertSee('hub_live_abcdEFGH_')
            ->assertSee('directory:read')
            ->assertSee('10.0.0.0/8')
            ->assertSee('Rate-Limits')
            ->assertSee(url('/api/docs'))
            ->assertDontSee('Fremder Key');
    }

    public function test_read_only_is_forbidden(): void
    {
        $user = User::factory()->role(Role::ReadOnly)->withoutTotp()->create();

        $this->actingAs($user)->get('/admin/api')->assertForbidden();
        $this->actingAs($user)->post('/admin/api', ['name' => 'x', 'scopes' => ['properties:read'], 'expires_at' => now()->addMonth()->format('Y-m-d')])->assertForbidden();
    }

    public function test_store_reveals_plain_key_once_and_audits(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->role(Role::Administrator)->for($organization)->create();

        $this->login($user)->post('/admin/api', [
            'name' => 'n8n Lesezugriff',
            'scopes' => ['properties:read', 'contacts:read'],
            'expires_at' => now()->addMonths(3)->format('Y-m-d'),
            'allowed_ips' => '192.168.10.5, 10.1.0.0/16',
        ])->assertRedirect('/admin/api');

        $key = ApiKey::query()->where('name', 'n8n Lesezugriff')->firstOrFail();
        $this->assertSame(['properties:read', 'contacts:read'], $key->scopes);
        $this->assertSame(['192.168.10.5', '10.1.0.0/16'], $key->allowed_ips);

        $page = $this->actingAs($user)->withSession([LoginService::SESSION_TWO_FACTOR_VERIFIED => now()->toIso8601String()])->get('/admin/api')->assertOk();
        $page->assertSee('data-key-reveal', false);
        preg_match('/(hub_live_'.preg_quote($key->prefix, '/').'_[A-Za-z0-9_\-]+)/', (string) $page->getContent(), $m);
        $this->assertNotEmpty($m, 'Klartextschlüssel nicht auf der Seite.');
        $this->assertSame($key->key_hash, ApiKeyService::hashKey($m[1]));

        $this->login($user)->get('/admin/api')->assertOk()->assertDontSee($m[1]);

        $log = AuditLog::query()->where('action', 'admin.api_keys.created')->firstOrFail();
        $this->assertSame('ApiKey', $log->entity_type);
    }

    public function test_revoke_requires_confirmation_and_audits(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->role(Role::Owner)->for($organization)->create();
        $key = ApiKey::factory()->for($organization)->create();

        $this->login($user)->post('/admin/api/'.$key->getKey().'/revoke', ['confirmation' => 'falsch'])->assertStatus(422);
        $this->assertNull($key->fresh()->revoked_at);

        $this->login($user)->post('/admin/api/'.$key->getKey().'/revoke', ['confirmation' => 'BESTÄTIGEN', 'reason' => 'Rotation'])->assertRedirect('/admin/api');
        $this->assertNotNull($key->fresh()->revoked_at);
        $this->assertNotNull(AuditLog::query()->where('action', 'admin.api_keys.revoked')->first());
    }
}
