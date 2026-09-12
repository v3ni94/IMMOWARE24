<?php

declare(strict_types=1);

namespace App\Modules\Imports\Services\Importers;

use App\Core\Support\Money;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Estate\Models\Contract;
use App\Modules\Estate\Models\ContractParty;
use App\Modules\Imports\DTO\ImportContext;
use App\Modules\Imports\DTO\ImportOutcome;
use App\Modules\Imports\Enums\ExportType;

/**
 * Belegungsliste: Mieter, Verwaltungseinheit, Mietbeginn, Mietende, Mietbeträge.
 * Schlüssel typischerweise object_number, unit_number, tenant_number, start_date.
 * Mieterkontakte erhalten source_system immoware24_csv und werden nie über Namen zugeordnet.
 */
final class TenantsContractsImporter extends AbstractCsvImporter
{
    public const string PREFIX = 'contract';

    public const string TENANT_PREFIX = 'tenant';

    public function exportType(): ExportType
    {
        return ExportType::TenantsContracts;
    }

    public function targetFields(): array
    {
        return [
            'object_number', 'unit_number', 'tenant_number', 'tenant_salutation', 'tenant_first_name', 'tenant_last_name',
            'contract_number', 'start_date', 'end_date', 'net_rent', 'ancillary', 'heating', 'total', 'status',
        ];
    }

    protected function importRow(ImportContext $context, array $row, string $key, ImportOutcome $outcome): bool
    {
        $line = isset($row['_line']) ? (int) $row['_line'] : null;
        $objectNumber = $context->value($row, 'object_number');
        $unitNumber = $context->value($row, 'unit_number');

        if ($objectNumber === null || $unitNumber === null) {
            $outcome->reject($line, 'Objektnummer oder VE-Nummer fehlt.');

            return false;
        }

        $unit = UnitsImporter::findUnit($context, $objectNumber, $unitNumber);

        if ($unit === null) {
            $outcome->reject($line, sprintf('Verwaltungseinheit %s/%s ist im Hub unbekannt.', $objectNumber, $unitNumber));

            return false;
        }

        $contract = $this->upsertByExternalId(Contract::class, $context, $this->externalId(self::PREFIX, $key), [
            'unit_id' => $unit->getKey(),
            'contract_number' => $context->value($row, 'contract_number'),
            'start_date' => $this->parseDate($context->value($row, 'start_date')),
            'end_date' => $this->parseDate($context->value($row, 'end_date')),
            'net_rent_cents' => $this->cents($context->value($row, 'net_rent')),
            'ancillary_cents' => $this->cents($context->value($row, 'ancillary')),
            'heating_cents' => $this->cents($context->value($row, 'heating')),
            'total_cents' => $this->cents($context->value($row, 'total')),
            'status' => $context->value($row, 'status'),
        ], ['external_parent_id' => $unit->external_id]);

        $tenantNumber = $context->value($row, 'tenant_number');

        if ($tenantNumber !== null) {
            $contact = $this->upsertByExternalId(Contact::class, $context, $this->externalId(self::TENANT_PREFIX, $tenantNumber), [
                'kind' => 'person',
                'salutation' => $context->value($row, 'tenant_salutation'),
                'first_name' => $context->value($row, 'tenant_first_name'),
                'last_name' => $context->value($row, 'tenant_last_name'),
            ], [], (string) config('hub.imports.contacts_csv_source_system', 'immoware24_csv'));

            ContractParty::query()->firstOrCreate([
                'contract_id' => $contract->getKey(),
                'contact_id' => $contact->getKey(),
                'party_role' => 'tenant',
            ]);
        }

        return true;
    }

    protected function sweepModel(): string
    {
        return Contract::class;
    }

    private function cents(?string $value): ?int
    {
        return $value === null ? null : Money::parseToCents($value);
    }
}
