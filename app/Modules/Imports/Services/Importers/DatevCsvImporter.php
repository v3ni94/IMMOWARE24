<?php

declare(strict_types=1);

namespace App\Modules\Imports\Services\Importers;

use App\Core\Support\Money;
use App\Modules\Estate\Models\Transaction;
use App\Modules\Imports\DTO\ImportOutcome;
use App\Modules\Imports\Exceptions\ImportException;
use App\Modules\Imports\Models\ImportFile;
use App\Modules\Imports\Services\CsvReader;
use App\Modules\Imports\Services\HeaderNormalizer;
use Carbon\CarbonImmutable;

/**
 * DATEV-Buchungsstapel (EXTF). Zeile 1: EXTF-Header (Formatkategorie 21, Buchungsstapel), Zeile 2: Spaltenheader.
 * Ausgewertete Felder: Umsatz (ohne Soll/Haben-Kz), Soll/Haben-Kennzeichen, Konto, Gegenkonto (ohne BU-Schlüssel),
 * Belegdatum (TTMM, Jahr aus dem EXTF-Header "Datum vom"/"Datum bis" bzw. Wirtschaftsjahresbeginn),
 * Belegfeld 1, Buchungstext, KOST1, KOST2.
 *
 * HINWEIS: Format ist DATEV-Standard (Buchungsstapel-Schnittstelle). Immoware24-Spezifika (Spaltenbelegung,
 * Vorzeichen, Kostenstellenverwendung, Zeichensatz) sind unbekannt und am eigenen Mandanten zu verifizieren.
 *
 * Duplikate: row_hash über Belegfeld 1, Belegdatum, Konto, Gegenkonto, Betrag, Text. Identische Zeilen innerhalb
 * einer Datei erhalten occurrence_no 1, 2, 3 ... und werden ab der zweiten als is_duplicate gespeichert, nie
 * verworfen. external_id = row_hash plus occurrence_no (02-data-model.md), ein erneuter Export derselben Buchungen
 * aktualisiert deshalb idempotent statt doppelt anzulegen.
 */
final class DatevCsvImporter
{
    public const string PREFIX = 'datev';

    /** @var array<string, array<int, string>> Zielfeld => akzeptierte normalisierte Spaltennamen */
    private const array COLUMNS = [
        'amount' => ['umsatz_ohne_soll_haben_kz', 'umsatz'],
        'debit_credit' => ['soll_haben_kennzeichen', 'soll_haben_kz', 's_h'],
        'account' => ['konto'],
        'contra_account' => ['gegenkonto_ohne_bu_schluessel', 'gegenkonto'],
        'document_date' => ['belegdatum'],
        'document_field' => ['belegfeld_1', 'belegfeld1'],
        'text' => ['buchungstext'],
        'kost1' => ['kost1_kostenstelle', 'kost1'],
        'kost2' => ['kost2_kostenstelle', 'kost2'],
    ];

    public function __construct(private readonly CsvReader $csv) {}

    public function import(ImportFile $file, string $path): ImportOutcome
    {
        $header = $this->readExtfHeader($path);
        $document = $this->csv->analyze($path, headerLine: 2);
        $columns = $this->resolveColumns($document->normalizedHeaders);

        $outcome = new ImportOutcome;
        $occurrences = [];
        $debitPositive = (bool) config('hub.imports.datev.debit_positive', true);
        $organizationId = (int) $file->organization_id;
        $now = CarbonImmutable::now();

        foreach ($this->csv->rows($document) as $row) {
            $outcome->rowsTotal++;
            $line = (int) $row['_line'];

            $amountRaw = $this->cell($row, $columns, 'amount');
            $cents = $amountRaw === null ? null : Money::parseToCents($amountRaw);

            if ($cents === null) {
                $outcome->reject($line, 'Umsatz fehlt oder ist ungültig.');

                continue;
            }

            $indicator = strtoupper($this->cell($row, $columns, 'debit_credit') ?? 'S');
            $signed = ($indicator === 'H') === $debitPositive ? -abs($cents) : abs($cents);

            $bookingDate = $this->bookingDate($this->cell($row, $columns, 'document_date'), $header);
            $account = $this->cell($row, $columns, 'account');
            $contra = $this->cell($row, $columns, 'contra_account');
            $documentField = $this->cell($row, $columns, 'document_field');
            $text = $this->cell($row, $columns, 'text');

            $rowHash = hash('sha256', implode('|', [
                $documentField ?? '', $bookingDate?->toDateString() ?? '', $account ?? '', $contra ?? '', (string) $signed, $text ?? '',
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
                'kind' => 'ledger',
                'booking_date' => $bookingDate,
                'amount_cents' => $signed,
                'currency' => 'EUR',
                'debit_account' => $account !== null ? mb_substr($account, 0, 20) : null,
                'credit_account' => $contra !== null ? mb_substr($contra, 0, 20) : null,
                'cost_center' => $this->costCenter($this->cell($row, $columns, 'kost1'), $this->cell($row, $columns, 'kost2')),
                'document_field' => $documentField !== null ? mb_substr($documentField, 0, 64) : null,
                'text' => $text !== null ? mb_substr($text, 0, 500) : null,
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
                    'first_synced_at' => $now,
                    'import_file_id' => $file->getKey(),
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

    /**
     * Liest die erste Zeile (EXTF-Header). Positionen gemäß DATEV-Formatbeschreibung:
     * 0 Kennzeichen (EXTF), 1 Versionsnummer, 2 Formatkategorie (21), 3 Formatname, 4 Formatversion,
     * 12 Wirtschaftsjahresbeginn (JJJJMMTT), 14 Datum vom (JJJJMMTT), 15 Datum bis (JJJJMMTT).
     *
     * @return array{fiscal_year_start: CarbonImmutable|null, date_from: CarbonImmutable|null, date_to: CarbonImmutable|null}
     */
    public function readExtfHeader(string $path): array
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new ImportException('DATEV-Datei nicht lesbar.');
        }

        $first = fgets($handle);
        fclose($handle);

        if ($first === false) {
            throw new ImportException('DATEV-Datei ist leer.');
        }

        $first = ltrim($first, "\xEF\xBB\xBF");
        $encoding = $this->csv->detectEncoding($first);
        $first = $this->csv->toUtf8(rtrim($first, "\r\n"), $encoding);
        $fields = str_getcsv($first, ';', '"', '\\');

        if (($fields[0] ?? null) !== 'EXTF') {
            throw new ImportException('Kein DATEV-Buchungsstapel: EXTF-Kennzeichen in Zeile 1 fehlt.');
        }

        if (isset($fields[2]) && trim((string) $fields[2]) !== '' && trim((string) $fields[2]) !== '21') {
            throw new ImportException(sprintf('DATEV-Formatkategorie %s wird nicht unterstützt, erwartet 21 (Buchungsstapel).', trim((string) $fields[2])));
        }

        return [
            'fiscal_year_start' => $this->yyyymmdd($fields[12] ?? null),
            'date_from' => $this->yyyymmdd($fields[14] ?? null),
            'date_to' => $this->yyyymmdd($fields[15] ?? null),
        ];
    }

    /**
     * @param  array<int, string>  $normalizedHeaders
     * @return array<string, string>
     */
    private function resolveColumns(array $normalizedHeaders): array
    {
        $resolved = [];

        foreach (self::COLUMNS as $target => $candidates) {
            foreach ($candidates as $candidate) {
                $normalizedCandidate = HeaderNormalizer::normalize($candidate);
                if (in_array($normalizedCandidate, $normalizedHeaders, true)) {
                    $resolved[$target] = $normalizedCandidate;
                    break;
                }
            }
        }

        foreach (['amount', 'account', 'contra_account', 'document_date'] as $required) {
            if (! isset($resolved[$required])) {
                throw new ImportException(sprintf('DATEV-Spalte für "%s" nicht gefunden. Kopfzeile: %s', $required, implode(', ', $normalizedHeaders)));
            }
        }

        return $resolved;
    }

    /**
     * @param  array<string, string|int>  $row
     * @param  array<string, string>  $columns
     */
    private function cell(array $row, array $columns, string $target): ?string
    {
        $column = $columns[$target] ?? null;

        if ($column === null || ! isset($row[$column])) {
            return null;
        }

        $value = trim((string) $row[$column]);

        return $value === '' ? null : $value;
    }

    /**
     * Belegdatum TTMM: Jahr aus dem Zeitraum des EXTF-Headers, sonst Wirtschaftsjahr, sonst aktuelles Jahr.
     *
     * @param  array{fiscal_year_start: CarbonImmutable|null, date_from: CarbonImmutable|null, date_to: CarbonImmutable|null}  $header
     */
    private function bookingDate(?string $value, array $header): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        $digits = (string) preg_replace('/\D/', '', $value);

        if (strlen($digits) === 8) {
            return $this->yyyymmdd(substr($digits, 4).substr($digits, 2, 2).substr($digits, 0, 2)) ?? $this->yyyymmdd($digits);
        }

        if (strlen($digits) < 3 || strlen($digits) > 4) {
            return null;
        }

        $digits = str_pad($digits, 4, '0', STR_PAD_LEFT);
        $day = (int) substr($digits, 0, 2);
        $month = (int) substr($digits, 2, 2);

        $candidates = [];
        $from = $header['date_from'];
        $to = $header['date_to'];

        if ($from !== null) {
            $candidates[] = $from->year;
            if ($to !== null && $to->year !== $from->year) {
                $candidates[] = $to->year;
            }
        } elseif ($header['fiscal_year_start'] !== null) {
            $candidates[] = $header['fiscal_year_start']->year;
            $candidates[] = $header['fiscal_year_start']->year + 1;
        } else {
            $candidates[] = CarbonImmutable::now()->year;
        }

        foreach ($candidates as $year) {
            if (! checkdate($month, $day, $year)) {
                continue;
            }

            $date = CarbonImmutable::create($year, $month, $day, 0, 0, 0, 'UTC');

            if ($from !== null && $to !== null && ($date->lt($from) || $date->gt($to))) {
                continue;
            }

            return $date;
        }

        return checkdate($month, $day, $candidates[0]) ? CarbonImmutable::create($candidates[0], $month, $day, 0, 0, 0, 'UTC') : null;
    }

    private function yyyymmdd(mixed $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        $digits = (string) preg_replace('/\D/', '', (string) $value);

        if (strlen($digits) !== 8) {
            return null;
        }

        $y = (int) substr($digits, 0, 4);
        $m = (int) substr($digits, 4, 2);
        $d = (int) substr($digits, 6, 2);

        return checkdate($m, $d, $y) ? CarbonImmutable::create($y, $m, $d, 0, 0, 0, 'UTC') : null;
    }

    private function costCenter(?string $kost1, ?string $kost2): ?string
    {
        // Laut immoware24.de/funktionen/datev trägt KOST2 die Objektzuordnung (DOKUMENTIERT), KOST1 ist Zusatz.
        $value = $kost2 ?? $kost1;

        return $value !== null ? mb_substr($value, 0, 40) : null;
    }
}
