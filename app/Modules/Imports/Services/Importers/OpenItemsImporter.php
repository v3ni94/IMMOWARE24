<?php

declare(strict_types=1);

namespace App\Modules\Imports\Services\Importers;

use App\Core\Support\Money;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Estate\Models\OpenItem;
use App\Modules\Imports\DTO\ImportContext;
use App\Modules\Imports\DTO\ImportOutcome;
use App\Modules\Imports\Enums\ExportType;
use Illuminate\Support\Facades\Log;

/**
 * Offene Posten als Snapshot mit Stichtag (as_of_date aus dem Sidecar bzw. exported_at).
 * Nur ein als Vollexport gekennzeichneter Import (is_full_export) ist ein vollständiger Snapshot: OP derselben
 * Quelle (source_system, connection_id) und desselben Objektbereichs (im Lauf gesehene Objekte), die nicht mehr
 * enthalten sind, gelten zum Stichtag als erledigt (settled_at = Stichtag). Teilexporte erledigen nichts.
 * OP werden nie gelöscht, auch nicht per Soft Delete. Je external_id wird genau eine Zeile mit dem jüngsten
 * Stichtag geführt (Änderungsvermerk 12.09.2026, siehe Antwort an Docs-Agent zu 02-data-model).
 * Schlüssel typischerweise op_number oder object_number, unit_number, due_date, kind.
 */
final class OpenItemsImporter extends AbstractCsvImporter
{
    public const string PREFIX = 'open_item';

    public function exportType(): ExportType
    {
        return ExportType::OpenItems;
    }

    public function targetFields(): array
    {
        return ['op_number', 'object_number', 'unit_number', 'contact_number', 'kind', 'due_date', 'amount', 'open_amount'];
    }

    protected function importRow(ImportContext $context, array $row, string $key, ImportOutcome $outcome): bool
    {
        $line = isset($row['_line']) ? (int) $row['_line'] : null;
        $amount = $context->value($row, 'amount');
        $openAmount = $context->value($row, 'open_amount') ?? $amount;
        $amountCents = $amount === null ? null : Money::parseToCents($amount);
        $openCents = $openAmount === null ? null : Money::parseToCents($openAmount);

        if ($amountCents === null || $openCents === null) {
            $outcome->reject($line, 'Betrag fehlt oder ist ungültig.');

            return false;
        }

        $objectNumber = $context->value($row, 'object_number');
        $unitNumber = $context->value($row, 'unit_number');
        $property = $objectNumber !== null ? UnitsImporter::findProperty($context, $objectNumber) : null;
        $unit = ($objectNumber !== null && $unitNumber !== null) ? UnitsImporter::findUnit($context, $objectNumber, $unitNumber) : null;
        $contactNumber = $context->value($row, 'contact_number');
        $contact = null;

        if ($contactNumber !== null) {
            $contactQuery = Contact::query()->withoutGlobalScope('organization')
                ->where('organization_id', $context->organizationId);
            $contactQuery->whereIn('external_id_hash', [
                hash('sha256', 'tenant:'.$contactNumber),
                hash('sha256', 'owner:'.$contactNumber),
                hash('sha256', 'contact:'.$contactNumber),
            ]);
            $contact = $contactQuery->first();
        }

        if ($property !== null) {
            $this->markPropertySeen((int) $property->getKey());
        }

        $externalId = $this->externalId(self::PREFIX, $key);

        /** @var OpenItem|null $item */
        $item = OpenItem::query()->withoutGlobalScope('organization')->withTrashed()
            ->where('organization_id', $context->organizationId)
            ->where('source_system', $this->sourceSystem())
            ->where('external_id_hash', hash('sha256', $externalId))
            ->first();

        $attributes = [
            'property_id' => $property?->getKey(),
            'unit_id' => $unit?->getKey(),
            'contact_id' => $contact?->getKey(),
            'kind' => mb_substr($context->value($row, 'kind') ?? 'unknown', 0, 24),
            'due_date' => $this->parseDate($context->value($row, 'due_date')),
            'amount_cents' => $amountCents,
            'open_cents' => $openCents,
            'currency' => 'EUR',
            'as_of_date' => $context->asOfDate,
            'settled_at' => null,
        ];

        if ($item === null) {
            $item = new OpenItem;
            $item->forceFill([
                'organization_id' => $context->organizationId,
                'connection_id' => $context->connectionId,
                'source_system' => $this->sourceSystem(),
                'external_id' => $externalId,
                'first_synced_at' => $context->startedAt,
            ]);
        }

        $wasNew = ! $item->exists;
        $openBefore = $item->exists ? (int) $item->getOriginal('open_cents') : null;
        $item->forceFill($attributes);
        $item->forceFill(['last_synced_at' => $context->startedAt, 'external_updated_at' => $context->file->exported_at ?? $context->startedAt]);
        $item->applyChecksum($attributes);
        $item->save();
        $this->markSeen($externalId);

        if ($wasNew) {
            $this->dispatchHubEvent('open_item', 'created', $item, $context, 'open-items');
        } elseif ($openBefore !== null && $openBefore !== 0 && $openCents === 0) {
            $this->dispatchHubEvent('open_item', 'paid', $item, $context, 'open-items', ['as_of_date' => $context->asOfDate->toDateString()]);
        }

        return true;
    }

    /**
     * Snapshot-Abgleich nur bei Vollexport: zuvor offene OP derselben Quelle und der im Lauf gesehenen Objekte,
     * die in diesem Snapshot nicht enthalten sind, werden zum Stichtag erledigt. Ohne bestimmbaren Objektumfang
     * (keine Zeile mit auflösbarer Objektnummer) findet kein Abgleich statt.
     */
    protected function afterRows(ImportContext $context, ImportOutcome $outcome): void
    {
        if (! $context->isFullExport) {
            return;
        }

        $propertyIds = array_keys($this->seenPropertyIds);

        if ($propertyIds === []) {
            Log::info('OP-Snapshot-Abgleich übersprungen: Datei ohne bestimmbaren Objektumfang.', ['import_file_id' => $context->file->getKey()]);

            return;
        }

        $query = OpenItem::query()->withoutGlobalScope('organization')
            ->where('organization_id', $context->organizationId)
            ->where('source_system', $this->sourceSystem());
        $query->whereIn('property_id', $propertyIds);
        $query->whereNull('settled_at');

        if ($context->connectionId !== null) {
            $query->where('connection_id', $context->connectionId);
        }

        $query->lazyById(500)
            ->each(function (OpenItem $item) use ($context, $outcome): void {
                if ($this->wasSeen($item)) {
                    return;
                }

                $item->forceFill(['settled_at' => $context->asOfDate, 'open_cents' => 0])->save();
                $outcome->rowsSwept++;
                $this->dispatchHubEvent('open_item', 'paid', $item, $context, 'open-items', ['as_of_date' => $context->asOfDate->toDateString(), 'settled_by' => 'snapshot']);
            });
    }

    /**
     * Kein Soft Delete auf offene Posten, der Snapshot-Abgleich läuft über settled_at.
     */
    protected function sweepModel(): ?string
    {
        return null;
    }
}
