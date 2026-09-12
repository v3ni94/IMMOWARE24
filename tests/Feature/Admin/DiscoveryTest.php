<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Core\Enums\Role;
use App\Modules\Connector\Models\ImmowareConnection;
use App\Modules\Connector\Models\Organization;
use App\Modules\Connector\Models\RemoteRequest;
use App\Modules\Security\Models\User;
use App\Modules\Security\Services\LoginService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DiscoveryTest extends TestCase
{
    use RefreshDatabase;

    private function login(User $user): static
    {
        return $this->actingAs($user)->withSession([LoginService::SESSION_TWO_FACTOR_VERIFIED => now()->toIso8601String()]);
    }

    public function test_developer_sees_filtered_list_and_masked_detail(): void
    {
        $organization = Organization::factory()->create();
        $developer = User::factory()->role(Role::Developer)->for($organization)->create();
        $connection = ImmowareConnection::factory()->for($organization)->create(['name' => 'WebDAV HVM']);
        $foreignConnection = ImmowareConnection::factory()->create();

        $entry = RemoteRequest::factory()->for($connection, 'connection')->create([
            'path' => 'https://dav.example.test/share/Posteingang/Rechnung.pdf',
            'request_headers_masked' => ['Depth' => '1', 'Authorization' => '***'],
            'response_headers' => ['Content-Type' => 'application/xml', 'Set-Cookie' => 'sid=abc123geheim'],
            'response_schema_fingerprint' => str_repeat('f', 64),
            'duration_ms' => 345,
            'response_bytes' => 2048,
        ]);
        RemoteRequest::factory()->for($connection, 'connection')->create(['method' => 'GET', 'path' => 'https://dav.example.test/share/Dokumente/x.pdf', 'outcome' => 'not_found', 'response_status' => 404]);
        RemoteRequest::factory()->for($foreignConnection, 'connection')->create(['path' => 'https://dav.example.test/fremd/']);

        $list = $this->login($developer)->get('/admin/discovery')->assertOk();
        $list->assertSee('Produktivdaten')->assertSee('PROPFIND')->assertSee('Posteingang')->assertSee('345 ms')->assertSee('ffffffffffff')->assertDontSee('/fremd/');

        $this->login($developer)->get('/admin/discovery?method=GET')->assertOk()->assertDontSee('Posteingang')->assertSee('Dokumente');
        $this->login($developer)->get('/admin/discovery?outcome=not_found&status=404')->assertOk()->assertSee('Dokumente')->assertDontSee('Posteingang');
        $this->login($developer)->get('/admin/discovery?connection='.$connection->getKey().'&path=Rechnung')->assertOk()->assertSee('Rechnung.pdf');

        $detail = $this->login($developer)->get('/admin/discovery/'.$entry->getKey())->assertOk();
        $detail->assertSee('Request-Header (maskiert)')->assertSee('Depth')->assertSee('***')->assertDontSee('abc123geheim')->assertSee(str_repeat('f', 64));
    }

    public function test_operator_and_read_only_are_forbidden(): void
    {
        $this->login(User::factory()->role(Role::Operator)->create())->get('/admin/discovery')->assertForbidden();
        $this->login(User::factory()->role(Role::ReadOnly)->create())->get('/admin/discovery')->assertForbidden();
        $this->login(User::factory()->role(Role::Owner)->create())->get('/admin/discovery')->assertOk();
        $this->login(User::factory()->role(Role::Administrator)->create())->get('/admin/discovery')->assertOk();
    }

    public function test_foreign_request_is_not_found(): void
    {
        $developer = User::factory()->role(Role::Developer)->create();
        $foreign = RemoteRequest::factory()->create();

        $this->login($developer)->get('/admin/discovery/'.$foreign->getKey())->assertNotFound();
    }
}
