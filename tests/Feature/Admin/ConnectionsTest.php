<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Core\Enums\Role;
use App\Modules\Connector\Models\ImmowareConnection;
use App\Modules\Connector\Models\Organization;
use App\Modules\Security\Models\AuditLog;
use App\Modules\Security\Models\User;
use App\Modules\Security\Services\LoginService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class ConnectionsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organization = Organization::factory()->create();
        config()->set('hub.connector.connections.allowed_hosts', ['*.example.test']);
    }

    public function test_store_rejects_base_url_on_private_or_foreign_hosts(): void
    {
        $this->loginAs(Role::Administrator);

        foreach (['https://10.0.0.5/dav', 'https://127.0.0.1/dav', 'https://localhost/dav', 'https://[::1]/dav', 'https://intranet.internal/dav', 'https://dav.fremd.example/share'] as $url) {
            $this->from('/admin/connections/create')->post('/admin/connections', [
                'name' => 'SSRF '.$url,
                'connector_type' => 'webdav_documents',
                'purpose' => 'read',
                'base_url' => $url,
                'poll_interval_seconds' => 300,
                'rate_limit_rps' => 1,
            ])->assertRedirect('/admin/connections/create')->assertSessionHasErrors('base_url');
        }

        $this->assertDatabaseMissing('immoware_connections', ['name' => 'SSRF https://10.0.0.5/dav']);
    }

    private function loginAs(Role $role): User
    {
        $user = User::factory()->role($role)->for($this->organization)->create();
        $this->actingAs($user)->withSession([LoginService::SESSION_TWO_FACTOR_VERIFIED => now()->toIso8601String()]);

        return $user;
    }

    public function test_index_and_show_render_for_administrator(): void
    {
        $this->loginAs(Role::Administrator);
        $connection = ImmowareConnection::factory()->for($this->organization)->create(['name' => 'WebDAV Hauptmandant']);
        $foreign = ImmowareConnection::factory()->create(['name' => 'Fremder Mandant']);

        $this->get('/admin/connections')->assertOk()->assertSee('WebDAV Hauptmandant')->assertDontSee('Fremder Mandant');

        $this->get('/admin/connections/'.$connection->getKey())
            ->assertOk()
            ->assertSee('Immoware-Schnittstelle prüfen')
            ->assertSee('hinterlegt (wird nie angezeigt)')
            ->assertDontSee('test-secret');

        $this->get('/admin/connections/'.$foreign->getKey())->assertNotFound();
    }

    public function test_read_only_is_forbidden(): void
    {
        $this->loginAs(Role::ReadOnly);

        $this->get('/admin/connections')->assertForbidden();
        $this->post('/admin/connections', ['name' => 'x'])->assertForbidden();
    }

    public function test_store_creates_paused_connection_with_audit_entry(): void
    {
        $user = $this->loginAs(Role::Administrator);

        $this->post('/admin/connections', [
            'name' => 'CardDAV Kontakte',
            'connector_type' => 'carddav_contacts',
            'purpose' => 'read',
            'base_url' => 'https://dav.example.test/carddav/share',
            'username' => 'hub-read',
            'password' => 'geheimes-passwort',
            'poll_interval_seconds' => 900,
            'rate_limit_rps' => '1.50',
        ])->assertRedirect();

        $connection = ImmowareConnection::query()->where('name', 'CardDAV Kontakte')->firstOrFail();
        $this->assertSame('paused', $connection->getAttribute('status'));
        $this->assertFalse((bool) $connection->getAttribute('write_enabled'));
        $this->assertSame((int) $this->organization->getKey(), (int) $connection->getAttribute('organization_id'));
        $this->assertSame('geheimes-passwort', $connection->getAttribute('credentials')['password']);
        $this->assertNotNull($connection->getAttribute('base_url_hash'));

        $audit = AuditLog::query()->where('action', 'admin.connections.created')->firstOrFail();
        $this->assertSame((int) $user->getKey(), (int) $audit->getAttribute('actor_id'));
        $this->assertStringNotContainsString('geheimes-passwort', json_encode($audit->getAttribute('after_json'), JSON_THROW_ON_ERROR));

        $this->get('/admin/connections/'.$connection->getKey())->assertOk()->assertDontSee('geheimes-passwort');
    }

    public function test_update_keeps_password_when_left_empty(): void
    {
        $this->loginAs(Role::Administrator);
        $connection = ImmowareConnection::factory()->for($this->organization)->create();

        $this->put('/admin/connections/'.$connection->getKey(), [
            'name' => 'Umbenannt',
            'connector_type' => 'webdav_documents',
            'purpose' => 'read',
            'base_url' => 'https://dav.example.test/neu',
            'username' => 'hub-read',
            'password' => '',
            'poll_interval_seconds' => 1800,
            'rate_limit_rps' => '2',
        ])->assertRedirect();

        $connection->refresh();
        $this->assertSame('Umbenannt', $connection->getAttribute('name'));
        $this->assertSame('test-secret', $connection->getAttribute('credentials')['password']);
        $this->assertSame(1, AuditLog::query()->where('action', 'admin.connections.updated')->count());
    }

    public function test_store_requires_https_url_for_dav(): void
    {
        $this->loginAs(Role::Administrator);

        $this->from('/admin/connections/create')->post('/admin/connections', [
            'name' => 'Ohne URL',
            'connector_type' => 'webdav_documents',
            'purpose' => 'read',
            'poll_interval_seconds' => 1800,
            'rate_limit_rps' => '2',
        ])->assertRedirect('/admin/connections/create')->assertSessionHasErrors('base_url');
    }

    public function test_probe_persists_result_and_shows_check_list(): void
    {
        config(['hub.connector.probe.etag_delay_seconds' => 0]);
        $this->loginAs(Role::Administrator);
        $connection = ImmowareConnection::factory()->for($this->organization)->active()->create(['rate_limit_rps' => 50]);

        Http::fake(function (Request $request) {
            $headers = ['DAV' => '1, 2, 3', 'Server' => 'TestDAV/1.0', 'Content-Type' => 'application/xml; charset=utf-8'];

            if (! $request->hasHeader('Authorization')) {
                return Http::response('', 401, $headers + ['WWW-Authenticate' => 'Basic realm="Immoware24 DAV"']);
            }

            $root = '<D:response><D:href>/share/</D:href><D:propstat><D:prop><D:resourcetype><D:collection/></D:resourcetype><D:getetag>"root"</D:getetag></D:prop><D:status>HTTP/1.1 200 OK</D:status></D:propstat></D:response>';
            $member = '<D:response><D:href>/share/a.pdf</D:href><D:propstat><D:prop><D:resourcetype/><D:getetag>"a1"</D:getetag><D:getlastmodified>Fri, 11 Sep 2026 10:00:00 GMT</D:getlastmodified><D:getcontentlength>100</D:getcontentlength></D:prop><D:status>HTTP/1.1 200 OK</D:status></D:propstat></D:response>';
            $xml = '<?xml version="1.0" encoding="utf-8"?><D:multistatus xmlns:D="DAV:">'.$root.$member.'</D:multistatus>';

            return match ($request->method()) {
                'OPTIONS' => Http::response('', 200, $headers + ['Allow' => 'OPTIONS, GET, HEAD, PROPFIND, REPORT']),
                'PROPFIND' => Http::response($xml, 207, $headers),
                'REPORT' => Http::response('', 405, $headers),
                default => Http::response('', 405, $headers),
            };
        });

        $this->post('/admin/connections/'.$connection->getKey().'/probe')->assertRedirect('/admin/connections/'.$connection->getKey());

        $connection->refresh();
        $this->assertNotNull($connection->getAttribute('last_probe_at'));
        $this->assertTrue($connection->getAttribute('probe_result')['etag_stable']);
        $this->assertFalse($connection->getAttribute('probe_result')['sync_token_supported']);
        $this->assertSame('etag_only', $connection->getAttribute('probe_result')['strategy']);

        $audit = AuditLog::query()->where('action', 'admin.connections.probed')->firstOrFail();
        $this->assertSame('etag_only', $audit->getAttribute('after_json')['strategy']);

        $this->get('/admin/connections/'.$connection->getKey())
            ->assertOk()
            ->assertSee('✓')
            ->assertSee('ETag-Stabilität')
            ->assertSee('etag_only')
            ->assertSee('data-probe-check="sync_collection_report"', false);
    }

    public function test_status_change_requires_confirmation_and_is_audited(): void
    {
        $this->loginAs(Role::Administrator);
        $connection = ImmowareConnection::factory()->for($this->organization)->create(['status' => 'paused']);

        $this->post('/admin/connections/'.$connection->getKey().'/status', ['transition' => 'activate'])->assertStatus(422);
        $this->assertSame('paused', $connection->refresh()->getAttribute('status'));

        $this->post('/admin/connections/'.$connection->getKey().'/status', ['transition' => 'activate', 'confirmation' => 'BESTÄTIGEN'])->assertRedirect();
        $this->assertSame('active', $connection->refresh()->getAttribute('status'));

        $audit = AuditLog::query()->where('action', 'admin.connections.status_changed')->firstOrFail();
        $this->assertSame('paused', $audit->getAttribute('before_json')['status']);
        $this->assertSame('active', $audit->getAttribute('after_json')['status']);
    }

    public function test_only_owner_may_clear_degraded(): void
    {
        $connection = ImmowareConnection::factory()->for($this->organization)->create(['status' => 'degraded', 'degraded_reason' => 'server_fingerprint_changed']);

        $this->loginAs(Role::Administrator);
        $this->post('/admin/connections/'.$connection->getKey().'/status', ['transition' => 'clear_degraded', 'confirmation' => 'BESTÄTIGEN', 'note' => 'geprüft'])->assertForbidden();
        $this->assertSame('degraded', $connection->refresh()->getAttribute('status'));

        $owner = $this->loginAs(Role::Owner);
        $this->post('/admin/connections/'.$connection->getKey().'/status', ['transition' => 'clear_degraded', 'confirmation' => 'BESTÄTIGEN', 'note' => 'geprüft'])->assertRedirect();
        $connection->refresh();
        $this->assertSame('active', $connection->getAttribute('status'));
        $this->assertNull($connection->getAttribute('degraded_reason'));
        $this->assertSame((int) $owner->getKey(), (int) $connection->getAttribute('degraded_cleared_by'));
    }

    public function test_update_of_scope_fields_resets_write_approval(): void
    {
        $this->loginAs(Role::Administrator);
        $requester = User::factory()->role(Role::Administrator)->for($this->organization)->create();
        $confirmer = User::factory()->role(Role::Owner)->for($this->organization)->create();
        $read = ImmowareConnection::factory()->for($this->organization)->create(['connector_type' => 'webdav_documents', 'purpose' => 'read']);
        $connection = ImmowareConnection::factory()->for($this->organization)->create([
            'connector_type' => 'webdav_documents',
            'purpose' => 'write',
            'paired_read_connection_id' => $read->getKey(),
            'base_url' => 'https://dav.example.test/share',
            'allowed_write_prefix' => '/Posteingang/',
            'write_enabled' => true,
            'write_enabled_by' => $requester->getKey(),
            'write_confirmed_by' => $confirmer->getKey(),
            'write_approval_document_id' => 7,
            'write_enabled_at' => now(),
        ]);
        $this->assertNull($connection->writeApprovalIncompleteReason());

        $payload = static fn (array $overrides): array => $overrides + [
            'name' => 'Schreib-Connection',
            'connector_type' => 'webdav_documents',
            'purpose' => 'write',
            'base_url' => 'https://dav.example.test/share',
            'poll_interval_seconds' => 1800,
            'rate_limit_rps' => '2',
            'allowed_write_prefix' => '/Posteingang/',
            'paired_read_connection_id' => $read->getKey(),
        ];

        // Namensänderung lässt die Freigabe bestehen.
        $this->put('/admin/connections/'.$connection->getKey(), $payload(['name' => 'Umbenannt']))->assertRedirect();
        $connection->refresh();
        $this->assertTrue((bool) $connection->getAttribute('write_enabled'));
        $this->assertDatabaseMissing('audit_logs', ['action' => 'admin.connections.write_approval_reset']);

        // Änderung des Schreibpräfixes hebt die Freigabe samt Vier-Augen-Nachweis auf.
        $this->put('/admin/connections/'.$connection->getKey(), $payload(['name' => 'Umbenannt', 'allowed_write_prefix' => '/Posteingang/Hub/']))->assertRedirect();
        $connection->refresh();
        $this->assertFalse((bool) $connection->getAttribute('write_enabled'));
        $this->assertNull($connection->getAttribute('write_enabled_by'));
        $this->assertNull($connection->getAttribute('write_confirmed_by'));
        $this->assertNull($connection->getAttribute('write_approval_document_id'));
        $this->assertNull($connection->getAttribute('write_enabled_at'));

        $reset = AuditLog::query()->where('action', 'admin.connections.write_approval_reset')->firstOrFail();
        $this->assertSame(['allowed_write_prefix'], $reset->getAttribute('after_json')['fields']);
        $updated = AuditLog::query()->where('action', 'admin.connections.updated')->orderByDesc('id')->firstOrFail();
        $this->assertTrue($updated->getAttribute('after_json')['write_approval_reset']);
    }

    public function test_write_prefix_and_purpose_are_validated(): void
    {
        $this->loginAs(Role::Administrator);

        $read = ImmowareConnection::factory()->for($this->organization)->create(['connector_type' => 'webdav_documents', 'purpose' => 'read']);
        $base = [
            'name' => 'Prüfung',
            'connector_type' => 'webdav_documents',
            'purpose' => 'write',
            'base_url' => 'https://dav.example.test/share',
            'poll_interval_seconds' => 1800,
            'rate_limit_rps' => '2',
            'paired_read_connection_id' => $read->getKey(),
        ];

        foreach (['/', '/Dokumente/', '/Dokumente/2026/', 'Posteingang/', '/Posteingang', '/Posteingang/../Dokumente/', ''] as $prefix) {
            $this->from('/admin/connections/create')->post('/admin/connections', $base + ['allowed_write_prefix' => $prefix])
                ->assertRedirect('/admin/connections/create')->assertSessionHasErrors('allowed_write_prefix');
        }

        // Zweck write nur für WebDAV (05-write-capabilities.md 2.2 Nr. 1).
        $this->from('/admin/connections/create')->post('/admin/connections', ['connector_type' => 'carddav_contacts', 'allowed_write_prefix' => '/Posteingang/'] + $base)
            ->assertRedirect('/admin/connections/create')->assertSessionHasErrors('purpose');

        $this->post('/admin/connections', $base + ['allowed_write_prefix' => '/Posteingang/'])->assertRedirect();
        $this->assertDatabaseHas('immoware_connections', ['name' => 'Prüfung', 'allowed_write_prefix' => '/Posteingang/', 'write_enabled' => false, 'paired_read_connection_id' => $read->getKey()]);
    }

    public function test_write_connection_requires_paired_read_connection_of_same_tenant(): void
    {
        $this->loginAs(Role::Administrator);
        $foreignRead = ImmowareConnection::factory()->create(['connector_type' => 'webdav_documents', 'purpose' => 'read']);
        $carddav = ImmowareConnection::factory()->for($this->organization)->create(['connector_type' => 'carddav_contacts', 'purpose' => 'read']);
        $ownWrite = ImmowareConnection::factory()->for($this->organization)->create(['connector_type' => 'webdav_documents', 'purpose' => 'write', 'allowed_write_prefix' => '/Posteingang/']);
        $base = [
            'name' => 'Schreib-Connection',
            'connector_type' => 'webdav_documents',
            'purpose' => 'write',
            'base_url' => 'https://dav.example.test/share',
            'poll_interval_seconds' => 1800,
            'rate_limit_rps' => '2',
            'allowed_write_prefix' => '/Posteingang/',
        ];

        // Ohne, mit fremder, mit falschem Typ oder mit einer Schreib-Connection als Partner: Validierungsfehler.
        foreach ([null, $foreignRead->getKey(), $carddav->getKey(), $ownWrite->getKey(), 999999] as $paired) {
            $this->from('/admin/connections/create')->post('/admin/connections', $base + ['paired_read_connection_id' => $paired])
                ->assertRedirect('/admin/connections/create')->assertSessionHasErrors('paired_read_connection_id');
        }

        // Eine Schreib-Connection darf beim Bearbeiten nicht sich selbst zuordnen.
        $this->from('/admin/connections/'.$ownWrite->getKey().'/edit')->put('/admin/connections/'.$ownWrite->getKey(), $base + ['paired_read_connection_id' => $ownWrite->getKey()])
            ->assertRedirect('/admin/connections/'.$ownWrite->getKey().'/edit')->assertSessionHasErrors('paired_read_connection_id');

        // Lese-Connection darf keinen Partner tragen.
        $this->from('/admin/connections/create')->post('/admin/connections', ['purpose' => 'read', 'paired_read_connection_id' => $ownWrite->getKey()] + $base)
            ->assertRedirect('/admin/connections/create')->assertSessionHasErrors('paired_read_connection_id');

        $this->assertDatabaseMissing('immoware_connections', ['name' => 'Schreib-Connection']);
    }
}
