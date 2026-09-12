<?php

declare(strict_types=1);

namespace App\Modules\Api\Support;

use App\Modules\Calendar\Models\CalendarEvent;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Documents\Models\Document;
use App\Modules\Estate\Models\CaseFile;
use App\Modules\Estate\Models\Contract;
use App\Modules\Estate\Models\Invoice;
use App\Modules\Estate\Models\OpenItem;
use App\Modules\Estate\Models\Property;
use App\Modules\Estate\Models\Transaction;
use App\Modules\Estate\Models\Unit;
use App\Modules\Sync\Models\Conflict;
use App\Modules\Sync\Models\ProposedChange;
use InvalidArgumentException;

/**
 * Katalog aller Spiegel- und Hub-Ressourcen der REST-API v1.
 */
final class ResourceRegistry
{
    /** @var array<string, ResourceDefinition>|null */
    private ?array $definitions = null;

    /**
     * @return array<string, ResourceDefinition>
     */
    public function all(): array
    {
        return $this->definitions ??= $this->build();
    }

    public function get(string $name): ResourceDefinition
    {
        $all = $this->all();

        if (! isset($all[$name])) {
            throw new InvalidArgumentException(sprintf('Unbekannte API-Ressource "%s".', $name));
        }

        return $all[$name];
    }

    public function has(string $name): bool
    {
        return isset($this->all()[$name]);
    }

    /**
     * @return array<string, ResourceDefinition>
     */
    private function build(): array
    {
        $list = [
            new ResourceDefinition(
                name: 'properties',
                model: Property::class,
                entityType: 'property',
                scope: 'properties:read',
                accessPath: 'csv_export',
                evidenceStatus: 'DOKUMENTIERT',
                attributes: ['id', 'immoware_object_number', 'name', 'management_type', 'street', 'house_number', 'postal_code', 'city', 'identity_confidence', 'created_at', 'updated_at'],
                filters: ['management_type' => 'management_type', 'postal_code' => 'postal_code', 'city' => 'city', 'immoware_object_number' => 'immoware_object_number'],
                sortable: ['updated_at', 'id', 'name', 'immoware_object_number'],
                searchable: ['name', 'street', 'city', 'immoware_object_number'],
                description: 'Objekte (Liegenschaften) aus dem Immoware24-Spiegel.',
                statusColumn: null,
            ),
            new ResourceDefinition(
                name: 'units',
                model: Unit::class,
                entityType: 'unit',
                scope: 'units:read',
                accessPath: 'csv_export',
                evidenceStatus: 'DOKUMENTIERT',
                attributes: ['id', 'property_id', 'building_id', 'unit_number', 'unit_type', 'floor', 'living_area_sqm', 'co_ownership_share_numerator', 'co_ownership_share_denominator', 'identity_confidence', 'created_at', 'updated_at'],
                filters: ['property_id' => 'property_id', 'unit_type' => 'unit_type', 'unit_number' => 'unit_number', 'floor' => 'floor'],
                sortable: ['updated_at', 'id', 'unit_number'],
                searchable: ['unit_number', 'unit_type'],
                description: 'Verwaltungseinheiten je Objekt.',
                statusColumn: null,
            ),
            new ResourceDefinition(
                name: 'contacts',
                model: Contact::class,
                entityType: 'contact',
                scope: 'contacts:read',
                accessPath: 'carddav',
                evidenceStatus: 'VERIFIZIERT',
                attributes: ['id', 'kind', 'salutation', 'first_name', 'last_name', 'company_id', 'company_name', 'job_title', 'emails', 'phones', 'addresses', 'categories', 'merged_into_id', 'identity_confidence', 'created_at', 'updated_at'],
                filters: ['kind' => 'kind', 'last_name' => 'last_name', 'property_id' => 'roles.property_id', 'unit_id' => 'roles.unit_id', 'role' => 'roles.role', 'company_id' => 'company_id'],
                sortable: ['updated_at', 'id', 'last_name', 'first_name'],
                searchable: ['first_name', 'last_name', 'company_name'],
                methods: ['GET', 'PATCH'],
                description: 'Kontakte aus dem CardDAV-Spiegel. PATCH erzeugt ausschließlich einen Änderungsvorschlag.',
                statusColumn: null,
            ),
            new ResourceDefinition(
                name: 'contracts',
                model: Contract::class,
                entityType: 'contract',
                scope: 'contracts:read',
                accessPath: 'csv_export',
                evidenceStatus: 'DOKUMENTIERT',
                attributes: ['id', 'unit_id', 'contract_number', 'start_date', 'end_date', 'net_rent_cents', 'ancillary_cents', 'heating_cents', 'total_cents', 'currency', 'status', 'identity_confidence', 'created_at', 'updated_at'],
                filters: ['unit_id' => 'unit_id', 'property_id' => 'unit.property_id', 'contact_id' => 'parties.contact_id', 'contract_number' => 'contract_number'],
                sortable: ['updated_at', 'id', 'start_date', 'end_date'],
                searchable: ['contract_number'],
                description: 'Mietverträge je Einheit.',
            ),
            new ResourceDefinition(
                name: 'documents',
                model: Document::class,
                entityType: 'document',
                scope: 'documents:read',
                accessPath: 'webdav',
                evidenceStatus: 'VERIFIZIERT',
                attributes: ['id', 'folder_id', 'path', 'filename', 'display_name', 'content_type', 'document_type', 'size_bytes', 'remote_etag', 'remote_last_modified', 'content_hash', 'content_stored', 'property_id', 'unit_id', 'contact_id', 'case_id', 'assignment_confidence', 'origin', 'identity_confidence', 'created_at', 'updated_at'],
                filters: ['property_id' => 'property_id', 'unit_id' => 'unit_id', 'contact_id' => 'contact_id', 'case_id' => 'case_id', 'folder_id' => 'folder_id', 'content_type' => 'content_type', 'document_type' => 'document_type', 'origin' => 'origin'],
                sortable: ['updated_at', 'id', 'remote_last_modified', 'filename', 'size_bytes'],
                searchable: ['filename', 'display_name', 'path'],
                methods: ['GET', 'POST'],
                description: 'Dokumentmetadaten aus dem WebDAV-Spiegel. POST legt einen Upload-Antrag für den Posteingang an.',
                statusColumn: null,
            ),
            new ResourceDefinition(
                name: 'invoices',
                model: Invoice::class,
                entityType: 'invoice',
                scope: 'finance:read',
                accessPath: 'datev_csv',
                evidenceStatus: 'DOKUMENTIERT',
                attributes: ['id', 'property_id', 'creditor_contact_id', 'invoice_number', 'invoice_date', 'due_date', 'gross_cents', 'net_cents', 'vat_cents', 'currency', 'document_id', 'status', 'created_at', 'updated_at'],
                filters: ['property_id' => 'property_id', 'contact_id' => 'creditor_contact_id', 'invoice_number' => 'invoice_number'],
                sortable: ['updated_at', 'id', 'invoice_date', 'due_date'],
                searchable: ['invoice_number'],
                description: 'Rechnungen aus Importen (Phase 3).',
                phase: 3,
            ),
            new ResourceDefinition(
                name: 'open-items',
                model: OpenItem::class,
                entityType: 'open_item',
                scope: 'finance:read',
                accessPath: 'csv_export',
                evidenceStatus: 'DOKUMENTIERT',
                attributes: ['id', 'property_id', 'unit_id', 'contact_id', 'kind', 'due_date', 'amount_cents', 'open_cents', 'currency', 'as_of_date', 'created_at', 'updated_at'],
                filters: ['property_id' => 'property_id', 'unit_id' => 'unit_id', 'contact_id' => 'contact_id', 'kind' => 'kind', 'as_of_date' => 'as_of_date'],
                sortable: ['updated_at', 'id', 'due_date', 'as_of_date', 'open_cents'],
                description: 'Offene Posten je Stichtag (Phase 3).',
                phase: 3,
                statusColumn: null,
            ),
            new ResourceDefinition(
                name: 'transactions',
                model: Transaction::class,
                entityType: 'transaction',
                scope: 'finance:read',
                accessPath: 'datev_csv',
                evidenceStatus: 'DOKUMENTIERT',
                attributes: ['id', 'property_id', 'bank_account_id', 'kind', 'booking_date', 'value_date', 'amount_cents', 'currency', 'debit_account', 'credit_account', 'cost_center', 'text', 'end_to_end_id', 'row_hash', 'occurrence_no', 'created_at', 'updated_at'],
                filters: ['property_id' => 'property_id', 'bank_account_id' => 'bank_account_id', 'kind' => 'kind', 'booking_date' => 'booking_date'],
                sortable: ['updated_at', 'id', 'booking_date', 'amount_cents'],
                searchable: ['text', 'end_to_end_id'],
                description: 'Buchungen aus DATEV-CSV und CAMT.053 (Phase 3). Duplikate werden nicht verschmolzen, row_hash dient der Deduplikation.',
                phase: 3,
                statusColumn: null,
            ),
            new ResourceDefinition(
                name: 'cases',
                model: CaseFile::class,
                entityType: 'case',
                scope: 'cases:read',
                accessPath: 'manual',
                evidenceStatus: 'HUB',
                attributes: ['id', 'property_id', 'unit_id', 'contact_id', 'title', 'status', 'immoware_ticket_reference', 'source_system', 'created_by', 'created_at', 'updated_at'],
                filters: ['property_id' => 'property_id', 'unit_id' => 'unit_id', 'contact_id' => 'contact_id', 'immoware_ticket_reference' => 'immoware_ticket_reference'],
                sortable: ['updated_at', 'id', 'created_at', 'title'],
                searchable: ['title', 'immoware_ticket_reference'],
                methods: ['GET', 'POST', 'PATCH'],
                hubOwned: true,
                description: 'Hub-eigene Vorgänge. Kein Schreibpfad nach Immoware24.',
            ),
            new ResourceDefinition(
                name: 'calendar-events',
                model: CalendarEvent::class,
                entityType: 'calendar_event',
                scope: 'properties:read',
                accessPath: 'caldav',
                evidenceStatus: 'VERIFIZIERT',
                attributes: ['id', 'ical_uid', 'summary', 'location', 'starts_at', 'ends_at', 'all_day', 'timezone', 'status', 'recurrence_rule', 'property_id', 'unit_id', 'contact_id', 'case_id', 'created_at', 'updated_at'],
                filters: ['property_id' => 'property_id', 'unit_id' => 'unit_id', 'contact_id' => 'contact_id', 'case_id' => 'case_id', 'starts_at' => 'starts_at'],
                sortable: ['updated_at', 'id', 'starts_at', 'ends_at'],
                searchable: ['summary', 'location'],
                description: 'Kalendereinträge aus dem CalDAV-Spiegel (lesend, Phase 3).',
                phase: 3,
            ),
            new ResourceDefinition(
                name: 'proposals',
                model: ProposedChange::class,
                entityType: 'proposed_change',
                scope: 'conflicts:read',
                accessPath: 'manual',
                evidenceStatus: 'HUB',
                attributes: ['id', 'connection_id', 'entity_type', 'entity_id', 'field', 'old_value', 'new_value', 'reason', 'status', 'requested_by', 'transferred_at', 'transferred_by', 'confirmed_by_sync_run_id', 'confirmed_at', 'rejected_at', 'correlation_id', 'created_at', 'updated_at'],
                filters: ['entity_type' => 'entity_type', 'entity_id' => 'entity_id', 'connection_id' => 'connection_id', 'field' => 'field'],
                sortable: ['updated_at', 'id', 'created_at', 'status'],
                searchable: ['field', 'reason'],
                hubOwned: true,
                description: 'Änderungsvorschläge (Human-in-the-Loop-Rückweg), lesend. Anlage über PATCH /contacts/{id}; Umsetzung erfolgt manuell in Immoware24 und wird durch den nächsten Sync bestätigt.',
                organizationColumn: 'organization_id',
                organizationVia: 'connection',
            ),
            new ResourceDefinition(
                name: 'conflicts',
                model: Conflict::class,
                entityType: 'conflict',
                scope: 'conflicts:read',
                accessPath: 'manual',
                evidenceStatus: 'HUB',
                attributes: ['id', 'connection_id', 'sync_run_id', 'entity_type', 'entity_id', 'conflict_type', 'conflict_state', 'local_snapshot_json', 'proposed_change_json', 'remote_payload_id', 'status', 'assigned_to', 'resolved_by', 'resolved_at', 'resolution_note', 'confirmed_by_sync_run_id', 'occurrences', 'last_seen_at', 'created_at', 'updated_at'],
                filters: ['conflict_type' => 'conflict_type', 'entity_type' => 'entity_type', 'entity_id' => 'entity_id', 'connection_id' => 'connection_id', 'assigned_to' => 'assigned_to'],
                sortable: ['updated_at', 'id', 'created_at', 'status', 'occurrences'],
                hubOwned: true,
                description: 'Sync-Konflikte, lesend. Zuweisung und Auflösung erfolgen in der Admin-Oberfläche (Endpunkte assign und resolve sind geplant).',
                organizationVia: 'connection',
            ),
        ];

        $result = [];

        foreach ($list as $definition) {
            $result[$definition->name] = $definition;
        }

        return $result;
    }
}
