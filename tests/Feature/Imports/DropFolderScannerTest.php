<?php

declare(strict_types=1);

namespace Tests\Feature\Imports;

use App\Modules\Imports\Enums\ExportType;
use App\Modules\Imports\Enums\ImportFileStatus;
use App\Modules\Imports\Models\ImportFile;
use App\Modules\Imports\Services\DropFolderScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DropFolderScannerTest extends TestCase
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

    public function test_file_with_sidecar_is_received_with_sha256_and_moved(): void
    {
        $organization = $this->createOrganization();
        $this->dropFixture('properties_win1252.csv', $organization, ExportType::Properties, ['is_full_export' => true]);

        $result = $this->app->make(DropFolderScanner::class)->scan();

        $this->assertCount(1, $result->received);
        $this->assertSame([], $result->errors);

        $file = ImportFile::query()->withoutGlobalScope('organization')->findOrFail($result->received[0]);
        $this->assertSame(ImportFileStatus::Received->value, $file->status);
        $this->assertSame(hash_file('sha256', $this->fixturePath('properties_win1252.csv')), $file->content_hash);
        $this->assertSame('properties', $file->export_type);
        $this->assertTrue($file->is_full_export);
        $this->assertSame('T. Müller', $file->exported_by);
        $this->assertSame((int) $organization->getKey(), (int) $file->organization_id);
        $this->assertFileDoesNotExist($this->dropPath.'/properties_win1252.csv');
        $this->assertFileExists($this->dropPath.'/'.$file->storage_key);
        $this->assertFileExists($this->dropPath.'/'.$file->storage_key.'.json');
    }

    public function test_file_without_sidecar_is_quarantined_when_metadata_required(): void
    {
        $organization = $this->createOrganization();
        config()->set('hub.imports.default_organization_id', (int) $organization->getKey());
        $this->dropFixture('units_bom.csv', $organization, ExportType::Units, sidecar: null);

        $result = $this->app->make(DropFolderScanner::class)->scan();

        $this->assertCount(0, $result->received);
        $this->assertCount(1, $result->quarantined);
        $file = ImportFile::query()->withoutGlobalScope('organization')->findOrFail($result->quarantined[0]);
        $this->assertSame(ImportFileStatus::Quarantined->value, $file->status);
        $this->assertSame('metadata_missing', $file->quarantine_reason);
        $this->assertStringStartsWith('quarantine/', (string) $file->storage_key);
    }

    public function test_incomplete_sidecar_is_quarantined_with_error_list(): void
    {
        $organization = $this->createOrganization();
        $this->dropFixture('units_bom.csv', $organization, ExportType::Units, ['exported_by' => '']);

        $result = $this->app->make(DropFolderScanner::class)->scan();

        $file = ImportFile::query()->withoutGlobalScope('organization')->findOrFail($result->quarantined[0]);
        $this->assertSame('metadata_invalid', $file->quarantine_reason);
        $this->assertStringContainsString('exported_by', (string) json_encode($file->errors));
    }

    public function test_same_content_is_not_registered_twice(): void
    {
        $organization = $this->createOrganization();
        $this->dropFixture('properties_win1252.csv', $organization, ExportType::Properties);
        $this->app->make(DropFolderScanner::class)->scan();

        $this->dropFixture('properties_win1252.csv', $organization, ExportType::Properties, as: 'nochmal.csv');
        $result = $this->app->make(DropFolderScanner::class)->scan();

        $this->assertSame(['nochmal.csv'], $result->duplicates);
        $this->assertSame(1, ImportFile::query()->withoutGlobalScope('organization')->count());
    }

    public function test_scan_command_runs(): void
    {
        $organization = $this->createOrganization();
        $this->dropFixture('unknown_header.csv', $organization, ExportType::Properties);

        $this->artisan('hub:imports:scan', ['--process' => true])
            ->expectsOutputToContain('Erfasst: 1')
            ->assertSuccessful();

        $this->assertSame(ImportFileStatus::Quarantined->value, ImportFile::query()->withoutGlobalScope('organization')->firstOrFail()->status);
    }
}
