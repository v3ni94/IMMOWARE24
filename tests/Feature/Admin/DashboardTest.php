<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Core\Enums\Role;
use App\Modules\Connector\Models\ImmowareConnection;
use App\Modules\Connector\Models\Organization;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Documents\Models\Document;
use App\Modules\Estate\Models\Contract;
use App\Modules\Estate\Models\Property;
use App\Modules\Estate\Models\Unit;
use App\Modules\Security\Models\User;
use App\Modules\Security\Services\LoginService;
use App\Modules\Sync\Enums\DlqStatus;
use App\Modules\Sync\Models\Conflict;
use App\Modules\Sync\Models\DlqItem;
use App\Modules\Sync\Models\SyncRun;
use App\Modules\Sync\Models\SyncState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_renders_for_administrator_with_confirmed_two_factor(): void
    {
        $user = User::factory()->role(Role::Administrator)->create();

        $this->actingAs($user)
            ->withSession([LoginService::SESSION_TWO_FACTOR_VERIFIED => now()->toIso8601String()])
            ->get('/admin')
            ->assertOk()
            ->assertSee('Immoware Hub')
            ->assertSee('Dashboard')
            ->assertSee('Immoware-Verbindung')
            ->assertSee('Discovery-Konsole')
            ->assertSee($user->name)
            ->assertSee('Abmelden');
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/admin')->assertRedirect('/login');
    }

    public function test_administrator_without_two_factor_session_is_redirected_to_challenge(): void
    {
        $user = User::factory()->role(Role::Administrator)->create();

        $this->actingAs($user)->get('/admin')->assertRedirect('/two-factor/challenge');
    }

    public function test_api_client_role_is_forbidden(): void
    {
        $user = User::factory()->role(Role::ApiClient)->withoutTotp()->create();

        $this->actingAs($user)
            ->withSession([LoginService::SESSION_TWO_FACTOR_VERIFIED => now()->toIso8601String()])
            ->get('/admin')
            ->assertForbidden();
    }

    public function test_read_only_role_without_totp_is_admitted_and_sees_reduced_navigation(): void
    {
        $user = User::factory()->role(Role::ReadOnly)->withoutTotp()->create();

        $this->actingAs($user)
            ->get('/admin')
            ->assertOk()
            ->assertSee('Auditlog')
            ->assertDontSee('Benutzer</')
            ->assertDontSee('Discovery-Konsole');
    }

    public function test_counters_match_factory_data_of_own_organization(): void
    {
        $organization = Organization::factory()->create();
        $foreign = Organization::factory()->create();
        $user = User::factory()->role(Role::Administrator)->for($organization)->create();

        $connection = ImmowareConnection::factory()->for($organization)->active()->create(['name' => 'WebDAV Hauptmandant']);
        $foreignConnection = ImmowareConnection::factory()->for($foreign)->create();

        $properties = Property::factory()->count(3)->for($organization)->create();
        Property::factory()->count(2)->for($foreign)->create();

        $units = collect();
        foreach ($properties as $property) {
            $units = $units->merge(Unit::factory()->count(2)->for($property)->create());
        }

        // Ein soft-deleted Objekt darf nicht mitgezählt werden.
        $deleted = Property::factory()->for($organization)->create();
        $deleted->delete();

        Contact::factory()->count(4)->for($organization)->create();
        Document::factory()->count(5)->for($connection, 'connection')->create();
        Document::factory()->count(2)->for($foreignConnection, 'connection')->create();

        foreach ($units->take(2) as $unit) {
            Contract::query()->create([
                'unit_id' => $unit->getKey(),
                'organization_id' => $organization->getKey(),
                'contract_number' => 'MV-'.$unit->getKey(),
                'status' => 'active',
                'source_system' => 'immoware24',
                'external_id' => 'contract-'.$unit->getKey(),
                'first_synced_at' => now(),
                'last_synced_at' => now(),
            ]);
        }

        DlqItem::query()->create([
            'job_class' => 'App\\Modules\\Sync\\Jobs\\RunSyncJob',
            'connection_id' => $connection->getKey(),
            'queue' => 'sync',
            'payload_json' => ['job' => 'x'],
            'exception' => 'RuntimeException: Test',
            'failed_at' => now(),
            'status' => DlqStatus::Open,
        ]);
        DlqItem::query()->create([
            'job_class' => 'App\\Modules\\Sync\\Jobs\\RunSyncJob',
            'connection_id' => $connection->getKey(),
            'queue' => 'sync',
            'payload_json' => ['job' => 'y'],
            'exception' => 'RuntimeException: erledigt',
            'failed_at' => now(),
            'replayed_at' => now(),
            'status' => DlqStatus::Replayed,
        ]);
        DlqItem::query()->create([
            'job_class' => 'App\\Modules\\Sync\\Jobs\\RunSyncJob',
            'connection_id' => $foreignConnection->getKey(),
            'queue' => 'sync',
            'payload_json' => ['job' => 'z'],
            'exception' => 'RuntimeException: fremd',
            'failed_at' => now(),
            'status' => DlqStatus::Open,
        ]);

        Conflict::query()->create([
            'connection_id' => $connection->getKey(),
            'entity_type' => 'contact',
            'entity_id' => 1,
            'conflict_type' => 'field_mismatch',
            'status' => 'open',
        ]);
        Conflict::query()->create([
            'connection_id' => $connection->getKey(),
            'entity_type' => 'contact',
            'entity_id' => 2,
            'conflict_type' => 'field_mismatch',
            'status' => 'resolved',
        ]);

        SyncState::query()->create([
            'connection_id' => $connection->getKey(),
            'entity_type' => 'document',
            'scope' => SyncState::SCOPE_COLLECTION,
            'collection_path_hash' => hash('sha256', 'collection:document'),
            'last_success_at' => now()->subHours(3),
        ]);

        SyncRun::query()->create([
            'connection_id' => $connection->getKey(),
            'run_type' => SyncRun::TYPE_INCREMENTAL,
            'entity_type' => 'document',
            'status' => 'failed',
            'phase' => SyncRun::PHASE_FAILED,
            'started_at' => now()->subMinutes(10),
            'finished_at' => now()->subMinutes(9),
            'error_summary' => 'PROPFIND 503 Service Unavailable',
            'counters' => [],
        ]);

        $response = $this->actingAs($user)
            ->withSession([LoginService::SESSION_TWO_FACTOR_VERIFIED => now()->toIso8601String()])
            ->get('/admin')
            ->assertOk();

        $response->assertSee('Zuletzt fehlgeschlagene Läufe');
        $response->assertSee('PROPFIND 503 Service Unavailable');

        $this->assertSame('3', $this->counter($response->getContent(), 'properties'));
        $this->assertSame('6', $this->counter($response->getContent(), 'units'));
        $this->assertSame('4', $this->counter($response->getContent(), 'contacts'));
        $this->assertSame('2', $this->counter($response->getContent(), 'contracts'));
        $this->assertSame('5', $this->counter($response->getContent(), 'documents'));
        $this->assertSame('1', $this->counter($response->getContent(), 'dlq_open'));
        $this->assertSame('1', $this->counter($response->getContent(), 'conflicts_open'));

        $response->assertSee('WebDAV Hauptmandant');
        $response->assertDontSee($foreignConnection->getAttribute('name'));
        // Dokumente sind nach 3 Stunden (Schwelle 2 Stunden) veraltet.
        $response->assertSee('gelten als veraltet');
    }

    public function test_every_navigation_link_points_to_an_existing_route_and_renders(): void
    {
        $user = User::factory()->role(Role::Owner)->create();

        $response = $this->actingAs($user)
            ->withSession([LoginService::SESSION_TWO_FACTOR_VERIFIED => now()->toIso8601String()])
            ->get('/admin')
            ->assertOk();

        $response->assertSee('href="'.url('/admin').'"', false);
        $response->assertDontSee('in Aufbau');

        $navigation = (array) config('hub.admin.navigation', []);
        $this->assertCount(17, $navigation);

        foreach ($navigation as $entry) {
            $route = (string) $entry['route'];
            $this->assertTrue(Route::has($route), 'Navigationsroute fehlt: '.$route);

            $url = route($route, [], false);
            $response->assertSee('href="'.url($url).'"', false);

            $this->actingAs($user)
                ->withSession([LoginService::SESSION_TWO_FACTOR_VERIFIED => now()->toIso8601String()])
                ->get($url)
                ->assertOk();
        }
    }

    private function counter(string|false $html, string $key): string
    {
        $this->assertIsString($html);
        $pattern = '/data-counter="'.preg_quote($key, '/').'"[^>]*>.*?<div class="hub-stat-value">\s*(.*?)\s*<\/div>/s';
        $this->assertMatchesRegularExpression($pattern, $html, 'Zähler '.$key.' nicht gefunden.');
        preg_match($pattern, $html, $m);

        return trim(strip_tags($m[1]));
    }
}
