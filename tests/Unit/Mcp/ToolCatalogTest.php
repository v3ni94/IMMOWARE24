<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp;

use App\Modules\Api\Support\ResourceRegistry;
use App\Modules\Mcp\Enums\ToolClass;
use App\Modules\Mcp\Services\ArgumentValidator;
use App\Modules\Mcp\Support\ToolCatalog;
use Tests\TestCase;

final class ToolCatalogTest extends TestCase
{
    public function test_catalog_contains_required_tools_and_maps_to_registered_api_scopes(): void
    {
        $catalog = $this->app->make(ToolCatalog::class);
        $registry = $this->app->make(ResourceRegistry::class);

        $expected = [
            'immoware_search_contacts', 'immoware_get_contact', 'immoware_search_properties', 'immoware_get_property',
            'immoware_search_units', 'immoware_get_unit', 'immoware_get_contract', 'immoware_search_documents',
            'immoware_create_case', 'immoware_propose_contact_change', 'immoware_change_bank_account', 'immoware_delete_document',
        ];

        $this->assertSame($expected, array_keys($catalog->all()));

        $knownScopes = (array) config('hub.security.api_keys.scopes');
        $this->assertContains('mcp:write', $knownScopes);

        foreach ($catalog->all() as $tool) {
            if ($tool->class === ToolClass::NeverAutonomous) {
                $this->assertNull($tool->path);
                $this->assertSame([], $tool->scopes);

                continue;
            }

            $this->assertStringStartsWith('/api/v1/', (string) $tool->path);

            $resource = explode('/', (string) $tool->path)[3];
            $definition = $registry->get($resource);

            foreach ($tool->scopes as $scope) {
                $this->assertContains($scope, $knownScopes, $tool->name);
            }

            if ($tool->class === ToolClass::Read) {
                $this->assertSame([$definition->scope], $tool->scopes, $tool->name);
                $this->assertSame('GET', $tool->method);
            } else {
                $this->assertContains($tool->method, ['POST', 'PATCH']);
                $this->assertContains($tool->method, $definition->methods, $tool->name);
            }

            foreach ($tool->queryParameters as $parameter) {
                if (in_array($parameter, ['q', 'page', 'per_page', 'updated_since'], true)) {
                    continue;
                }

                $this->assertArrayHasKey($parameter, $definition->filters, $tool->name.' Filter '.$parameter);
            }
        }
    }

    public function test_argument_validator_checks_types_enums_and_bounds(): void
    {
        $validator = new ArgumentValidator;
        $schema = $this->app->make(ToolCatalog::class)->get('immoware_search_contacts')->inputSchema;

        $this->assertSame([], $validator->validate($schema, ['q' => 'Müller', 'role' => 'tenant', 'per_page' => 20]));

        $errors = $validator->validate($schema, ['q' => 'M', 'role' => 'ceo', 'per_page' => 0, 'page' => 'x']);
        $codes = array_column($errors, 'code', 'field');

        $this->assertSame('min_length', $codes['q']);
        $this->assertSame('enum', $codes['role']);
        $this->assertSame('minimum', $codes['per_page']);
        $this->assertSame('type', $codes['page']);
    }
}
