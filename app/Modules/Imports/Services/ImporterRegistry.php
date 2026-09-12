<?php

declare(strict_types=1);

namespace App\Modules\Imports\Services;

use App\Modules\Imports\Contracts\RowImporterInterface;
use App\Modules\Imports\Enums\ExportType;
use App\Modules\Imports\Exceptions\ImportException;

/**
 * Registry der mapping-basierten Importer je Exporttyp.
 */
final class ImporterRegistry
{
    /** @var array<string, RowImporterInterface> */
    private array $importers = [];

    public function register(RowImporterInterface $importer): void
    {
        $this->importers[$importer->exportType()->value] = $importer;
    }

    public function for(ExportType $exportType): RowImporterInterface
    {
        return $this->importers[$exportType->value]
            ?? throw new ImportException(sprintf('Kein Importer für Exporttyp "%s" registriert.', $exportType->value));
    }

    public function has(ExportType $exportType): bool
    {
        return isset($this->importers[$exportType->value]);
    }

    /**
     * @return array<string, RowImporterInterface>
     */
    public function all(): array
    {
        return $this->importers;
    }
}
