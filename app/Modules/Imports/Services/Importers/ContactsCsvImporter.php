<?php

declare(strict_types=1);

namespace App\Modules\Imports\Services\Importers;

use App\Modules\Contacts\Models\Contact;
use App\Modules\Imports\DTO\ImportContext;
use App\Modules\Imports\DTO\ImportOutcome;
use App\Modules\Imports\Enums\ExportType;

/**
 * Adressbuch aus CSV, ergänzend zum CardDAV-Spiegel. source_system = immoware24_csv, damit beide Quellen
 * nebeneinander bestehen und Zusammenführungen dem Contacts-Modul (contact_merges) vorbehalten bleiben.
 * Schlüssel typischerweise contact_number.
 */
final class ContactsCsvImporter extends AbstractCsvImporter
{
    public const string PREFIX = 'contact';

    public function exportType(): ExportType
    {
        return ExportType::ContactsCsv;
    }

    public function targetFields(): array
    {
        return [
            'contact_number', 'kind', 'salutation', 'first_name', 'last_name', 'company_name', 'birth_date',
            'email', 'phone', 'mobile', 'street', 'postal_code', 'city', 'country',
        ];
    }

    protected function sourceSystem(): string
    {
        return (string) config('hub.imports.contacts_csv_source_system', 'immoware24_csv');
    }

    protected function importRow(ImportContext $context, array $row, string $key, ImportOutcome $outcome): bool
    {
        $emails = [];
        $phones = [];
        $addresses = [];

        if (($email = $context->value($row, 'email')) !== null) {
            $emails[] = ['type' => 'work', 'value' => mb_strtolower($email)];
        }
        if (($phone = $context->value($row, 'phone')) !== null) {
            $phones[] = ['type' => 'work', 'value' => $phone];
        }
        if (($mobile = $context->value($row, 'mobile')) !== null) {
            $phones[] = ['type' => 'cell', 'value' => $mobile];
        }

        $street = $context->value($row, 'street');
        $postal = $context->value($row, 'postal_code');
        $city = $context->value($row, 'city');

        if ($street !== null || $postal !== null || $city !== null) {
            $addresses[] = array_filter([
                'type' => 'home',
                'street' => $street,
                'postal_code' => $postal,
                'city' => $city,
                'country' => $context->value($row, 'country'),
            ], static fn ($v): bool => $v !== null);
        }

        $kind = strtolower($context->value($row, 'kind') ?? 'person');
        $companyName = $context->value($row, 'company_name');

        $this->upsertByExternalId(Contact::class, $context, $this->externalId(self::PREFIX, $key), [
            'kind' => $kind === 'company' || ($companyName !== null && $context->value($row, 'last_name') === null) ? 'company' : 'person',
            'salutation' => $context->value($row, 'salutation'),
            'first_name' => $context->value($row, 'first_name'),
            'last_name' => $context->value($row, 'last_name') ?? $companyName,
            'birth_date' => $this->parseDate($context->value($row, 'birth_date')),
            'emails' => $emails === [] ? null : $emails,
            'phones' => $phones === [] ? null : $phones,
            'addresses' => $addresses === [] ? null : $addresses,
        ]);

        return true;
    }

    protected function sweepModel(): string
    {
        return Contact::class;
    }
}
