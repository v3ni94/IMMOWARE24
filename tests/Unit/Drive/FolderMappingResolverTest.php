<?php

declare(strict_types=1);

namespace Tests\Unit\Drive;

use App\Modules\Drive\Models\DriveFolderMapping;
use App\Modules\Drive\Services\FolderMappingResolver;
use App\Modules\Estate\Models\Property;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class FolderMappingResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_config_env_map_and_hub_mappings_are_merged_without_duplicates(): void
    {
        config()->set('hub.drive.folder_map_env', 'OBJ-0001:ordnerA, OBJ-0002:ordnerB,kaputt,:leer');
        $property = Property::factory()->create(['immoware_object_number' => 'OBJ-0001']);
        DriveFolderMapping::query()->create(['organization_id' => $property->getAttribute('organization_id'), 'property_id' => $property->getKey(), 'folder_id' => 'ordnerA']);
        DriveFolderMapping::query()->create(['organization_id' => $property->getAttribute('organization_id'), 'property_id' => $property->getKey(), 'folder_id' => 'ordnerC']);

        $resolver = $this->app->make(FolderMappingResolver::class);

        $this->assertSame(['OBJ-0001' => 'ordnerA', 'OBJ-0002' => 'ordnerB'], $resolver->configMap());
        $this->assertSame(['ordnerA', 'ordnerC'], $resolver->foldersForProperty($property));
        $this->assertSame([], $resolver->foldersForProperty(Property::factory()->create(['immoware_object_number' => 'OBJ-0099'])));
    }
}
