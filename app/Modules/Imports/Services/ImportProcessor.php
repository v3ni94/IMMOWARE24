<?php

declare(strict_types=1);

namespace App\Modules\Imports\Services;

use App\Modules\Imports\DTO\ImportContext;
use App\Modules\Imports\DTO\ImportOutcome;
use App\Modules\Imports\Enums\ExportType;
use App\Modules\Imports\Enums\ImportFileStatus;
use App\Modules\Imports\Exceptions\ImportException;
use App\Modules\Imports\Models\ImportFile;
use App\Modules\Imports\Services\Importers\Camt053Importer;
use App\Modules\Imports\Services\Importers\DatevCsvImporter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Verarbeitet eine erfasste Importdatei: Header-Fingerprint gegen import_formats, bei unbekanntem
 * Fingerprint Quarantäne, bei bestätigtem Mapping Übergabe an den Importer des Exporttyps.
 * DATEV und camt.053 laufen über eigene Parser ohne Spaltenmapping.
 */
final class ImportProcessor
{
    public function __construct(
        private readonly ImportStorage $storage,
        private readonly CsvReader $csv,
        private readonly ImportFormatService $formats,
        private readonly ImporterRegistry $importers,
        private readonly DatevCsvImporter $datev,
        private readonly Camt053Importer $camt,
        private readonly ExportScheduleService $schedules,
    ) {}

    public function process(ImportFile $file): ImportFile
    {
        if ($file->status === ImportFileStatus::Quarantined->value) {
            return $file;
        }

        if ($file->export_type === null) {
            return $this->quarantine($file, 'export_type_missing', 'Exporttyp fehlt im Sidecar.');
        }

        $exportType = ExportType::tryFrom((string) $file->export_type);

        if ($exportType === null) {
            return $this->quarantine($file, 'export_type_unknown', sprintf('Unbekannter Exporttyp "%s".', $file->export_type));
        }

        $path = $this->storage->absolutePath((string) $file->storage_key);

        if (! is_file($path)) {
            return $this->fail($file, 'Datei im Drop-Ordner nicht mehr vorhanden.');
        }

        $file->forceFill(['status' => ImportFileStatus::Processing->value])->save();
        $startedAt = CarbonImmutable::now();

        try {
            $outcome = match ($exportType) {
                ExportType::DatevBuchungsstapel => $this->datev->import($file, $path),
                ExportType::Camt053 => $this->camt->import($file, $path),
                default => $this->processMapped($file, $exportType, $path, $startedAt),
            };
        } catch (ImportException $e) {
            return $this->fail($file, $e->getMessage());
        } catch (Throwable $e) {
            Log::error('Import fehlgeschlagen.', ['import_file_id' => $file->getKey(), 'error' => $e->getMessage()]);

            return $this->fail($file, 'Unerwarteter Fehler: '.$e->getMessage());
        }

        if ($outcome === null) {
            return $file->refresh();
        }

        $file->forceFill([
            'status' => ImportFileStatus::Imported->value,
            'rows_total' => $outcome->rowsTotal,
            'rows_imported' => $outcome->rowsImported,
            'rows_rejected' => $outcome->rowsRejected,
            'rows_failed' => $outcome->rowsFailed,
            'rows_duplicate' => $outcome->rowsDuplicate,
            'errors' => $outcome->errors === [] ? null : $outcome->errors,
            'error_summary' => $this->summary($outcome),
            'processed_at' => CarbonImmutable::now(),
        ])->save();

        $this->schedules->markImported($file);

        return $file;
    }

    private function processMapped(ImportFile $file, ExportType $exportType, string $path, CarbonImmutable $startedAt): ?ImportOutcome
    {
        $document = $this->csv->analyze($path);
        $format = $this->formats->findByFingerprint($document->fingerprint);

        if ($format === null) {
            $format = $this->formats->registerUnknown($document, $exportType);
        }

        $file->forceFill(['header_fingerprint' => $document->fingerprint, 'import_format_id' => $format->getKey()])->save();

        if (! $this->formats->isConfirmed($format)) {
            $this->quarantine($file, 'format_unknown', sprintf(
                'Header-Fingerprint %s ist nicht bestätigt (import_formats #%d). Administrator muss das Spaltenmapping bestätigen.',
                substr($document->fingerprint, 0, 12),
                (int) $format->getKey(),
            ));

            return null;
        }

        if ($format->format_key !== $exportType->value) {
            $this->quarantine($file, 'format_mismatch', sprintf(
                'Header gehört zum Exporttyp "%s", Sidecar nennt "%s".',
                (string) $format->format_key,
                $exportType->value,
            ));

            return null;
        }

        /** @var array<string, string> $mapping */
        $mapping = (array) $format->column_mapping;
        /** @var array<int, string> $keySchema */
        $keySchema = array_values((array) $format->key_schema);

        $context = new ImportContext(
            file: $file,
            organizationId: (int) $file->organization_id,
            connectionId: $file->connection_id !== null ? (int) $file->connection_id : null,
            mapping: $mapping,
            keySchema: $keySchema,
            isFullExport: (bool) $file->is_full_export,
            asOfDate: $file->as_of_date ?? $file->exported_at?->startOfDay() ?? $startedAt->startOfDay(),
            startedAt: $startedAt,
        );

        return $this->importers->for($exportType)->import($context, $this->csv->rows($document));
    }

    private function quarantine(ImportFile $file, string $reason, string $message): ImportFile
    {
        $errors = (array) ($file->errors ?? []);
        $errors[] = ['line' => null, 'reason' => $message];

        $file->forceFill([
            'status' => ImportFileStatus::Quarantined->value,
            'quarantine_reason' => $reason,
            'errors' => $errors,
            'error_summary' => $message,
        ])->save();

        Log::warning('Import-Datei in Quarantäne.', ['import_file_id' => $file->getKey(), 'reason' => $reason]);

        return $file;
    }

    private function fail(ImportFile $file, string $message): ImportFile
    {
        $errors = (array) ($file->errors ?? []);
        $errors[] = ['line' => null, 'reason' => $message];

        $file->forceFill([
            'status' => ImportFileStatus::Failed->value,
            'errors' => $errors,
            'error_summary' => $message,
            'processed_at' => CarbonImmutable::now(),
        ])->save();

        return $file;
    }

    private function summary(ImportOutcome $outcome): ?string
    {
        if ($outcome->rowsRejected === 0 && $outcome->rowsFailed === 0) {
            return null;
        }

        return sprintf('%d Zeilen abgelehnt, %d Zeilen fehlgeschlagen.', $outcome->rowsRejected, $outcome->rowsFailed);
    }
}
