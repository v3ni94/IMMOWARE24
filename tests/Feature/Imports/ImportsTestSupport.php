<?php

declare(strict_types=1);

namespace Tests\Feature\Imports;

use App\Modules\Connector\Models\Organization;
use App\Modules\Imports\Enums\ExportType;
use App\Modules\Imports\Models\ImportFile;
use App\Modules\Imports\Services\DropFolderScanner;
use App\Modules\Imports\Services\ImportFormatService;
use App\Modules\Imports\Services\ImportProcessor;
use App\Modules\Imports\Services\ImportStorage;

/**
 * Gemeinsame Hilfsfunktionen: temporärer Drop-Ordner, Fixtures mit Sidecar ablegen, scannen, verarbeiten.
 */
trait ImportsTestSupport
{
    protected string $dropPath;

    protected function setUpDropFolder(): void
    {
        $this->dropPath = sys_get_temp_dir().'/hub-imports-'.bin2hex(random_bytes(6));
        mkdir($this->dropPath, 0770, true);
        config()->set('hub.imports.drop_path', $this->dropPath);
        config()->set('hub.imports.require_metadata', true);
        $this->app->make(ImportStorage::class)->reset();
    }

    protected function tearDownDropFolder(): void
    {
        if (isset($this->dropPath) && is_dir($this->dropPath)) {
            $this->removeDirectory($this->dropPath);
        }
    }

    protected function fixturePath(string $name): string
    {
        return base_path('tests/Fixtures/imports/'.$name);
    }

    /**
     * @param  array<string, mixed>|null  $sidecar  null = kein Sidecar
     */
    protected function dropFixture(string $fixture, Organization $organization, ?ExportType $exportType, ?array $sidecar = [], ?string $as = null): string
    {
        $target = $as ?? $fixture;
        copy($this->fixturePath($fixture), $this->dropPath.'/'.$target);

        if ($sidecar !== null) {
            $data = array_merge([
                'organization' => $organization->legal_entity_code,
                'export_type' => $exportType?->value,
                'exported_at' => '2026-09-01T08:00:00+02:00',
                'exported_by' => 'T. Müller',
            ], $sidecar);
            file_put_contents($this->dropPath.'/'.$target.'.json', json_encode($data, JSON_THROW_ON_ERROR));
        }

        return $target;
    }

    protected function scanAndProcess(): ImportFile
    {
        $result = $this->app->make(DropFolderScanner::class)->scan();
        $id = $result->received[0] ?? $result->quarantined[0] ?? null;
        $this->assertNotNull($id, 'Keine Datei erfasst: '.implode('; ', $result->errors));

        /** @var ImportFile $file */
        $file = ImportFile::query()->withoutGlobalScope('organization')->findOrFail($id);

        return $this->app->make(ImportProcessor::class)->process($file);
    }

    protected function confirmPropertiesFormat(): void
    {
        $this->app->make(ImportFormatService::class)->registerConfirmed(
            ExportType::Properties,
            ['Objektnummer', 'Bezeichnung', 'Verwaltungsart', 'Straße', 'Hausnummer', 'PLZ', 'Ort'],
            ['object_number' => 'objektnummer', 'name' => 'bezeichnung', 'management_type' => 'verwaltungsart', 'street' => 'strasse', 'house_number' => 'hausnummer', 'postal_code' => 'plz', 'city' => 'ort'],
            ['object_number'],
        );
    }

    protected function confirmUnitsFormat(): void
    {
        $this->app->make(ImportFormatService::class)->registerConfirmed(
            ExportType::Units,
            ['Objektnummer', 'VE-Nummer', 'Einheitentyp', 'Etage', 'Wohnfläche'],
            ['object_number' => 'objektnummer', 'unit_number' => 've_nummer', 'unit_type' => 'einheitentyp', 'floor' => 'etage', 'living_area_sqm' => 'wohnflaeche'],
            ['object_number', 'unit_number'],
            delimiter: ',',
        );
    }

    protected function confirmOpenItemsFormat(): void
    {
        $this->app->make(ImportFormatService::class)->registerConfirmed(
            ExportType::OpenItems,
            ['OP-Nummer', 'Objektnummer', 'VE-Nummer', 'Art', 'Fälligkeit', 'Betrag', 'Offen'],
            ['op_number' => 'op_nummer', 'object_number' => 'objektnummer', 'unit_number' => 've_nummer', 'kind' => 'art', 'due_date' => 'faelligkeit', 'amount' => 'betrag', 'open_amount' => 'offen'],
            ['op_number'],
        );
    }

    private function removeDirectory(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
