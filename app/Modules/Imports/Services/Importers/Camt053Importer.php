<?php

declare(strict_types=1);

namespace App\Modules\Imports\Services\Importers;

use App\Modules\Estate\Models\Transaction;
use App\Modules\Imports\DTO\ImportOutcome;
use App\Modules\Imports\Models\ImportFile;
use App\Modules\Imports\Services\Camt053Parser;
use Carbon\CarbonImmutable;

/**
 * Schreibt camt.053-Buchungen als transactions (kind = bank), lesend für die Anzeige.
 * Kontrahent und maskierte IBAN werden im Text bzw. document_field abgelegt, nie eine Klar-IBAN.
 */
final class Camt053Importer
{
    public const string PREFIX = 'camt';

    public function __construct(private readonly Camt053Parser $parser) {}

    public function import(ImportFile $file, string $path): ImportOutcome
    {
        $entries = $this->parser->parse((string) file_get_contents($path));
        $outcome = new ImportOutcome;
        $occurrences = [];
        $organizationId = (int) $file->organization_id;
        $now = CarbonImmutable::now();

        foreach ($entries as $index => $entry) {
            $outcome->rowsTotal++;

            $rowHash = hash('sha256', implode('|', [
                $entry->accountIbanMasked,
                $entry->bookingDate?->toDateString() ?? '',
                (string) $entry->amountCents,
                $entry->currency,
                $entry->endToEndId ?? '',
                $entry->acctSvcrRef ?? '',
                $entry->remittanceInfo ?? '',
                $entry->counterpartyName ?? '',
            ]));
            $occurrences[$rowHash] = ($occurrences[$rowHash] ?? 0) + 1;
            $occurrence = $occurrences[$rowHash];
            $externalId = self::PREFIX.':'.$rowHash.'#'.$occurrence;

            $isDuplicate = $occurrence > 1;

            /** @var Transaction|null $transaction */
            $transaction = Transaction::query()->withoutGlobalScope('organization')->withTrashed()
                ->where('organization_id', $organizationId)
                ->where('source_system', 'immoware24')
                ->where('external_id_hash', hash('sha256', $externalId))
                ->first();

            $attributes = [
                'kind' => 'bank',
                'booking_date' => $entry->bookingDate,
                'value_date' => $entry->valueDate,
                'amount_cents' => $entry->amountCents,
                'currency' => $entry->currency,
                'document_field' => $entry->counterpartyIbanMasked,
                'text' => mb_substr(trim(($entry->counterpartyName !== null ? $entry->counterpartyName.': ' : '').($entry->remittanceInfo ?? '')), 0, 500),
                'end_to_end_id' => $entry->endToEndId !== null ? mb_substr($entry->endToEndId, 0, 35) : null,
                'acct_svcr_ref' => $entry->acctSvcrRef !== null ? mb_substr($entry->acctSvcrRef, 0, 64) : null,
                'row_hash' => $rowHash,
                'occurrence_no' => $occurrence,
                'is_duplicate' => $isDuplicate,
            ];

            if ($transaction === null) {
                $transaction = new Transaction;
                $transaction->forceFill([
                    'organization_id' => $organizationId,
                    'connection_id' => $file->connection_id,
                    'source_system' => 'immoware24',
                    'external_id' => $externalId,
                    'external_parent_id' => $entry->accountIbanMasked !== '' ? $entry->accountIbanMasked : null,
                    'first_synced_at' => $now,
                ]);
            }

            $transaction->forceFill($attributes);
            $transaction->forceFill(['last_synced_at' => $now, 'external_updated_at' => $file->exported_at ?? $now, 'import_file_id' => $file->getKey()]);
            $transaction->applyChecksum($attributes);
            $transaction->save();

            $outcome->rowsImported++;

            if ($isDuplicate) {
                $outcome->rowsDuplicate++;
            }
        }

        return $outcome;
    }
}
