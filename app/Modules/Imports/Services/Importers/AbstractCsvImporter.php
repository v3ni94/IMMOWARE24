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
 *
 * Sweep-Regeln (07-sync-strategy.md Abschnitt 4 Punkt 8, data-ownership.md Delete Rules, Änderungsvermerk 12.09.2026):
 * Die Sweep-Menge ist auf den Exporttyp (Präfix der external_id) und, wo der Export objektbezogen ist, auf die
 * im Lauf gesehenen Objekte begrenzt. Erstes Fehlen setzt nur missing_since, Soft Delete erst beim zweiten
 * Vollexport ohne Treffer. Schutzgrenze analog Dokumentenspiegel: Fehlen mehr als 20 Prozent oder mehr als 500
 * Datensätze, wird nicht gelöscht, sondern nur missing_since gesetzt und gewarnt.
 */
abstract class AbstractCsvImporter implements RowImporterInterface
{
    public const string SOURCE_SYSTEM = 'immoware24';

    public const string DELETION_REASON_MISSING = 'missing_in_full_export';

    public const float SWEEP_GUARD_RATIO = 0.2;

    public const int SWEEP_GUARD_ABSOLUTE = 500;

    public const int SWEEP_GUARD_MIN_TOTAL = 10;

    /** @var array<string, true> external_id_hash aller in diesem Lauf berührten Datensätze (für Sweep und Snapshot) */
    protected array $seenHashes = [];

    /** @var array<int, true> IDs der in diesem Lauf berührten Objekte (properties), begrenzen den Sweep objektbezogener Exporte */
    protected array $seenPropertyIds = [];

    /**
     * @param  iterable<int, array<string, string|int>>  $rows
     */
    public function import(ImportContext $context, iterable $rows): ImportOutcome
    {
        $outcome = new ImportOutcome;
        $this->seenHashes = [];
        $this->seenPropertyIds = [];
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

    protected function markPropertySeen(int $propertyId): void
    {
        $this->seenPropertyIds[$propertyId] = true;
    }

    /**
     * Präfix der external_id dieses Exporttyps. Der Sweep berührt ausschließlich Datensätze mit diesem Präfix,
     * damit z. B. ein Kontakte-Vollexport nie die von Belegungs- oder Eigentümerlisten angelegten tenant:*- und
     * owner:*-Kontakte löscht.
     */
    protected function sweepPrefix(): ?string
    {
        return defined('static::PREFIX') ? (string) constant('static::PREFIX') : null;
    }

    /**
     * Begrenzt den Sweep auf den Umfang der Datei. Objektbezogene Exporte (Einheiten, Verträge, Eigentümer)
     * überschreiben diese Methode und filtern auf die gesehenen Objekte. Rückgabe null = kein Sweep.
     *
     * @param  Builder<Model>  $query
     * @param  array<int, int>  $propertyIds
     * @return Builder<Model>|null
     */
    protected function scopeSweep(Builder $query, ImportContext $context, array $propertyIds): ?Builder
    {
        return $query;
    }

    protected function wasSeen(Model $model): bool
    {
        return isset($this->seenHashes[(string) $model->getAttribute('external_id_hash')]);
    }

    /**
     * Mark-and-Sweep in zwei Stufen: Datensätze des Exporttyps (und Objektumfangs), die in diesem Vollexport nicht
     * berührt wurden, erhalten beim ersten Fehlen missing_since; beim zweiten Vollexport ohne Treffer folgt der
     * Soft Delete. Der Abgleich läuft über die im Lauf gesehenen external_id_hash, nicht über Zeitstempel.
     */
    protected function sweep(ImportContext $context, ImportOutcome $outcome): void
    {
        $modelClass = $this->sweepModel();

        if ($modelClass === null) {
            return;
        }

        /** @var Builder<Model> $query */
        $query = $modelClass::query()->withoutGlobalScope('organization')
            ->where('organization_id', $context->organizationId)
            ->where('source_system', $this->sourceSystem());

        $prefix = $this->sweepPrefix();

        if ($prefix !== null) {
            $query->where('external_id', 'like', $prefix.':%');
        }

        $query = $this->scopeSweep($query, $context, array_keys($this->seenPropertyIds));

        if ($query === null) {
            Log::info('Sweep übersprungen: Datei ohne bestimmbaren Objektumfang.', ['import_file_id' => $context->file->getKey(), 'model' => $modelClass]);

            return;
        }

        $total = 0;
        /** @var array<int, Model> $missing */
        $missing = [];

        foreach ((clone $query)->lazyById(500) as $model) {
            $total++;

            if (! $this->wasSeen($model)) {
                $missing[] = $model;
            }
        }

        if ($missing === []) {
            return;
        }

        $guard = $this->sweepGuardTriggered(count($missing), $total);

        if ($guard) {
            Log::warning('Sweep-Schutzgrenze erreicht, kein Soft Delete in diesem Lauf.', [
                'import_file_id' => $context->file->getKey(),
                'model' => $modelClass,
                'missing' => count($missing),
                'total' => $total,
            ]);
            $outcome->addError(null, sprintf('Schutzgrenze: %d von %d Datensätzen fehlen im Vollexport, nur missing_since gesetzt, kein Soft Delete.', count($missing), $total));
        }

        foreach ($missing as $model) {
            $missingSince = $model->getAttribute('missing_since');

            if ($missingSince === null || $guard) {
                if ($missingSince === null) {
                    $model->forceFill([
                        'missing_since' => $context->startedAt,
                        'deletion_reason' => self::DELETION_REASON_MISSING,
                    ])->save();
                    $outcome->rowsMarkedMissing++;
                }

                continue;
            }

            // Zweites Fehlen in einem Folge-Vollexport: Soft Delete.
            $model->forceFill(['deletion_reason' => self::DELETION_REASON_MISSING])->save();
            $model->delete();
            $outcome->rowsSwept++;
        }
    }

    protected function sweepGuardTriggered(int $missing, int $total): bool
    {
        if ($missing > self::SWEEP_GUARD_ABSOLUTE) {
            return true;
        }

        return $total >= self::SWEEP_GUARD_MIN_TOTAL && ($missing / $total) > self::SWEEP_GUARD_RATIO;
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
