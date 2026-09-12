<?php

declare(strict_types=1);

namespace Tests\Feature\Imports;

use App\Modules\Estate\Models\Property;
use App\Modules\Estate\Models\Unit;
use App\Modules\Imports\Enums\ExportType;
use App\Modules\Imports\Enums\ImportFileStatus;
use App\Modules\Imports\Enums\ImportFormatStatus;
use App\Modules\Imports\Exceptions\ImportException;
use App\Modules\Imports\Models\ImportFormat;
use App\Modules\Imports\Services\HeaderNormalizer;
use App\Modules\Imports\Services\ImportFormatService;
use App\Modules\Imports\Services\ImportProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CsvImportProcessorTest extends TestCase
{
    use ImportsTestSupport;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDropFolder();
    }

    protected function tearDown(): void
    {
        $this->tearDownDropFolder();
        parent::tearDown();
    }

    public function test_unknown_header_is_quarantined_and_format_registered_as_unknown(): void
    {
        $organization = $this->createOrganization();
        $this->dropFixture('unknown_header.csv', $organization, ExportType::Properties);

        $file = $this->scanAndProcess();

        $this->assertSame(ImportFileStatus::Quarantined->value, $file->status);
        $this->assertSame('format_unknown', $file->quarantine_reason);
        $this->assertNotNull($file->header_fingerprint);

        $format = ImportFormat::query()->where('header_fingerprint', $file->header_fingerprint)->firstOrFail();
        $this->assertSame(ImportFormatStatus::Unknown->value, $format->status);
        $this->assertSame(['Spalte A', 'Spalte B', 'Irgendwas'], $format->header_columns);
        $this->assertSame([], $format->column_mapping);
        $this->assertSame(0, Property::query()->withoutGlobalScope('organization')->count(), 'Es wird nie geraten.');
    }

    public function test_confirmed_header_imports_windows_1252_properties_and_rejects_rows_without_key(): void
    {
        $organization = $this->createOrganization();
        $this->confirmPropertiesFormat();
        $this->dropFixture('properties_win1252.csv', $organization, ExportType::Properties);

        $file = $this->scanAndProcess();

        $this->assertSame(ImportFileStatus::Imported->value, $file->status);
        $this->assertSame(3, $file->rows_total);
        $this->assertSame(2, $file->rows_imported);
        $this->assertSame(1, $file->rows_rejected);
        $this->assertSame(4, $file->errors[0]['line']);
        $this->assertStringContainsString('Schlüssel', $file->errors[0]['reason']);

        $property = Property::query()->withoutGlobalScope('organization')->where('immoware_object_number', 'OBJ-001')->firstOrFail();
        $this->assertSame('WEG Königsallee', $property->name);
        $this->assertSame('Düsseldorf', $property->city);
        $this->assertSame('WEG', $property->management_type);
        $this->assertSame('property:OBJ-001', $property->external_id);
        $this->assertSame('immoware24', $property->source_system);
        $this->assertNotNull($property->checksum);
        $this->assertSame('MIET', Property::query()->withoutGlobalScope('organization')->where('immoware_object_number', 'OBJ-002')->firstOrFail()->management_type);
    }

    public function test_confirm_via_service_unlocks_quarantined_fingerprint(): void
    {
        $organization = $this->createOrganization();
        $this->dropFixture('properties_win1252.csv', $organization, ExportType::Properties);
        $file = $this->scanAndProcess();
        $this->assertSame(ImportFileStatus::Quarantined->value, $file->status);

        $service = $this->app->make(ImportFormatService::class);
        $service->confirm((string) $file->header_fingerprint, [
            'object_number' => 'objektnummer', 'name' => 'bezeichnung', 'city' => 'ort',
        ], ['object_number']);

        $file->forceFill(['status' => ImportFileStatus::Received->value, 'quarantine_reason' => null, 'errors' => null])->save();
        $processed = $this->app->make(ImportProcessor::class)->process($file);

        $this->assertSame(ImportFileStatus::Imported->value, $processed->status);
        $this->assertSame(2, Property::query()->withoutGlobalScope('organization')->count());
    }

    public function test_confirm_validates_mapping_against_header(): void
    {
        $service = $this->app->make(ImportFormatService::class);
        $format = $service->registerConfirmed(ExportType::Properties, ['Objektnummer', 'Ort'], ['object_number' => 'objektnummer'], ['object_number']);
        $this->assertSame(ImportFormatStatus::Confirmed->value, $format->status);

        try {
            $service->confirm($format->header_fingerprint, ['object_number' => 'gibt_es_nicht'], ['object_number']);
            $this->fail('Exception erwartet.');
        } catch (ImportException $e) {
            $this->assertStringContainsString('gibt_es_nicht', $e->getMessage());
        }

        $this->expectException(ImportException::class);
        $service->confirm(HeaderNormalizer::fingerprint(['x']), ['a' => 'x'], ['a']);
    }

    public function test_units_with_bom_link_to_property_by_object_number_and_full_export_sweeps(): void
    {
        $organization = $this->createOrganization();
        $this->confirmUnitsFormat();
        $property = Property::factory()->for($organization)->create(['immoware_object_number' => 'OBJ-001']);
        $stale = Unit::factory()->for($property)->create(['organization_id' => $organization->getKey(), 'unit_number' => 'VE-ALT', 'last_synced_at' => now()->subDay()]);

        $this->dropFixture('units_bom.csv', $organization, ExportType::Units, ['is_full_export' => true]);
        $file = $this->scanAndProcess();

        $this->assertSame(ImportFileStatus::Imported->value, $file->status);
        $this->assertSame(2, $file->rows_imported);
        $this->assertSame(1, $file->rows_rejected, 'OBJ-999 unbekannt');

        $unit = Unit::query()->withoutGlobalScope('organization')->where('unit_number', 'VE-01')->firstOrFail();
        $this->assertSame((int) $property->getKey(), (int) $unit->property_id);
        $this->assertSame('65.50', (string) $unit->living_area_sqm);
        $this->assertSame($property->external_id, $unit->external_parent_id);

        $stale->refresh();
        $this->assertNotNull($stale->deleted_at, 'Mark-and-Sweep per Soft Delete');
        $this->assertSame('missing_in_full_export', $stale->deletion_reason);
        $this->assertSame(3, Unit::query()->withoutGlobalScope('organization')->withTrashed()->count(), 'Kein Hard Delete');
    }

    public function test_partial_export_does_not_sweep(): void
    {
        $organization = $this->createOrganization();
        $this->confirmUnitsFormat();
        $property = Property::factory()->for($organization)->create(['immoware_object_number' => 'OBJ-001']);
        $stale = Unit::factory()->for($property)->create(['organization_id' => $organization->getKey(), 'last_synced_at' => now()->subDay()]);

        $this->dropFixture('units_bom.csv', $organization, ExportType::Units, ['is_full_export' => false]);
        $this->scanAndProcess();

        $this->assertNull($stale->refresh()->deleted_at);
    }

    public function test_header_of_other_export_type_is_quarantined_as_mismatch(): void
    {
        $organization = $this->createOrganization();
        $this->confirmPropertiesFormat();
        $this->dropFixture('properties_win1252.csv', $organization, ExportType::Units);

        $file = $this->scanAndProcess();

        $this->assertSame(ImportFileStatus::Quarantined->value, $file->status);
        $this->assertSame('format_mismatch', $file->quarantine_reason);
    }
}
