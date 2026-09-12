<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Modules\Security\Models\ApiKey;
use App\Modules\Security\Services\ApiKeyService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use Tests\TestCase;

final class ApiKeyMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['auth.apikey', 'throttle.apikey:3', 'scope:properties:read'])
            ->get('/_test/properties', static fn (): array => ['data' => 'ok']);

        Route::middleware(['auth.apikey', 'scope:documents:write'])
            ->get('/_test/documents', static fn (): array => ['data' => 'ok']);
    }

    private function createKey(array $scopes = ['properties:read'], ?CarbonImmutable $expires = null, ?array $ips = null): array
    {
        $organization = $this->createOrganization();
        $service = $this->app->make(ApiKeyService::class);

        $created = $service->create($organization->id, 'Test', $scopes, $expires ?? CarbonImmutable::now()->addMonth(), null, $ips);

        return [$created->apiKey, $created->plainTextKey];
    }

    public function test_created_key_is_only_stored_as_hash_and_resolves(): void
    {
        [$apiKey, $plain] = $this->createKey();

        $this->assertStringStartsWith('hub_live_'.$apiKey->prefix.'_', $plain);
        $this->assertSame(hash('sha256', $plain), $apiKey->key_hash);
        $this->assertDatabaseMissing('api_keys', ['key_hash' => $plain]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'security.api_key.created', 'entity_type' => 'ApiKey', 'entity_id' => $apiKey->id]);

        $resolved = $this->app->make(ApiKeyService::class)->resolve($plain);
        $this->assertNotNull($resolved);
        $this->assertTrue($resolved->is($apiKey));
        $this->assertNull($this->app->make(ApiKeyService::class)->resolve('hub_live_'.$apiKey->prefix.'_falsch'));
    }

    public function test_valid_key_with_scope_is_accepted_and_touched(): void
    {
        [$apiKey, $plain] = $this->createKey();

        $this->withHeader('Authorization', 'Bearer '.$plain)->getJson('/_test/properties')
            ->assertOk()
            ->assertJson(['data' => 'ok'])
            ->assertHeader('RateLimit-Limit', '3');

        $this->assertNotNull($apiKey->fresh()->last_used_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'security.api_key.used', 'actor_type' => 'api_key', 'actor_id' => $apiKey->id]);
    }

    public function test_missing_scope_is_rejected_with_403(): void
    {
        [, $plain] = $this->createKey(['properties:read']);

        $this->withHeader('Authorization', 'Bearer '.$plain)->getJson('/_test/documents')
            ->assertStatus(403)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJson(['code' => 'insufficient_scope', 'status' => 403]);
    }

    public function test_admin_scope_covers_all_scopes(): void
    {
        [, $plain] = $this->createKey(['admin']);

        $this->withHeader('Authorization', 'Bearer '.$plain)->getJson('/_test/documents')->assertOk();
    }

    public function test_expired_key_is_rejected_with_401(): void
    {
        [$apiKey, $plain] = $this->createKey();
        $apiKey->forceFill(['expires_at' => CarbonImmutable::now()->subMinute()])->save();

        $this->withHeader('Authorization', 'Bearer '.$plain)->getJson('/_test/properties')
            ->assertStatus(401)
            ->assertJson(['code' => 'key_expired']);
    }

    public function test_revoked_and_missing_keys_are_rejected(): void
    {
        [$apiKey, $plain] = $this->createKey();
        $this->app->make(ApiKeyService::class)->revoke($apiKey);

        $this->withHeader('Authorization', 'Bearer '.$plain)->getJson('/_test/properties')
            ->assertStatus(401)
            ->assertJson(['code' => 'key_revoked']);

        $this->flushHeaders()->getJson('/_test/properties')->assertStatus(401)->assertJson(['code' => 'unauthenticated']);
        $this->withHeader('Authorization', 'Bearer hub_live_abcdefgh_ungueltig')->getJson('/_test/properties')->assertStatus(401);
        $this->assertDatabaseHas('audit_logs', ['action' => 'security.api_key.revoked', 'entity_id' => $apiKey->id]);
    }

    public function test_ip_allowlist_is_enforced(): void
    {
        [, $plain] = $this->createKey(['properties:read'], null, ['10.0.0.0/8']);

        $this->withHeader('Authorization', 'Bearer '.$plain)->getJson('/_test/properties', ['REMOTE_ADDR' => '10.1.2.3'])->assertOk();
        $this->withHeader('Authorization', 'Bearer '.$plain)->getJson('/_test/properties', ['REMOTE_ADDR' => '203.0.113.1'])
            ->assertStatus(403)
            ->assertJson(['code' => 'ip_not_allowed']);
    }

    public function test_rate_limit_per_key_returns_429_with_retry_after(): void
    {
        [, $plain] = $this->createKey();

        for ($i = 0; $i < 3; $i++) {
            $this->withHeader('Authorization', 'Bearer '.$plain)->getJson('/_test/properties')->assertOk();
        }

        $this->withHeader('Authorization', 'Bearer '.$plain)->getJson('/_test/properties')
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJson(['code' => 'rate_limited']);
    }

    public function test_unknown_scope_and_too_long_lifetime_are_rejected_on_creation(): void
    {
        $organization = $this->createOrganization();
        $service = $this->app->make(ApiKeyService::class);

        try {
            $service->create($organization->id, 'x', ['contacts:delete'], CarbonImmutable::now()->addMonth());
            $this->fail('Unbekannter Scope hätte abgewiesen werden müssen.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('contacts:delete', $e->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $service->create($organization->id, 'x', ['properties:read'], CarbonImmutable::now()->addMonths(13));
    }

    public function test_factory_key_can_be_used_as_bearer(): void
    {
        $plain = 'hub_live_factory1_'.str_repeat('a', 43);
        ApiKey::factory()->withPlainKey($plain)->scopes(['properties:read'])->create();

        $this->withHeader('Authorization', 'Bearer '.$plain)->getJson('/_test/properties')->assertOk();
    }
}
