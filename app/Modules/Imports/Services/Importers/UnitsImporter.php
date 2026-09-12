<?php

declare(strict_types=1);

namespace App\Modules\Imports\Services\Importers;

use App\Modules\Estate\Models\Property;
use App\Modules\Estate\Models\Unit;
use App\Modules\Imports\DTO\ImportContext;
use App\Modules\Imports\DTO\ImportOutcome;
use App\Modules\Imports\Enums\ExportType;
use Illuminate\Database\Eloquent\Builder;

/**
 * Verwaltungseinheiten mit Objektbezug über die externe Objektnummer (immoware_object_number).
 * Schlüssel typischerweise object_number plus unit_number.
 */
final class UnitsImporter extends AbstractCsvImporter
{
    public const string PREFIX = 'unit';

    public function exportType(): ExportType
    {
        return ExportType::Units;
    }

    public function targetFields(): array
    {
        return ['object_number', 'unit_number', 'unit_type', 'floor', 'living_area_sqm', 'share_numerator', 'share_denominator'];
    }

    protected function importRow(ImportContext $context, array $row, string $key, ImportOutcome $outcome): bool
    {
        $objectNumber = $context->value($row, 'object_number');
        $unitNumber = $context->value($row, 'unit_number');
        $line = isset($row['_line']) ? (int) $row['_line'] : null;

        if ($objectNumber === null || $unitNumber === null) {
            $outcome->reject($line, 'Objektnummer oder VE-Nummer fehlt.');

            return false;
        }

        $property = $this->findProperty($context, $objectNumber);

        if ($property === null) {
            $outcome->reject($line, sprintf('Objekt "%s" ist im Hub unbekannt, Objektexport zuerst importieren.', $objectNumber));

            return false;
        }

        $this->markPropertySeen((int) $property->getKey());

        $this->upsertByExternalId(Unit::class, $context, $this->externalId(self::PREFIX, $key), [
            'property_id' => $property->getKey(),
            'unit_number' => mb_substr($unitNumber, 0, 64),
            'unit_type' => $context->value($row, 'unit_type'),
            'floor' => $context->value($row, 'floor'),
            'living_area_sqm' => $this->parseDecimal($context->value($row, 'living_area_sqm')),
            'co_ownership_share_numerator' => $this->parseDecimal($context->value($row, 'share_numerator')),
            'co_ownership_share_denominator' => $this->parseDecimal($context->value($row, 'share_denominator')),
        ], ['external_parent_id' => $property->external_id]);

        return true;
    }

    /**
     * Objektbezogener Export: Sweep nur für Einheiten der im Lauf gesehenen Objekte.
     */
    protected function scopeSweep(Builder $query, ImportContext $context, array $propertyIds): ?Builder
    {
        return $propertyIds === [] ? null : $query->whereIn('property_id', $propertyIds);
    }

    protected function sweepModel(): string
    {
        return Unit::class;
    }

    public static function findProperty(ImportContext $context, string $objectNumber): ?Property
    {
        return Property::query()->withoutGlobalScope('organization')
            ->where('organization_id', $context->organizationId)
            ->where('immoware_object_number', $objectNumber)
            ->first();
    }

    public static function findUnit(ImportContext $context, string $objectNumber, string $unitNumber): ?Unit
    {
        $property = self::findProperty($context, $objectNumber);

        if ($property === null) {
            return null;
        }

        return Unit::query()->withoutGlobalScope('organization')
            ->where('property_id', $property->getKey())
            ->where('unit_number', $unitNumber)
            ->first();
    }
}
