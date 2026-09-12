<?php

declare(strict_types=1);

namespace App\Modules\Imports\Services\Importers;

use App\Modules\Imports\Contracts\RowImporterInterface;
use App\Modules\Imports\DTO\ImportContext;
use App\Modules\Imports\DTO\ImportOutcome;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Gemeinsame Logik: Schlüsselbildung aus konfigurierten Spalten, Ablehnung schlüsselloser Zeilen,
 * Upsert über externe IDs (nie über Namen), Checksumme, Mark-and-Sweep (Soft Delete) nur bei Vollexport.
 */
abstract class AbstractCsvImporter implements RowImporterInterface
{
    public const string SOURCE_SYSTEM = 'immoware24';

    /** @var array<string, true> external_id_hash aller in diesem Lauf berührten Datensätze (für Sweep und Snapshot) */
    protected array $seenHashes = [];

    /**
     * @param  iterable<int, array<string, string|int>>  $rows
     */
    public function import(ImportContext $context, iterable $rows): ImportOutcome
    {
        $outcome = new ImportOutcome;
        $this->seenHashes = [];
        $this->beforeRows($context, $outcome);

        foreach ($rows as $row) {
            $outcome->rowsTotal++;
            $line = isset($row['_line']) ? (int) $row['_line'] : null;
            $key = $context->key($row);

            if ($key === null) {
                $outcome->reject($line, 'Zeile ohne stabilen Schlüssel ('.implode(', ', $context->keySchema).').');

                continue;
            }

            try {
                if ($this->importRow($context, $row, $key, $outcome)) {
                    $outcome->rowsImported++;
                }
            } catch (Throwable $e) {
                Log::warning('Importzeile fehlgeschlagen.', ['import_file_id' => $context->file->getKey(), 'line' => $line, 'error' => $e->getMessage()]);
                $outcome->fail($line, $e->getMessage());
            }
        }

        $this->afterRows($context, $outcome);

        if ($context->isFullExport) {
            $this->sweep($context, $outcome);
        }

        return $outcome;
    }

    /**
     * Verarbeitet eine Zeile mit gültigem Schlüssel. Rückgabe false = abgelehnt (Zähler wird im Importer geführt).
     *
     * @param  array<string, string|int>  $row
     */
    abstract protected function importRow(ImportContext $context, array $row, string $key, ImportOutcome $outcome): bool;

    /**
     * Zielmodell des Sweeps. null = kein Sweep für diesen Exporttyp.
     *
     * @return class-string<Model>|null
     */
    abstract protected function sweepModel(): ?string;

    protected function beforeRows(ImportContext $context, ImportOutcome $outcome): void {}

    protected function afterRows(ImportContext $context, ImportOutcome $outcome): void {}

    protected function sourceSystem(): string
    {
        return static::SOURCE_SYSTEM;
    }

    /**
     * Externe ID: Exporttyp-Präfix plus Schlüssel, damit Schlüssel verschiedener Exporte nie kollidieren.
     */
    protected function externalId(string $prefix, string $key): string
    {
        return $prefix.':'.$key;
    }

    /**
     * Legt an oder aktualisiert über die externe ID, setzt Herkunftsblock und Checksumme, reaktiviert Soft-Deleted.
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $modelClass
     * @param  array<string, mixed>  $attributes  fachliche Attribute (ohne Herkunftsblock)
     * @param  array<string, mixed>  $onCreate  Attribute nur beim Anlegen
     * @return TModel
     */
    protected function upsertByExternalId(string $modelClass, ImportContext $context, string $externalId, array $attributes, array $onCreate = [], ?string $sourceSystem = null): Model
    {
        $sourceSystem ??= $this->sourceSystem();

        /** @var Builder<TModel> $query */
        $query = $modelClass::query()->withoutGlobalScope('organization')->withTrashed()
            ->where('organization_id', $context->organizationId)
            ->where('source_system', $sourceSystem)
            ->where('external_id_hash', hash('sha256', $externalId));

        /** @var TModel|null $model */
        $model = $query->first();
        $now = $context->startedAt;

        if ($model === null) {
            $model = new $modelClass;
            $model->forceFill(array_merge($onCreate, [
                'organization_id' => $context->organizationId,
                'connection_id' => $context->connectionId,
                'source_system' => $sourceSystem,
                'external_id' => $externalId,
                'first_synced_at' => $now,
            ]));
        }

        $model->forceFill($attributes);
        $model->forceFill([
            'last_synced_at' => $now,
            'external_updated_at' => $context->file->exported_at ?? $now,
            'deleted_at' => null,
            'missing_since' => null,
            'deletion_reason' => null,
        ]);
        $model->applyChecksum($attributes);
        $model->save();
        $this->markSeen($externalId);

        return $model;
    }

    protected function markSeen(string $externalId): void
    {
        $this->seenHashes[hash('sha256', $externalId)] = true;
    }

    protected function wasSeen(Model $model): bool
    {
        return isset($this->seenHashes[(string) $model->getAttribute('external_id_hash')]);
    }

    /**
     * Mark-and-Sweep: alle Datensätze der Quelle, die in diesem Vollexport nicht berührt wurden, werden soft-deleted.
     * Der Abgleich läuft über die im Lauf gesehenen external_id_hash, nicht über Zeitstempel.
     */
    protected function sweep(ImportContext $context, ImportOutcome $outcome): void
    {
        $modelClass = $this->sweepModel();

        if ($modelClass === null) {
            return;
        }

        $modelClass::query()->withoutGlobalScope('organization')
            ->where('organization_id', $context->organizationId)
            ->where('source_system', $this->sourceSystem())
            ->lazyById(500)
            ->each(function (Model $model) use ($context, $outcome): void {
                if ($this->wasSeen($model)) {
                    return;
                }

                $model->forceFill([
                    'missing_since' => $context->startedAt,
                    'deletion_reason' => 'missing_in_full_export',
                ])->save();
                $model->delete();
                $outcome->rowsSwept++;
            });
    }

    protected function parseDate(?string $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $value, $m) === 1) {
            return checkdate((int) $m[2], (int) $m[1], (int) $m[3])
                ? CarbonImmutable::create((int) $m[3], (int) $m[2], (int) $m[1], 0, 0, 0, 'UTC')
                : null;
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) === 1) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1])
                ? CarbonImmutable::create((int) $m[1], (int) $m[2], (int) $m[3], 0, 0, 0, 'UTC')
                : null;
        }

        return null;
    }

    protected function parseDecimal(?string $value): ?float
    {
        if ($value === null) {
            return null;
        }

        $clean = str_replace([' ', "\u{a0}"], '', $value);

        if (str_contains($clean, ',')) {
            $clean = str_replace('.', '', $clean);
            $clean = str_replace(',', '.', $clean);
        }

        return is_numeric($clean) ? (float) $clean : null;
    }
}
