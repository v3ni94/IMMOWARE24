<?php

declare(strict_types=1);

namespace App\Modules\Api\OpenApi;

use App\Modules\Api\Support\ResourceDefinition;
use App\Modules\Api\Support\ResourceRegistry;

/**
 * Manuelles Schema-Verzeichnis für die OpenAPI-Spezifikation (kein Paket). Ressourcenschemata werden
 * aus der ResourceRegistry abgeleitet und mit Typhinweisen je Feldnamensmuster versehen.
 */
final class SchemaRegistry
{
    public function __construct(private readonly ResourceRegistry $resources) {}

    /**
     * @return array<string, array<string, mixed>>
     */
    public function schemas(): array
    {
        $schemas = [
            'Provenance' => [
                'type' => 'object',
                'description' => 'Herkunft und Datenalter der Spiegeldaten. Der Hub liefert nie Live-Daten aus Immoware24.',
                'required' => ['source_system', 'stale'],
                'properties' => [
                    'source_system' => ['type' => 'string', 'enum' => ['immoware24', 'hub']],
                    'external_id' => ['type' => ['string', 'null'], 'description' => 'Externer Schlüssel des Quellsystems, nur Zusatzinformation.'],
                    'last_synced_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                    'connector' => ['type' => ['string', 'null'], 'description' => 'connector_type der Connection, z. B. webdav_documents, carddav_contacts.'],
                    'mapping_version' => ['type' => ['integer', 'null']],
                    'data_age_seconds' => ['type' => ['integer', 'null']],
                    'stale' => ['type' => 'boolean'],
                ],
            ],
            'Problem' => [
                'type' => 'object',
                'description' => 'Fehlerobjekt nach RFC 7807 (application/problem+json).',
                'required' => ['type', 'title', 'status', 'code'],
                'properties' => [
                    'type' => ['type' => 'string', 'format' => 'uri', 'example' => 'https://immoware.muellerhv.de/errors/not_found'],
                    'title' => ['type' => 'string'],
                    'status' => ['type' => 'integer'],
                    'code' => ['type' => 'string'],
                    'detail' => ['type' => 'string'],
                    'instance' => ['type' => 'string'],
                    'request_id' => ['type' => 'string'],
                    'errors' => [
                        'type' => 'array',
                        'items' => ['type' => 'object', 'properties' => ['field' => ['type' => 'string'], 'code' => ['type' => 'string'], 'message' => ['type' => 'string']]],
                    ],
                ],
            ],
            'PageMeta' => [
                'type' => 'object',
                'required' => ['page', 'per_page', 'total'],
                'properties' => [
                    'page' => ['type' => 'integer', 'minimum' => 1],
                    'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => (int) config('hub.api.pagination.max_per_page', 500)],
                    'total' => ['type' => 'integer'],
                    'last_page' => ['type' => 'integer'],
                    'request_id' => ['type' => 'string'],
                    'source' => ['$ref' => '#/components/schemas/SourceMeta'],
                ],
            ],
            'SourceMeta' => [
                'type' => 'object',
                'properties' => [
                    'system' => ['type' => 'string'],
                    'access_path' => ['type' => 'string'],
                    'evidence_status' => ['type' => ['string', 'null']],
                    'source_status' => ['type' => 'string', 'enum' => ['fresh', 'stale', 'degraded']],
                    'data_age_seconds' => ['type' => ['integer', 'null']],
                    'last_synced_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                ],
            ],
            'Links' => [
                'type' => 'object',
                'properties' => [
                    'self' => ['type' => 'string'],
                    'first' => ['type' => 'string'],
                    'last' => ['type' => 'string'],
                    'next' => ['type' => ['string', 'null']],
                    'prev' => ['type' => ['string', 'null']],
                ],
            ],
            'CaseWrite' => [
                'type' => 'object',
                'required' => ['title'],
                'properties' => [
                    'title' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 300],
                    'status' => ['type' => 'string', 'enum' => (array) config('hub.api.case_statuses', ['open'])],
                    'property_id' => ['type' => ['integer', 'null']],
                    'unit_id' => ['type' => ['integer', 'null']],
                    'contact_id' => ['type' => ['integer', 'null']],
                    'immoware_ticket_reference' => ['type' => ['string', 'null'], 'maxLength' => 64, 'description' => 'Nur Text, kein Sync belegt.'],
                ],
            ],
            'ContactProposal' => [
                'type' => 'object',
                'required' => ['changes'],
                'properties' => [
                    'changes' => [
                        'type' => 'object',
                        'description' => 'Feldname zu neuem Wert. Erlaubt: '.implode(', ', (array) config('hub.api.contact_proposal_fields', [])),
                        'additionalProperties' => true,
                    ],
                    'reason' => ['type' => ['string', 'null'], 'maxLength' => 1000],
                ],
            ],
            'ContactProposalResult' => [
                'type' => 'object',
                'properties' => [
                    'contact_id' => ['type' => 'integer'],
                    'proposals' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                        'id' => ['type' => 'integer'], 'field' => ['type' => 'string'], 'old_value' => ['type' => ['string', 'null']], 'new_value' => ['type' => ['string', 'null']], 'status' => ['type' => 'string'],
                    ]]],
                    'effect' => ['type' => 'string', 'enum' => ['hub']],
                    'note' => ['type' => 'string'],
                ],
            ],
            'DocumentUpload' => [
                'type' => 'object',
                'required' => ['file', 'filename'],
                'properties' => [
                    'file' => ['type' => 'string', 'format' => 'binary'],
                    'filename' => ['type' => 'string', 'maxLength' => 120, 'description' => 'ASCII, keine Pfadtrenner, kein führender Punkt.'],
                    'connection_id' => ['type' => 'integer'],
                    'case_id' => ['type' => 'integer'],
                    'source_document_id' => ['type' => 'integer'],
                    'note' => ['type' => 'string', 'maxLength' => 500],
                ],
            ],
            'DocumentUploadResult' => [
                'type' => 'object',
                'description' => 'Upload-Antrag (write_operation). POST liefert zusätzlich outcome, effect, approval_required und status_url; GET /documents/uploads/{uuid} liefert den aktuellen Stand.',
                'required' => ['operation_uuid', 'upload_id', 'status'],
                'properties' => [
                    'operation_uuid' => ['type' => 'string', 'format' => 'uuid'],
                    'upload_id' => ['type' => 'integer'],
                    'status' => ['type' => 'string', 'enum' => ['pending', 'prechecked', 'sent', 'unknown', 'verified', 'failed', 'rejected']],
                    'operation' => ['type' => 'string', 'enum' => ['webdav_create']],
                    'target_path' => ['type' => ['string', 'null']],
                    'original_filename' => ['type' => ['string', 'null']],
                    'size_bytes' => ['type' => ['integer', 'null']],
                    'content_hash' => ['type' => ['string', 'null']],
                    'requested_via' => ['type' => ['string', 'null']],
                    'precheck_result' => ['type' => ['object', 'null']],
                    'verify_result' => ['type' => ['object', 'null']],
                    'last_error' => ['type' => ['string', 'null']],
                    'document_id' => ['type' => ['integer', 'null']],
                    'case_id' => ['type' => ['integer', 'null']],
                    'source_document_id' => ['type' => ['integer', 'null']],
                    'sent_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                    'verified_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                    'failed_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                    'created_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                    'updated_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                    'outcome' => ['type' => 'string'],
                    'effect' => ['type' => 'string', 'enum' => ['immoware24', 'immoware24_after_approval']],
                    'approval_required' => ['type' => 'boolean'],
                    'status_url' => ['type' => 'string'],
                ],
            ],
            'DirectoryEntry' => [
                'type' => 'object',
                'description' => 'Minimierter Telefonbucheintrag ohne Notizen, IBAN oder Geburtsdatum.',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'display_name' => ['type' => 'string'],
                    'first_name' => ['type' => ['string', 'null']],
                    'last_name' => ['type' => ['string', 'null']],
                    'company' => ['type' => ['string', 'null']],
                    'phones' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'mobiles' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'emails' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'roles' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                        'role' => ['type' => 'string'], 'property_id' => ['type' => ['integer', 'null']], 'property' => ['type' => ['string', 'null']], 'unit_id' => ['type' => ['integer', 'null']], 'unit' => ['type' => ['string', 'null']],
                    ]]],
                    'updated_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                ],
            ],
            'Health' => [
                'type' => 'object',
                'required' => ['status'],
                'properties' => [
                    'status' => ['type' => 'string', 'enum' => ['ok', 'degraded', 'down']],
                    'checks' => ['type' => 'object', 'additionalProperties' => ['type' => 'object', 'properties' => ['status' => ['type' => 'string'], 'details' => ['type' => 'object']]]],
                    'checked_at' => ['type' => 'string', 'format' => 'date-time'],
                ],
            ],
            'WebhookEndpoint' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'name' => ['type' => 'string'],
                    'url' => ['type' => 'string', 'format' => 'uri'],
                    'events' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'active' => ['type' => 'boolean'],
                    'created_at' => ['type' => 'string', 'format' => 'date-time'],
                ],
            ],
            'WebhookEndpointWrite' => [
                'type' => 'object',
                'required' => ['name', 'url', 'events'],
                'properties' => [
                    'name' => ['type' => 'string', 'maxLength' => 120],
                    'url' => ['type' => 'string', 'format' => 'uri', 'description' => 'Nur https.'],
                    'events' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'active' => ['type' => 'boolean'],
                ],
            ],
            'WebhookPayload' => [
                'type' => 'object',
                'description' => 'Ausgehendes Ereignis. Enthält nur IDs, Typ, Link und Herkunft, keine personenbezogenen Feldwerte und keine Beträge.',
                'required' => ['event_id', 'event', 'occurred_at', 'organization_id', 'data'],
                'properties' => [
                    'event_id' => ['type' => 'string', 'format' => 'uuid'],
                    'event' => ['type' => 'string'],
                    'occurred_at' => ['type' => 'string', 'format' => 'date-time'],
                    'organization_id' => ['type' => 'integer'],
                    'data' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'type' => ['type' => 'string'], 'href' => ['type' => 'string']]],
                    'source' => ['type' => 'object'],
                ],
            ],
        ];

        foreach ($this->resources->all() as $definition) {
            $schemas[$this->schemaName($definition)] = $this->resourceSchema($definition);
        }

        return $schemas;
    }

    public function schemaName(ResourceDefinition $definition): string
    {
        return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $definition->singular())));
    }

    /**
     * @return array<string, mixed>
     */
    private function resourceSchema(ResourceDefinition $definition): array
    {
        $properties = [];

        foreach ($definition->attributes as $attribute) {
            $properties[$attribute] = $this->guessType($attribute);
        }

        $properties['identity_uncertain'] = ['type' => 'boolean', 'description' => 'true, wenn die Zuordnung zur externen ID unsicher ist.'];
        $properties['provenance'] = ['$ref' => '#/components/schemas/Provenance'];

        return [
            'type' => 'object',
            'description' => $definition->description,
            'required' => ['id', 'provenance'],
            'properties' => $properties,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function guessType(string $attribute): array
    {
        return match (true) {
            $attribute === 'id' || str_ends_with($attribute, '_id') || str_ends_with($attribute, '_by') => ['type' => $attribute === 'id' ? 'integer' : ['integer', 'null']],
            str_ends_with($attribute, '_cents') => ['type' => ['integer', 'null'], 'description' => 'Betrag in Cent.'],
            str_ends_with($attribute, '_at') => ['type' => ['string', 'null'], 'format' => 'date-time'],
            str_ends_with($attribute, '_date') => ['type' => ['string', 'null'], 'format' => 'date'],
            in_array($attribute, ['emails', 'phones', 'addresses', 'categories', 'attendees'], true) => ['type' => ['array', 'null'], 'items' => ['type' => 'object']],
            in_array($attribute, ['all_day', 'content_stored'], true) => ['type' => 'boolean'],
            in_array($attribute, ['size_bytes', 'occurrence_no', 'sync_version', 'occurrences'], true) => ['type' => ['integer', 'null']],
            str_ends_with($attribute, '_json') => ['type' => ['object', 'array', 'null']],
            in_array($attribute, ['living_area_sqm', 'co_ownership_share_numerator', 'co_ownership_share_denominator'], true) => ['type' => ['string', 'number', 'null']],
            default => ['type' => ['string', 'null']],
        };
    }
}
