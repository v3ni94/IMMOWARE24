<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Modules\Contacts\Models\Contact;
use App\Modules\Estate\Models\CaseFile;
use App\Modules\Estate\Models\Property;
use App\Modules\Mcp\Support\ToolCatalog;
use Tests\Feature\Api\ApiTestCase;

final class McpEndpointsTest extends ApiTestCase
{
    public function test_catalog_requires_api_key(): void
    {
        $this->postJson('/api/v1/mcp/tools')
            ->assertStatus(401)
            ->assertHeader('Content-Type', 'application/problem+json');
    }

    public function test_catalog_lists_all_tools_with_schema_and_classification(): void
    {
        $this->issueKey(['contacts:read']);

        $response = $this->postJson('/api/v1/mcp/tools', [], $this->authHeaders());
        $response->assertOk();

        $catalog = $this->app->make(ToolCatalog::class);
        $names = array_column($response->json('data.tools'), 'name');

        $this->assertSame(array_keys($catalog->all()), $names);
        $this->assertSame(count($catalog->all()), $response->json('meta.total'));

        foreach ($response->json('data.tools') as $tool) {
            $this->assertSame('object', $tool['inputSchema']['type']);
            $this->assertArrayHasKey('properties', $tool['inputSchema']);
            $this->assertContains($tool['class'], ['read', 'write', 'never_autonomous']);

            if ($tool['class'] === 'never_autonomous') {
                $this->assertNull($tool['hub_api']);
                $this->assertFalse($tool['implemented']);
                $this->assertFalse($tool['callable_with_current_key']);
            } else {
                $this->assertNotEmpty($tool['hub_api']['path']);
                $this->assertNotEmpty($tool['required_scopes']);
            }
        }

        $byName = array_column($response->json('data.tools'), null, 'name');
        $this->assertTrue($byName['immoware_search_contacts']['callable_with_current_key']);
        $this->assertFalse($byName['immoware_search_properties']['callable_with_current_key']);
        $this->assertSame('never_autonomous', $byName['immoware_change_bank_account']['class']);
        $this->assertSame('never_autonomous', $byName['immoware_delete_document']['class']);
    }

    public function test_call_without_business_scope_is_rejected_with_403(): void
    {
        $this->issueKey(['units:read']);

        $this->postJson('/api/v1/mcp/call', ['tool' => 'immoware_search_properties', 'arguments' => []], $this->authHeaders())
            ->assertStatus(403)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJson(['code' => 'insufficient_scope'])
            ->assertJsonPath('missing_scopes.0', 'properties:read');
    }

    public function test_read_tool_returns_data_through_hub_api(): void
    {
        $this->issueKey(['properties:read']);
        Property::factory()->for($this->organization)->create(['name' => 'WEG Lindenstraße 12']);
        Property::factory()->for($this->organization)->create(['name' => 'Miethaus Am Park 3']);
        Property::factory()->create(['name' => 'WEG Lindenstraße 99']); // anderer Mandant

        $response = $this->postJson('/api/v1/mcp/call', [
            'tool' => 'immoware_search_properties',
            'arguments' => ['q' => 'Linden', 'per_page' => 10],
        ], $this->authHeaders());

        $response->assertOk()
            ->assertJsonPath('data.tool', 'immoware_search_properties')
            ->assertJsonPath('data.effect', 'none')
            ->assertJsonPath('data.hub_api.status', 200)
            ->assertJsonPath('data.result.meta.total', 1)
            ->assertJsonPath('data.result.data.0.name', 'WEG Lindenstraße 12')
            ->assertJsonPath('data.result.data.0.provenance.source_system', 'immoware24')
            ->assertJsonPath('data.result.meta.source.access_path', 'csv_export');

        $propertyId = $response->json('data.result.data.0.id');

        $this->postJson('/api/v1/mcp/call', ['tool' => 'immoware_get_property', 'arguments' => ['id' => $propertyId]], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('data.result.data.id', $propertyId);

        // Read-Tools werden standardmäßig nicht auditiert.
        $this->assertDatabaseMissing('audit_logs', ['action' => 'mcp.tool.called']);
    }

    public function test_call_with_invalid_arguments_is_rejected_with_422(): void
    {
        $this->issueKey(['properties:read']);

        $this->postJson('/api/v1/mcp/call', [
            'tool' => 'immoware_search_properties',
            'arguments' => ['per_page' => 500, 'unbekannt' => 1],
        ], $this->authHeaders())
            ->assertStatus(422)
            ->assertJson(['code' => 'validation_failed'])
            ->assertJsonCount(2, 'errors');

        $this->postJson('/api/v1/mcp/call', ['tool' => 'immoware_get_property', 'arguments' => []], $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonPath('errors.0.field', 'id');
    }

    public function test_unknown_tool_is_rejected_with_404(): void
    {
        $this->issueKey(['properties:read']);

        $this->postJson('/api/v1/mcp/call', ['tool' => 'immoware_do_anything', 'arguments' => []], $this->authHeaders())
            ->assertStatus(404)
            ->assertJson(['code' => 'unknown_tool']);

        $this->postJson('/api/v1/mcp/call', ['arguments' => []], $this->authHeaders())
            ->assertStatus(400)
            ->assertJson(['code' => 'bad_request']);
    }

    public function test_write_tool_without_mcp_write_scope_is_rejected_with_403(): void
    {
        $this->issueKey(['cases:write', 'cases:read']);

        $this->postJson('/api/v1/mcp/call', [
            'tool' => 'immoware_create_case',
            'arguments' => ['title' => 'Wasserschaden Keller'],
        ], $this->authHeaders())
            ->assertStatus(403)
            ->assertJson(['code' => 'mcp_write_scope_required'])
            ->assertJsonPath('missing_scopes.0', 'mcp:write');

        $this->assertSame(0, CaseFile::query()->count());
        $this->assertDatabaseMissing('audit_logs', ['action' => 'mcp.tool.called']);
    }

    public function test_admin_scope_does_not_replace_mcp_write_scope(): void
    {
        $this->issueKey(['admin']);

        $this->postJson('/api/v1/mcp/call', [
            'tool' => 'immoware_create_case',
            'arguments' => ['title' => 'Wasserschaden Keller'],
        ], $this->authHeaders())
            ->assertStatus(403)
            ->assertJson(['code' => 'mcp_write_scope_required']);
    }

    public function test_write_tool_creates_hub_case_and_audit_entry_with_source_mcp(): void
    {
        $this->issueKey(['cases:write', 'mcp:write']);
        $property = Property::factory()->for($this->organization)->create();

        $response = $this->postJson('/api/v1/mcp/call', [
            'tool' => 'immoware_create_case',
            'arguments' => ['title' => 'Heizungsausfall Haus 3', 'property_id' => $property->getKey()],
            'idempotency_key' => 'mcp-case-1',
        ], $this->authHeaders());

        $response->assertOk()
            ->assertJsonPath('data.effect', 'hub')
            ->assertJsonPath('data.hub_api.status', 201)
            ->assertJsonPath('data.result.data.title', 'Heizungsausfall Haus 3')
            ->assertJsonPath('data.result.data.provenance.source_system', 'hub');

        $caseId = $response->json('data.result.data.id');
        $this->assertDatabaseHas('cases', ['id' => $caseId, 'organization_id' => $this->organization->getKey(), 'source_system' => 'hub']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'mcp.tool.called', 'source' => 'mcp']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'api.case.created', 'entity_id' => $caseId]);

        // Wiederholung mit gleichem idempotency_key erzeugt keinen zweiten Vorgang.
        $this->postJson('/api/v1/mcp/call', [
            'tool' => 'immoware_create_case',
            'arguments' => ['title' => 'Heizungsausfall Haus 3', 'property_id' => $property->getKey()],
            'idempotency_key' => 'mcp-case-1',
        ], $this->authHeaders())->assertOk()->assertJsonPath('data.result.data.id', $caseId);

        $this->assertSame(1, CaseFile::query()->count());
    }

    public function test_propose_contact_change_creates_proposal_only(): void
    {
        $this->issueKey(['contacts:write', 'mcp:write']);
        $contact = Contact::factory()->for($this->organization)->create(['last_name' => 'Alt']);

        $response = $this->postJson('/api/v1/mcp/call', [
            'tool' => 'immoware_propose_contact_change',
            'arguments' => ['id' => $contact->getKey(), 'changes' => ['last_name' => 'Neu'], 'reason' => 'Schreiben vom 01.09.2026'],
        ], $this->authHeaders());

        $response->assertOk()
            ->assertJsonPath('data.hub_api.status', 202)
            ->assertJsonPath('data.result.data.effect', 'hub')
            ->assertJsonPath('data.result.data.proposals.0.field', 'last_name')
            ->assertJsonPath('data.result.data.proposals.0.new_value', 'Neu');

        $this->assertSame('Alt', $contact->refresh()->getAttribute('last_name'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'mcp.tool.called', 'source' => 'mcp']);
    }

    public function test_never_autonomous_tool_is_rejected_with_403_and_clear_message(): void
    {
        $this->issueKey(['admin', 'mcp:write']);

        foreach (['immoware_change_bank_account', 'immoware_delete_document'] as $tool) {
            $response = $this->postJson('/api/v1/mcp/call', ['tool' => $tool, 'arguments' => []], $this->authHeaders());

            $response->assertStatus(403)
                ->assertHeader('Content-Type', 'application/problem+json')
                ->assertJson(['code' => 'never_autonomous', 'tool' => $tool, 'class' => 'never_autonomous']);

            $this->assertStringContainsString('nie autonom', (string) $response->json('detail'));
            $this->assertStringContainsString('Mensch', (string) $response->json('detail'));
        }

        $this->assertDatabaseMissing('audit_logs', ['action' => 'mcp.tool.called']);
    }

    public function test_export_command_writes_tools_json(): void
    {
        $target = sys_get_temp_dir().'/mcp-tools-'.uniqid().'.json';

        $this->artisan('hub:mcp:export', ['--path' => $target])->assertSuccessful();

        $document = json_decode((string) file_get_contents($target), true);
        @unlink($target);

        $this->assertSame('mcp:write', $document['write_scope']);
        $this->assertCount(count($this->app->make(ToolCatalog::class)->all()), $document['tools']);
        $this->assertSame('immoware_search_contacts', $document['tools'][0]['name']);
    }
}
