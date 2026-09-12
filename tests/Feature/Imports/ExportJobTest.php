<?php

declare(strict_types=1);

namespace Tests\Feature\Imports;

use App\Modules\Estate\Models\Property;
use App\Modules\Imports\Enums\HubExportFormat;
use App\Modules\Imports\Enums\HubExportStatus;
use App\Modules\Imports\Exceptions\ImportException;
use App\Modules\Imports\Jobs\ExportJob;
use App\Modules\Imports\Services\HubExportService;
use App\Modules\Imports\Services\ImportStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class ExportJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_csv_export_is_written_chunked_to_storage(): void
    {
        Storage::fake('local');
        config()->set('hub.imports.exports.chunk_size', 50);
        $organization = $this->createOrganization();
        $other = $this->createOrganization();
        Property::factory()->count(120)->for($organization)->create();
        Property::factory()->count(3)->for($other)->create();

        $export = $this->app->make(HubExportService::class)->request((int) $organization->getKey(), 'properties', [], HubExportFormat::Csv, dispatch: false);
        (new ExportJob((int) $export->getKey()))->handle($this->app->make(HubExportService::class), $this->app->make(ImportStorage::class));

        $export->refresh();
        $this->assertSame(HubExportStatus::Completed, $export->status);
        $this->assertSame(120, $export->row_count, 'Nur der eigene Mandant');
        $this->assertNotNull($export->storage_key);
        Storage::disk('local')->assertExists((string) $export->storage_key);

        $content = (string) Storage::disk('local')->get((string) $export->storage_key);
        $this->assertStringStartsWith("\xEF\xBB\xBF".'id;immoware_object_number;name', $content);
        $this->assertSame(121, count(array_filter(explode("\r\n", $content))));
        $this->assertSame(hash('sha256', $content), $export->content_hash);
        $this->assertSame(strlen($content), $export->size_bytes);
    }

    public function test_json_export_with_filter(): void
    {
        Storage::fake('local');
        $organization = $this->createOrganization();
        Property::factory()->count(4)->for($organization)->create(['management_type' => 'WEG']);
        Property::factory()->count(2)->for($organization)->create(['management_type' => 'MIET']);

        $export = $this->app->make(HubExportService::class)->request((int) $organization->getKey(), 'properties', ['management_type' => 'MIET'], HubExportFormat::Json, dispatch: false);
        (new ExportJob((int) $export->getKey()))->handle($this->app->make(HubExportService::class), $this->app->make(ImportStorage::class));

        $export->refresh();
        $this->assertSame(2, $export->row_count);
        $decoded = json_decode((string) Storage::disk('local')->get((string) $export->storage_key), true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(2, $decoded);
        $this->assertSame('MIET', $decoded[0]['management_type']);
    }

    public function test_unknown_entity_is_rejected(): void
    {
        $organization = $this->createOrganization();
        $this->expectException(ImportException::class);
        $this->app->make(HubExportService::class)->request((int) $organization->getKey(), 'users', [], HubExportFormat::Csv, dispatch: false);
    }
}
