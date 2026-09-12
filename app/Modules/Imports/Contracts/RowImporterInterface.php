<?php

declare(strict_types=1);

namespace App\Modules\Imports\Contracts;

use App\Modules\Imports\DTO\ImportContext;
use App\Modules\Imports\DTO\ImportOutcome;
use App\Modules\Imports\Enums\ExportType;

/**
 * Mapping-basierter Importer je Exporttyp. Erhält gestreamte Zeilen (normalisierter Header => Wert).
 */
interface RowImporterInterface
{
    public function exportType(): ExportType;

    /**
     * Zielfelder, die das Mapping kennen muss (Pflichtfelder markiert der Importer selbst).
     *
     * @return array<int, string>
     */
    public function targetFields(): array;

    /**
     * @param  iterable<int, array<string, string|int>>  $rows
     */
    public function import(ImportContext $context, iterable $rows): ImportOutcome;
}
