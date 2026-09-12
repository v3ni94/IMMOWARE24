<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Connector\Models\ImmowareConnection;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Documents\Models\Document;
use App\Modules\Estate\Models\Property;
use App\Modules\Estate\Models\Unit;
use App\Modules\Sync\Models\ExternalPayload;
use App\Modules\Sync\Models\FieldMapping;
use App\Modules\Sync\Services\ExternalPayloadArchiver;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Datenherkunft: lesende Detailseiten für Kontakte, Dokumente, Objekte und Einheiten mit Herkunftsblock
 * (Quelle, External ID, letzter Sync, Connector, Mapping-Version) und Link auf die archivierten Rohnutzlasten
 * (external_payloads, maskiert). Keine Mutation. Rechte: records.view für die Detailseite, payloads.view für
 * Rohnutzlasten (nur Owner, Administrator, Developer). Jeder Aufruf der Detailseite und jeder Payload-Abruf wird
 * auditiert (08-security.md Abschnitt 6: jeder Download eines Payloads ist Pflichtereignis).
 */
final class RecordsController extends AdminController
{
    /** @var array<string, class-string<Model>> */
    public const array ENTITIES = [
        'contacts' => Contact::class,
        'documents' => Document::class,
        'properties' => Property::class,
        'units' => Unit::class,
    ];

    /** @var array<string, string> Entitätstyp für Mapping-Version und Stale-Schwellen */
    private const array ENTITY_TYPES = [
        'contacts' => 'contact',
        'documents' => 'document',
        'properties' => 'property',
        'units' => 'unit',
    ];

    /** @var array<string, string> */
    private const array LABELS = [
        'contacts' => 'Kontakt',
        'documents' => 'Dokument',
        'properties' => 'Objekt',
        'units' => 'Einheit',
    ];

    /** @var array<int, string> Spalten, die nie angezeigt werden */
    private const array HIDDEN = ['vcard_extra', 'similarity_hash', 'external_id_hash', 'path_hash', 'storage_key'];

    public function __construct(private readonly ExternalPayloadArchiver $archiver) {}

    public function show(Request $request, string $id): View
    {
        $this->requirePermission('records.view');

        $entity = (string) $request->route('entity');
        $model = $this->find($entity, (int) $id);
        $connection = $this->connection($model);
        $payloadCount = $this->payloadQuery($model)?->count() ?? 0;

        $this->audit('records.viewed', $model, [], ['entity' => $entity, 'payload_count' => $payloadCount], $connection !== null ? (int) $connection->getKey() : null);

        return view('admin::records.show', [
            'entity' => $entity,
            'label' => self::LABELS[$entity],
            'model' => $model,
            'connection' => $connection,
            'connectorName' => $connection !== null ? $connection->getAttribute('name').' ('.$connection->getAttribute('connector_type').')' : $this->fallbackConnector($model),
            'mappingVersion' => $this->mappingVersion(self::ENTITY_TYPES[$entity]),
            'attributes' => $this->displayAttributes($model),
            'payloadCount' => $payloadCount,
            'canViewPayload' => Gate::allows('payloads.view'),
            'staleThreshold' => (int) (config('hub.sync.stale_after_seconds.'.self::ENTITY_TYPES[$entity]) ?? config('hub.sync.stale_after_seconds.default', 86400)),
        ]);
    }

    public function payload(Request $request, string $id): View
    {
        $this->requirePermission('records.view');
        $this->requirePermission('payloads.view');

        $entity = (string) $request->route('entity');
        $model = $this->find($entity, (int) $id);
        $query = $this->payloadQuery($model);

        $payloads = $query !== null
            ? $query->orderByDesc('received_at')->orderByDesc('id')->paginate(10)->withQueryString()
            : null;

        $selectedId = (int) $request->query('payload', '0');
        $selected = null;
        $content = null;

        if ($payloads !== null && $payloads->count() > 0) {
            $candidates = collect($payloads->items());
            $selected = $selectedId > 0 ? $candidates->first(static fn (ExternalPayload $p): bool => (int) $p->getKey() === $selectedId) : $candidates->first();

            if ($selected instanceof ExternalPayload && (int) $selected->size_bytes <= ExternalPayload::INLINE_LIMIT_BYTES) {
                $raw = $this->archiver->contents($selected);
                $content = is_string($raw) && mb_check_encoding($raw, 'UTF-8') ? $raw : null;
            }
        }

        $connectionId = $model->getAttribute('connection_id');
        $this->audit('records.payload_viewed', $model, [], [
            'entity' => $entity,
            'payload_id' => $selected instanceof ExternalPayload ? (int) $selected->getKey() : null,
            'payload_type' => $selected instanceof ExternalPayload ? $selected->getAttribute('payload_type') : null,
            'contains_personal_data' => $selected instanceof ExternalPayload ? (bool) $selected->getAttribute('contains_personal_data') : null,
            'content_shown' => $content !== null,
        ], $connectionId !== null ? (int) $connectionId : null);

        return view('admin::records.payload', [
            'entity' => $entity,
            'label' => self::LABELS[$entity],
            'model' => $model,
            'payloads' => $payloads,
            'selected' => $selected,
            'content' => $content,
        ]);
    }

    private function find(string $entity, int $id): Model
    {
        $class = self::ENTITIES[$entity] ?? abort(404);

        /** @var Model $model */
        $model = $class::query()->withTrashed()->whereKey($id)->firstOrFail();

        return $model;
    }

    private function connection(Model $model): ?ImmowareConnection
    {
        $connectionId = $model->getAttribute('connection_id');

        if ($connectionId === null) {
            return null;
        }

        return ImmowareConnection::query()->whereKey((int) $connectionId)->first(['id', 'name', 'connector_type', 'status']);
    }

    private function fallbackConnector(Model $model): ?string
    {
        $source = (string) $model->getAttribute('source_system');

        return match (true) {
            $source === 'immoware24_csv' => 'file_import (CSV)',
            $source === 'hub' => 'Hub (eigene Daten)',
            default => null,
        };
    }

    private function mappingVersion(string $entityType): ?int
    {
        $version = FieldMapping::query()->where('entity_type', $entityType)->where('status', 'active')->max('version');

        if ($version !== null) {
            return (int) $version;
        }

        $config = match ($entityType) {
            'contact' => config('hub.contacts.mapping_version'),
            default => null,
        };

        return $config !== null ? (int) $config : null;
    }

    /**
     * Fachliche Attribute ohne Herkunftsblock und ohne technische Hashes.
     *
     * @return array<string, mixed>
     */
    private function displayAttributes(Model $model): array
    {
        $skip = array_merge(self::HIDDEN, [
            'id', 'organization_id', 'connection_id', 'source_system', 'external_id', 'external_parent_id', 'external_updated_at',
            'first_synced_at', 'last_synced_at', 'checksum', 'sync_version', 'deleted_at', 'missing_since', 'stale_since', 'created_at', 'updated_at',
        ]);

        $result = [];

        foreach ($model->attributesToArray() as $key => $value) {
            if (in_array($key, $skip, true)) {
                continue;
            }

            $result[$key] = $value;
        }

        return $result;
    }

    /**
     * Nutzlasten derselben Connection mit dem Hash der externen ID.
     *
     * @return Builder<ExternalPayload>|null
     */
    private function payloadQuery(Model $model): ?Builder
    {
        $hash = $model->getAttribute('external_id_hash');
        $connectionId = $model->getAttribute('connection_id');

        if (! is_string($hash) || $hash === '') {
            return null;
        }

        $query = ExternalPayload::query()->where('external_id_hash', $hash);

        if ($connectionId !== null) {
            $query->where('connection_id', (int) $connectionId);
        } else {
            $query->whereIn('connection_id', ImmowareConnection::query()->select('id'));
        }

        return $query;
    }
}
