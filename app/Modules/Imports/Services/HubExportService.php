<?php

declare(strict_types=1);

namespace App\Modules\Imports\Services;

use App\Modules\Contacts\Models\Contact;
use App\Modules\Estate\Models\OpenItem;
use App\Modules\Estate\Models\Property;
use App\Modules\Estate\Models\Transaction;
use App\Modules\Estate\Models\Unit;
use App\Modules\Imports\Enums\HubExportFormat;
use App\Modules\Imports\Enums\HubExportStatus;
use App\Modules\Imports\Exceptions\ImportException;
use App\Modules\Imports\Jobs\ExportJob;
use App\Modules\Imports\Models\HubExport;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Anlegen und Einreihen asynchroner Hub-Exporte. Exportierbare Entitäten sind fest hinterlegt,
 * sensible Spalten (IBAN, Secrets) sind nicht Teil der Spaltenlisten.
 */
final class HubExportService
{
    /** @var array<string, array{model: class-string<Model>, columns: array<int, string>}> */
    public const array ENTITIES = [
        'properties' => ['model' => Property::class, 'columns' => ['id', 'immoware_object_number', 'name', 'management_type', 'street', 'house_number', 'postal_code', 'city', 'external_id', 'last_synced_at', 'deleted_at']],
        'units' => ['model' => Unit::class, 'columns' => ['id', 'property_id', 'unit_number', 'unit_type', 'floor', 'living_area_sqm', 'external_id', 'last_synced_at', 'deleted_at']],
        'contacts' => ['model' => Contact::class, 'columns' => ['id', 'kind', 'salutation', 'first_name', 'last_name', 'source_system', 'external_id', 'last_synced_at', 'deleted_at']],
        'open_items' => ['model' => OpenItem::class, 'columns' => ['id', 'property_id', 'unit_id', 'contact_id', 'kind', 'due_date', 'amount_cents', 'open_cents', 'currency', 'as_of_date', 'settled_at', 'external_id']],
        'transactions' => ['model' => Transaction::class, 'columns' => ['id', 'kind', 'booking_date', 'value_date', 'amount_cents', 'currency', 'debit_account', 'credit_account', 'cost_center', 'document_field', 'text', 'is_duplicate', 'external_id']],
    ];

    public function __construct(private readonly Dispatcher $bus) {}

    /**
     * @param  array<string, mixed>  $filter
     */
    public function request(int $organizationId, string $entity, array $filter, HubExportFormat $format, ?int $requestedBy = null, bool $dispatch = true): HubExport
    {
        if (! isset(self::ENTITIES[$entity])) {
            throw new ImportException(sprintf('Entität "%s" ist nicht exportierbar.', $entity));
        }

        $export = HubExport::query()->create([
            'organization_id' => $organizationId,
            'entity' => $entity,
            'filter' => $filter,
            'format' => $format->value,
            'status' => HubExportStatus::Pending->value,
            'requested_by' => $requestedBy,
        ]);

        if ($dispatch) {
            $this->bus->dispatch(new ExportJob((int) $export->getKey()));
        }

        return $export;
    }

    /**
     * Query der zu exportierenden Datensätze (Mandant, Filter). Filter: nur Gleichheit auf erlaubten Spalten
     * sowie include_deleted.
     *
     * @return Builder<Model>
     */
    public function query(HubExport $export): Builder
    {
        $definition = self::ENTITIES[$export->entity] ?? throw new ImportException(sprintf('Entität "%s" ist nicht exportierbar.', (string) $export->entity));
        $modelClass = $definition['model'];

        /** @var Builder<Model> $query */
        $query = $modelClass::query()->withoutGlobalScope('organization')
            ->where('organization_id', $export->organization_id);

        /** @var array<string, mixed> $filter */
        $filter = (array) ($export->filter ?? []);

        if (method_exists($modelClass, 'bootSoftDeletes')) {
            if (($filter['include_deleted'] ?? false) === true) {
                $query->withTrashed();
            }
            unset($filter['include_deleted']);
        }

        foreach ($filter as $column => $value) {
            if (in_array($column, $definition['columns'], true) && (is_scalar($value) || $value === null)) {
                $value === null ? $query->whereNull($column) : $query->where($column, $value);
            }
        }

        return $query;
    }

    /**
     * @return array<int, string>
     */
    public function columns(HubExport $export): array
    {
        return self::ENTITIES[$export->entity]['columns'] ?? [];
    }
}
