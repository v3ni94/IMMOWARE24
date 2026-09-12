<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Core\Enums\CapabilityStatus;
use App\Core\Enums\Role;
use App\Modules\Connector\Models\Capability;
use App\Modules\Connector\Models\ImmowareConnection;
use App\Modules\Connector\Models\Organization;
use App\Modules\Security\Models\User;
use App\Modules\Security\Services\LoginService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CapabilitiesTest extends TestCase
{
    use RefreshDatabase;

    private function loginAs(Role $role, Organization $organization): User
    {
        $user = User::factory()->role($role)->for($organization)->create();
        $this->actingAs($user)->withSession([LoginService::SESSION_TWO_FACTOR_VERIFIED => now()->toIso8601String()]);

        return $user;
    }

    public function test_table_shows_status_source_and_activatability(): void
    {
        $organization = Organization::factory()->create();
        $this->loginAs(Role::Administrator, $organization);
        $connection = ImmowareConnection::factory()->for($organization)->create(['name' => 'WebDAV Hauptmandant']);
        $foreign = ImmowareConnection::factory()->create(['name' => 'Fremder Mandant']);

        Capability::factory()->for($connection, 'connection')->key('documents.read')->status(CapabilityStatus::Tested)->create(['enabled' => true]);
        Capability::factory()->for($connection, 'connection')->key('documents.delete')->hardLocked()->create();
        Capability::factory()->for($foreign, 'connection')->key('documents.read')->status(CapabilityStatus::Tested)->create();

        $response = $this->get('/admin/capabilities')->assertOk();
        $response->assertSee('WebDAV Hauptmandant')->assertDontSee('Fremder Mandant');
        $response->assertSee('getestet (Probe)');
        $response->assertSee('hard_locked');
        $response->assertSee('Harte Sperren (nicht aufhebbar)');
        $response->assertSee('IMMOWARE_WRITE_WEBDAV_DELETE_ENABLED');

        $html = (string) $response->getContent();
        $this->assertMatchesRegularExpression('/data-capability="documents\.read".*?<td>freigegeben<\/td>\s*<td>ja<\/td>/s', $html, 'documents.read muss aktivierbar sein');
        $this->assertMatchesRegularExpression('/data-capability="documents\.delete".*?<td>gesperrt<\/td>\s*<td>nein<\/td>/s', $html, 'documents.delete darf nie aktivierbar sein');
        $this->assertMatchesRegularExpression('/data-capability="contacts\.write"[^>]*class="[^"]*is-hard-locked/s', $html, 'hard_locked auch ohne Datenbankzeile');
    }

    public function test_read_only_can_view_and_no_mutation_route_exists(): void
    {
        $organization = Organization::factory()->create();
        $this->loginAs(Role::ReadOnly, $organization);
        ImmowareConnection::factory()->for($organization)->create();

        $this->get('/admin/capabilities')->assertOk()->assertSee('Diese Seite ist lesend');
        $this->post('/admin/capabilities')->assertStatus(405);
    }

    public function test_filter_by_connection(): void
    {
        $organization = Organization::factory()->create();
        $this->loginAs(Role::Operator, $organization);
        $a = ImmowareConnection::factory()->for($organization)->create(['name' => 'Connection Alpha']);
        $b = ImmowareConnection::factory()->for($organization)->carddav()->create(['name' => 'Connection Beta']);

        $this->get('/admin/capabilities?connection_id='.$a->getKey())->assertOk()->assertSee('id="cap-'.$a->getKey().'"', false)->assertDontSee('id="cap-'.$b->getKey().'"', false);
    }
}
