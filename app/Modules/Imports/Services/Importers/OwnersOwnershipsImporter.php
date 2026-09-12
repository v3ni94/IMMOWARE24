<?php

declare(strict_types=1);

namespace App\Modules\Imports\Services\Importers;

use App\Core\Support\Money;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Estate\Models\Ownership;
use App\Modules\Estate\Models\Unit;
use App\Modules\Imports\DTO\ImportContext;
use App\Modules\Imports\DTO\ImportOutcome;
use App\Modules\Imports\Enums\ExportType;
use Illuminate\Database\Eloquent\Builder;

/**
 * WEG: Eigentümer und Eigentumsanteile je Verwaltungseinheit.
 * Schlüssel typischerweise object_number, unit_number, owner_number.
 */
final class OwnersOwnershipsImporter extends AbstractCsvImporter
{
    public const string PREFIX = 'ownership';

    public const string OWNER_PREFIX = 'owner';

    public function exportType(): ExportType
    {
        return ExportType::OwnersOwnerships;
    }

    public function targetFields(): array
    {
        return [
            'object_number', 'unit_number', 'owner_number', 'owner_kind', 'owner_salutation', 'owner_first_name', 'owner_last_name',
            'share_numerator', 'share_denominator', 'valid_from', 'valid_to', 'house_money',
        ];
    }

    protected function importRow(ImportContext $context, array $row, string $key, ImportOutcome $outcome): bool
    {
        $line = isset($row['_line']) ? (int) $row['_line'] : null;
        $objectNumber = $context->value($row, 'object_number');
        $unitNumber = $context->value($row, 'unit_number');
        $ownerNumber = $context->value($row, 'owner_number');

        if ($objectNumber === null || $unitNumber === null || $ownerNumber === null) {
            $outcome->reject($line, 'Objektnummer, VE-Nummer oder Eigentümernummer fehlt.');

            return false;
        }

        $unit = UnitsImporter::findUnit($context, $objectNumber, $unitNumber);

        if ($unit === null) {
            $outcome->reject($line, sprintf('Verwaltungseinheit %s/%s ist im Hub unbekannt.', $objectNumber, $unitNumber));

            return false;
        }

        $this->markPropertySeen((int) $unit->property_id);

        $kind = strtolower($context->value($row, 'owner_kind') ?? 'person');
        $contact = $this->upsertByExternalId(Contact::class, $context, $this->externalId(self::OWNER_PREFIX, $ownerNumber), [
            'kind' => in_array($kind, ['person', 'company'], true) ? $kind : 'person',
            'salutation' => $context->value($row, 'owner_salutation'),
            'first_name' => $context->value($row, 'owner_first_name'),
            'last_name' => $context->value($row, 'owner_last_name'),
        ], [], (string) config('hub.imports.contacts_csv_source_system', 'immoware24_csv'));

        $houseMoney = $context->value($row, 'house_money');

        $this->upsertByExternalId(Ownership::class, $context, $this->externalId(self::PREFIX, $key), [
            'unit_id' => $unit->getKey(),
            'contact_id' => $contact->getKey(),
            'share_numerator' => $this->parseDecimal($context->value($row, 'share_numerator')),
            'share_denominator' => $this->parseDecimal($context->value($row, 'share_denominator')),
            'valid_from' => $this->parseDate($context->value($row, 'valid_from')),
            'valid_to' => $this->parseDate($context->value($row, 'valid_to')),
            'house_money_cents' => $houseMoney === null ? null : Money::parseToCents($houseMoney),
        ], ['external_parent_id' => $unit->external_id]);

        return true;
    }

    /**
     * Objektbezogener Export: Sweep nur für Datensätze von Einheiten der im Lauf gesehenen Objekte.
     */
    protected function scopeSweep(Builder $query, ImportContext $context, array $propertyIds): ?Builder
    {
        if ($propertyIds === []) {
            return null;
        }

        $query->whereIn('unit_id', Unit::query()->withoutGlobalScope('organization')->select('id')->whereIn('property_id', $propertyIds));

        return $query;
    }

    protected function sweepModel(): string
    {
        return Ownership::class;
    }
}
