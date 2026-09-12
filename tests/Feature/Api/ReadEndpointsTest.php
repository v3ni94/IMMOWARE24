<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Modules\Estate\Models\Property;
use App\Modules\Estate\Models\Unit;
use Carbon\CarbonImmutable;

final class ReadEndpointsTest extends ApiTestCase
{
    public function test_request_without_key_is_rejected_with_401_problem_json(): void
    {
        $this->getJson('/api/v1/properties')
            ->assertStatus(401)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJson(['status' => 401, 'code' => 'unauthenticated']);
    }

    public function test_request_without_scope_is_rejected_with_403(): void
    {
        $this->issueKey(['units:read']);

        $this->getJson('/api/v1/properties', $this->authHeaders())
            ->assertStatus(403)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJson(['code' => 'insufficient_scope']);
    }

    public function test_list_returns_pagination_meta_links_and_provenance(): void
    {
        $this->issueKey(['properties:read']);
        Property::factory()->count(7)->for($this->organization)->create();
        Property::factory()->count(2)->create(); // anderer Mandant

        $response = $this->getJson('/api/v1/properties?per_page=5&page=2', $this->authHeaders());

        $response->assertOk()
            ->assertJsonPath('meta.page', 2)
            ->assertJsonPath('meta.per_page', 5)
            ->assertJsonPath('meta.total', 7)
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure(['links' => ['self', 'first', 'last', 'next', 'prev']])
            ->assertJsonStructure(['data' => [['id', 'name', 'provenance' => ['source_system', 'external_id', 'last_synced_at', 'connector', 'mapping_version', 'data_age_seconds', 'stale']]]]);

        $this->assertNull($response->json('links.next'));
        $this->assertSame('immoware24', $response->json('data.0.provenance.source_system'));
        $this->assertFalse($response->json('data.0.provenance.stale'));
    }

    public function test_per_page_is_capped_at_500(): void
    {
        $this->issueKey(['properties:read']);

        $this->getJson('/api/v1/properties?per_page=5000', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.per_page', 500);
    }

    public function test_updated_since_filter_limits_results(): void
    {
        $this->issueKey(['properties:read']);

        $old = Property::factory()->for($this->organization)->create();
        Property::query()->whereKey($old->getKey())->update(['updated_at' => CarbonImmutable::now()->subDays(10)]);
        $recent = Property::factory()->for($this->organization)->create();

        $since = CarbonImmutable::now()->subDay()->toIso8601String();

        $response = $this->getJson('/api/v1/properties?updated_since='.urlencode($since), $this->authHeaders());

        $response->assertOk()->assertJsonPath('meta.total', 1);
        $this->assertSame((int) $recent->getKey(), $response->json('data.0.id'));

        $this->getJson('/api/v1/properties?updated_since=kein-datum', $this->authHeaders())
            ->assertStatus(400)
            ->assertJson(['code' => 'invalid_filter']);
    }

    public function test_external_id_filter_property_id_filter_sort_and_sparse_fields(): void
    {
        $this->issueKey(['properties:read', 'units:read']);

        $property = Property::factory()->for($this->organization)->create(['external_id' => 'OBJ-EXT-1']);
        $other = Property::factory()->for($this->organization)->create();
        Unit::factory()->count(3)->for($property)->create(['organization_id' => $this->organization->getKey()]);
        Unit::factory()->for($other)->create(['organization_id' => $this->organization->getKey()]);

        $this->getJson('/api/v1/properties?external_id=OBJ-EXT-1', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', (int) $property->getKey());

        $response = $this->getJson('/api/v1/units?property_id='.$property->getKey().'&sort=-unit_number&fields=unit_number', $this->authHeaders());
        $response->assertOk()->assertJsonPath('meta.total', 3);

        $first = $response->json('data.0');
        $this->assertSame(['id', 'unit_number', 'provenance'], array_keys($first));

        $numbers = array_column($response->json('data'), 'unit_number');
        $sorted = $numbers;
        rsort($sorted);
        $this->assertSame($sorted, $numbers);

        $this->getJson('/api/v1/units?sort=nicht_erlaubt', $this->authHeaders())
            ->assertStatus(400)
            ->assertJson(['code' => 'invalid_filter']);
    }

    public function test_show_returns_404_problem_json_and_stale_flag(): void
    {
        $this->issueKey(['properties:read']);

        $this->getJson('/api/v1/properties/999999', $this->authHeaders())
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJson([
                'type' => 'https://immoware.muellerhv.de/errors/not_found',
                'status' => 404,
                'code' => 'not_found',
            ])
            ->assertJsonStructure(['request_id', 'instance']);

        $property = Property::factory()->for($this->organization)->create(['last_synced_at' => CarbonImmutable::now()->subDays(3)]);

        $this->getJson('/api/v1/properties/'.$property->getKey(), $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('data.provenance.stale', true)
            ->assertJsonPath('meta.source.source_status', 'stale');

        $foreign = Property::factory()->create();

        $this->getJson('/api/v1/properties/'.$foreign->getKey(), $this->authHeaders())->assertStatus(404);
    }

    public function test_root_directory_lists_resources_and_me_returns_caller(): void
    {
        $this->issueKey(['properties:read']);

        $root = $this->getJson('/api/v1', $this->authHeaders())->assertOk();
        $names = array_column($root->json('data.resources'), 'name');

        foreach (['properties', 'units', 'contacts', 'contracts', 'documents', 'invoices', 'open-items', 'transactions', 'cases', 'calendar-events', 'directory', 'sync_status', 'capabilities'] as $expected) {
            $this->assertContains($expected, $names);
        }

        $this->getJson('/api/v1/me', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('data.type', 'api_key')
            ->assertJsonPath('data.scopes', ['properties:read'])
            ->assertJsonPath('data.organization_id', (int) $this->organization->getKey());
    }

    public function test_sync_status_and_capabilities_require_admin_scope(): void
    {
        $this->issueKey(['properties:read']);
        $this->getJson('/api/v1/sync/status', $this->authHeaders())->assertStatus(403);

        $this->issueKey(['admin']);
        $this->createConnection($this->organization);

        $this->getJson('/api/v1/sync/status', $this->authHeaders())
            ->assertOk()
            ->assertJsonCount(1, 'data.connections');

        $this->getJson('/api/v1/capabilities', $this->authHeaders())
            ->assertOk()
            ->assertJsonStructure(['data' => ['capabilities', 'registry']]);
    }
}
