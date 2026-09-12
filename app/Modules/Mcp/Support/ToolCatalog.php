<?php

declare(strict_types=1);

namespace App\Modules\Mcp\Support;

use App\Core\Enums\ContactRoleType;
use App\Modules\Mcp\Enums\ToolClass;
use InvalidArgumentException;

/**
 * Tool-Katalog des KI/MCP-Layers als PHP-Array. Jedes Tool ist einem Endpunkt der Hub-API v1 und dessen Scope
 * zugeordnet. Es gibt keinen Tool-Pfad an Immoware24 vorbei (08-security.md Abschnitt 8).
 */
final class ToolCatalog
{
    /** @var array<string, ToolDefinition>|null */
    private ?array $definitions = null;

    /**
     * @return array<string, ToolDefinition>
     */
    public function all(): array
    {
        return $this->definitions ??= $this->build();
    }

    public function has(string $name): bool
    {
        return isset($this->all()[$name]);
    }

    public function get(string $name): ToolDefinition
    {
        $all = $this->all();

        if (! isset($all[$name])) {
            throw new InvalidArgumentException(sprintf('Unbekanntes MCP-Tool "%s".', $name));
        }

        return $all[$name];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function toArray(): array
    {
        return array_values(array_map(static fn (ToolDefinition $tool): array => $tool->toArray(), $this->all()));
    }

    /**
     * @return array<string, ToolDefinition>
     */
    private function build(): array
    {
        $maxPerPage = (int) config('hub.mcp.max_per_page', 50);
        $prefix = (string) config('hub.mcp.tool_prefix', 'immoware_');

        $paging = [
            'page' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Seite, ab 1.'],
            'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => $maxPerPage, 'description' => 'Treffer je Seite, höchstens '.$maxPerPage.'.'],
        ];
        $id = ['type' => 'integer', 'minimum' => 1, 'description' => 'Hub-interne ID der Ressource.'];
        $updatedSince = ['type' => 'string', 'format' => 'date-time', 'description' => 'Nur Datensätze mit updated_at ab diesem ISO-8601-Zeitpunkt.'];
        $q = ['type' => 'string', 'minLength' => 2, 'maxLength' => 120, 'description' => 'Freitextsuche über die durchsuchbaren Felder.'];

        $list = [
            new ToolDefinition(
                name: $prefix.'search_contacts',
                description: 'Sucht Kontakte im CardDAV-Spiegel des Hubs (Name, Firma, Rolle, Objekt, Einheit). Liefert keine Bankverbindungen. Ergebnisse tragen Datenalter und Herkunft.',
                class: ToolClass::Read,
                inputSchema: $this->schema([
                    'q' => $q,
                    'role' => ['type' => 'string', 'enum' => array_map(static fn (ContactRoleType $r): string => $r->value, ContactRoleType::cases()), 'description' => 'Rolle des Kontakts.'],
                    'kind' => ['type' => 'string', 'maxLength' => 20, 'description' => 'Kontaktart, z. B. person oder company.'],
                    'property_id' => $id,
                    'unit_id' => $id,
                    'updated_since' => $updatedSince,
                ] + $paging),
                method: 'GET',
                path: '/api/v1/contacts',
                scopes: ['contacts:read'],
                queryParameters: ['q', 'role', 'kind', 'property_id', 'unit_id', 'updated_since', 'page', 'per_page'],
            ),
            new ToolDefinition(
                name: $prefix.'get_contact',
                description: 'Liefert einen Kontakt anhand seiner Hub-ID. Zusammengeführte Kontakte verweisen auf den Zielkontakt.',
                class: ToolClass::Read,
                inputSchema: $this->schema(['id' => $id], ['id']),
                method: 'GET',
                path: '/api/v1/contacts/{id}',
                scopes: ['contacts:read'],
                pathParameters: ['id'],
            ),
            new ToolDefinition(
                name: $prefix.'search_properties',
                description: 'Sucht Objekte (Liegenschaften) im Spiegel nach Name, Straße, Ort oder Immoware-Objektnummer.',
                class: ToolClass::Read,
                inputSchema: $this->schema([
                    'q' => $q,
                    'management_type' => ['type' => 'string', 'maxLength' => 40, 'description' => 'Verwaltungsart, z. B. WEG oder Miete.'],
                    'postal_code' => ['type' => 'string', 'maxLength' => 10],
                    'city' => ['type' => 'string', 'maxLength' => 120],
                    'immoware_object_number' => ['type' => 'string', 'maxLength' => 64],
                    'updated_since' => $updatedSince,
                ] + $paging),
                method: 'GET',
                path: '/api/v1/properties',
                scopes: ['properties:read'],
                queryParameters: ['q', 'management_type', 'postal_code', 'city', 'immoware_object_number', 'updated_since', 'page', 'per_page'],
            ),
            new ToolDefinition(
                name: $prefix.'get_property',
                description: 'Liefert ein Objekt anhand seiner Hub-ID.',
                class: ToolClass::Read,
                inputSchema: $this->schema(['id' => $id], ['id']),
                method: 'GET',
                path: '/api/v1/properties/{id}',
                scopes: ['properties:read'],
                pathParameters: ['id'],
            ),
            new ToolDefinition(
                name: $prefix.'search_units',
                description: 'Sucht Verwaltungseinheiten, in der Regel eingeschränkt auf ein Objekt (property_id).',
                class: ToolClass::Read,
                inputSchema: $this->schema([
                    'q' => $q,
                    'property_id' => $id,
                    'unit_type' => ['type' => 'string', 'maxLength' => 40],
                    'unit_number' => ['type' => 'string', 'maxLength' => 40],
                    'updated_since' => $updatedSince,
                ] + $paging),
                method: 'GET',
                path: '/api/v1/units',
                scopes: ['units:read'],
                queryParameters: ['q', 'property_id', 'unit_type', 'unit_number', 'updated_since', 'page', 'per_page'],
            ),
            new ToolDefinition(
                name: $prefix.'get_unit',
                description: 'Liefert eine Verwaltungseinheit anhand ihrer Hub-ID.',
                class: ToolClass::Read,
                inputSchema: $this->schema(['id' => $id], ['id']),
                method: 'GET',
                path: '/api/v1/units/{id}',
                scopes: ['units:read'],
                pathParameters: ['id'],
            ),
            new ToolDefinition(
                name: $prefix.'get_contract',
                description: 'Liefert einen Mietvertrag anhand seiner Hub-ID. Beträge sind Ganzzahlen in Cent mit Währung.',
                class: ToolClass::Read,
                inputSchema: $this->schema(['id' => $id], ['id']),
                method: 'GET',
                path: '/api/v1/contracts/{id}',
                scopes: ['contracts:read'],
                pathParameters: ['id'],
            ),
            new ToolDefinition(
                name: $prefix.'search_documents',
                description: 'Sucht Dokumentmetadaten im WebDAV-Spiegel (Dateiname, Pfad, Typ, Zuordnung). Liefert keinen Dateiinhalt.',
                class: ToolClass::Read,
                inputSchema: $this->schema([
                    'q' => $q,
                    'property_id' => $id,
                    'unit_id' => $id,
                    'contact_id' => $id,
                    'case_id' => $id,
                    'document_type' => ['type' => 'string', 'maxLength' => 60],
                    'content_type' => ['type' => 'string', 'maxLength' => 120],
                    'updated_since' => $updatedSince,
                ] + $paging),
                method: 'GET',
                path: '/api/v1/documents',
                scopes: ['documents:read'],
                queryParameters: ['q', 'property_id', 'unit_id', 'contact_id', 'case_id', 'document_type', 'content_type', 'updated_since', 'page', 'per_page'],
            ),
            new ToolDefinition(
                name: $prefix.'create_case',
                description: 'Legt einen Hub-eigenen Vorgang an (kein Schreibpfad nach Immoware24). Verlangt zusätzlich den Scope mcp:write und wird mit Quelle mcp auditiert.',
                class: ToolClass::Write,
                inputSchema: $this->schema([
                    'title' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 300],
                    'status' => ['type' => 'string', 'enum' => (array) config('hub.api.case_statuses', ['open'])],
                    'property_id' => $id,
                    'unit_id' => $id,
                    'contact_id' => $id,
                    'immoware_ticket_reference' => ['type' => 'string', 'maxLength' => 64],
                ], ['title']),
                method: 'POST',
                path: '/api/v1/cases',
                scopes: ['cases:write'],
                bodyParameters: ['title', 'status', 'property_id', 'unit_id', 'contact_id', 'immoware_ticket_reference'],
            ),
            new ToolDefinition(
                name: $prefix.'propose_contact_change',
                description: 'Legt je Feld einen Änderungsvorschlag (proposed_change) zu einem Kontakt an. Kein Writeback nach Immoware24, Umsetzung erfolgt manuell. Verlangt zusätzlich den Scope mcp:write.',
                class: ToolClass::Write,
                inputSchema: $this->schema([
                    'id' => $id,
                    'changes' => [
                        'type' => 'object',
                        'minProperties' => 1,
                        'maxProperties' => 20,
                        'description' => 'Feld => neuer Wert. Erlaubte Felder: '.implode(', ', (array) config('hub.api.contact_proposal_fields', [])).'.',
                        'additionalProperties' => true,
                    ],
                    'reason' => ['type' => 'string', 'maxLength' => 1000, 'description' => 'Begründung, z. B. Quelle des Änderungswunsches.'],
                ], ['id', 'changes']),
                method: 'PATCH',
                path: '/api/v1/contacts/{id}',
                scopes: ['contacts:write'],
                pathParameters: ['id'],
                bodyParameters: ['changes', 'reason'],
            ),
            new ToolDefinition(
                name: $prefix.'change_bank_account',
                description: 'Nicht implementiert. Bankverbindungen ändert ausschließlich ein Mensch in Immoware24 (08-security.md Abschnitt 8, Schutzstufe hoch).',
                class: ToolClass::NeverAutonomous,
                inputSchema: $this->schema([]),
                reason: 'Bankverbindung ist zahlungsrelevant und schutzbedürftig. Kein Tool, kein Endpunkt, keine Freigabe durch Scope möglich.',
            ),
            new ToolDefinition(
                name: $prefix.'delete_document',
                description: 'Nicht implementiert. Löschen ist per WebDAV hart gesperrt und eine DSGVO-Entscheidung eines Menschen.',
                class: ToolClass::NeverAutonomous,
                inputSchema: $this->schema([]),
                reason: 'DELETE, MOVE und Overwrite Richtung Immoware24 sind gesperrt (CLAUDE.md Regel 2). Kein Tool, kein Endpunkt.',
            ),
        ];

        $result = [];

        foreach ($list as $tool) {
            $result[$tool->name] = $tool;
        }

        return $result;
    }

    /**
     * @param  array<string, array<string, mixed>>  $properties
     * @param  array<int, string>  $required
     * @return array<string, mixed>
     */
    private function schema(array $properties, array $required = []): array
    {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
            'additionalProperties' => false,
        ];
    }
}
