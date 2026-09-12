<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Core\Enums\Role;
use App\Modules\Security\Models\ApiKey;
use App\Modules\Security\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class ConsoleCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_create_command_prompts_for_password(): void
    {
        $organization = $this->createOrganization();

        $this->artisan('hub:user:create', ['email' => 'Neu@MuellerHV.de', '--role' => 'administrator', '--organization' => $organization->id])
            ->expectsQuestion('Passwort (mindestens 12 Zeichen)', 'ein-langes-passwort-123')
            ->assertSuccessful();

        $user = User::query()->where('email', 'neu@muellerhv.de')->firstOrFail();
        $this->assertSame(Role::Administrator, $user->role);
        $this->assertSame($organization->id, $user->organization_id);
        $this->assertTrue(Hash::check('ein-langes-passwort-123', $user->password));
        $this->assertNull($user->totp_confirmed_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'security.user.created', 'entity_id' => $user->id]);
    }

    public function test_user_create_command_rejects_short_password_invalid_role_and_duplicates(): void
    {
        $organization = $this->createOrganization();

        $this->artisan('hub:user:create', ['email' => 'a@muellerhv.de', '--password' => 'kurz', '--organization' => $organization->id])->assertFailed();
        $this->artisan('hub:user:create', ['email' => 'a@muellerhv.de', '--role' => 'api_client', '--password' => 'ein-langes-passwort-123'])->assertFailed();
        $this->artisan('hub:user:create', ['email' => 'a@muellerhv.de', '--password' => 'ein-langes-passwort-123', '--organization' => $organization->id])->assertSuccessful();
        $this->artisan('hub:user:create', ['email' => 'a@muellerhv.de', '--password' => 'ein-langes-passwort-123', '--organization' => $organization->id])->assertFailed();
        $this->assertDatabaseCount('users', 1);
    }

    public function test_api_key_create_command_prints_plaintext_once(): void
    {
        $organization = $this->createOrganization();
        $admin = User::factory()->role(Role::Administrator)->for($organization)->create();

        $this->artisan('hub:api-key:create', [
            'name' => 'n8n Lesezugriff',
            '--scopes' => 'properties:read,units:read',
            '--expires' => now()->addMonths(3)->format('d.m.Y'),
            '--organization' => $organization->id,
            '--created-by' => $admin->email,
            '--allowed-ips' => '10.0.0.0/8',
        ])->assertSuccessful()->expectsOutputToContain('Key: hub_live_');

        $key = ApiKey::query()->firstOrFail();
        $this->assertSame(['properties:read', 'units:read'], $key->scopes);
        $this->assertSame(['10.0.0.0/8'], $key->allowed_ips);
        $this->assertSame($admin->id, $key->created_by);
        $this->assertSame(64, strlen($key->key_hash));
    }

    public function test_api_key_create_command_rejects_unknown_scope(): void
    {
        $organization = $this->createOrganization();

        $this->artisan('hub:api-key:create', ['name' => 'x', '--scopes' => 'contacts:delete', '--organization' => $organization->id])->assertFailed();
        $this->assertDatabaseCount('api_keys', 0);
    }
}
