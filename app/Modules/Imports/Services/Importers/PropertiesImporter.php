<?php

declare(strict_types=1);

namespace App\Modules\Imports\Services\Importers;

use App\Modules\Estate\Models\Property;
use App\Modules\Imports\DTO\ImportContext;
use App\Modules\Imports\DTO\ImportOutcome;
use App\Modules\Imports\Enums\ExportType;

/**
 * Objekte. Schlüssel typischerweise object_number (externe Objektnummer).
 */
final class PropertiesImporter extends AbstractCsvImporter
{
    public const string PREFIX = 'property';

    public function exportType(): ExportType
    {
        return ExportType::Properties;
    }

    public function targetFields(): array
    {
        return ['object_number', 'name', 'management_type', 'street', 'house_number', 'postal_code', 'city'];
    }

    protected function importRow(ImportContext $context, array $row, string $key, ImportOutcome $outcome): bool
    {
        $objectNumber = $context->value($row, 'object_number') ?? $key;
        $name = $context->value($row, 'name') ?? $objectNumber;

        $this->upsertByExternalId(Property::class, $context, $this->externalId(self::PREFIX, $key), [
            'immoware_object_number' => $objectNumber,
            'name' => mb_substr($name, 0, 200),
            'management_type' => $this->managementType($context->value($row, 'management_type')),
            'street' => $context->value($row, 'street'),
            'house_number' => $context->value($row, 'house_number'),
            'postal_code' => $context->value($row, 'postal_code'),
            'city' => $context->value($row, 'city'),
        ]);

        return true;
    }

    protected function sweepModel(): string
    {
        return Property::class;
    }

    private function managementType(?string $value): string
    {
        if ($value === null) {
            return 'UNKNOWN';
        }

        $upper = mb_strtoupper($value);

        return match (true) {
            str_contains($upper, 'WEG') => 'WEG',
            str_contains($upper, 'MIET') || str_contains($upper, 'SEV') => 'MIET',
            default => mb_substr($upper, 0, 16),
        };
    }
}
