<?php

declare(strict_types=1);

namespace App\Modules\Imports\Services;

use App\Modules\Imports\DTO\CsvDocument;
use App\Modules\Imports\Enums\ExportType;
use App\Modules\Imports\Enums\ImportFormatStatus;
use App\Modules\Imports\Exceptions\ImportException;
use App\Modules\Imports\Models\ImportFormat;
use Carbon\CarbonImmutable;

/**
 * Verwaltet import_formats: bekannte Header-Fingerprints je Exporttyp mit Spaltenmapping und Schlüsselschema.
 * Unbekannte Fingerprints werden mit Status unknown erfasst; erst die Bestätigung durch einen Administrator
 * (confirm) gibt den Import frei. Es wird nie geraten.
 */
final class ImportFormatService
{
    public function findByFingerprint(string $fingerprint): ?ImportFormat
    {
        return ImportFormat::query()->where('header_fingerprint', $fingerprint)->first();
    }

    /**
     * Erfasst einen unbekannten Header als Format mit Status unknown (idempotent je Fingerprint).
     */
    public function registerUnknown(CsvDocument $document, ExportType $exportType): ImportFormat
    {
        $existing = $this->findByFingerprint($document->fingerprint);

        if ($existing !== null) {
            return $existing;
        }

        return ImportFormat::query()->create([
            'format_key' => $exportType->value,
            'version' => $this->nextVersion($exportType),
            'status' => ImportFormatStatus::Unknown->value,
            'header_fingerprint' => $document->fingerprint,
            'header_columns' => $document->headers,
            'delimiter' => $document->delimiter,
            'charset' => $document->encoding,
            'column_mapping' => [],
            'key_schema' => [],
        ]);
    }

    /**
     * Bestätigt das Spaltenmapping eines Fingerprints.
     *
     * @param  array<string, string>  $mapping  Zielfeld => normalisierte Quellspalte
     * @param  array<int, string>  $keySchema  Zielfelder, aus denen der stabile Schlüssel gebildet wird
     */
    public function confirm(string $fingerprint, array $mapping, array $keySchema, ?int $confirmedBy = null, ?ExportType $exportType = null): ImportFormat
    {
        $format = $this->findByFingerprint($fingerprint);

        if ($format === null) {
            throw new ImportException('Unbekannter Header-Fingerprint, Format wurde noch nicht erfasst.');
        }

        if ($mapping === []) {
            throw new ImportException('Spaltenmapping darf nicht leer sein.');
        }

        if ($keySchema === []) {
            throw new ImportException('Schlüsselschema darf nicht leer sein.');
        }

        $normalizedHeaders = HeaderNormalizer::normalizeAll(array_map(static fn ($h): string => (string) $h, (array) $format->header_columns));

        foreach ($mapping as $target => $source) {
            if (! in_array($source, $normalizedHeaders, true)) {
                throw new ImportException(sprintf('Quellspalte "%s" für Zielfeld "%s" ist im Header nicht vorhanden.', $source, $target));
            }
        }

        foreach ($keySchema as $keyField) {
            if (! array_key_exists($keyField, $mapping)) {
                throw new ImportException(sprintf('Schlüsselfeld "%s" ist nicht im Mapping enthalten.', $keyField));
            }
        }

        $format->forceFill([
            'format_key' => $exportType !== null ? $exportType->value : $format->format_key,
            'status' => ImportFormatStatus::Confirmed->value,
            'column_mapping' => $mapping,
            'key_schema' => array_values($keySchema),
            'confirmed_by' => $confirmedBy,
            'confirmed_at' => CarbonImmutable::now(),
        ])->save();

        return $format;
    }

    /**
     * Legt ein bestätigtes Format direkt aus einer Headerliste an (z. B. Seed nach Sichtung einer echten Datei).
     *
     * @param  array<int, string>  $headers
     * @param  array<string, string>  $mapping
     * @param  array<int, string>  $keySchema
     */
    public function registerConfirmed(ExportType $exportType, array $headers, array $mapping, array $keySchema, ?int $confirmedBy = null, string $delimiter = ';', string $charset = 'UTF-8'): ImportFormat
    {
        $normalized = HeaderNormalizer::normalizeAll($headers);
        $fingerprint = HeaderNormalizer::fingerprint($normalized);

        if ($this->findByFingerprint($fingerprint) === null) {
            ImportFormat::query()->create([
                'format_key' => $exportType->value,
                'version' => $this->nextVersion($exportType),
                'status' => ImportFormatStatus::Unknown->value,
                'header_fingerprint' => $fingerprint,
                'header_columns' => $headers,
                'delimiter' => $delimiter,
                'charset' => $charset,
                'column_mapping' => [],
                'key_schema' => [],
            ]);
        }

        return $this->confirm($fingerprint, $mapping, $keySchema, $confirmedBy, $exportType);
    }

    public function isConfirmed(ImportFormat $format): bool
    {
        return $format->status === ImportFormatStatus::Confirmed->value;
    }

    private function nextVersion(ExportType $exportType): int
    {
        $max = ImportFormat::query()->where('format_key', $exportType->value)->max('version');

        return ((int) $max) + 1;
    }
}
