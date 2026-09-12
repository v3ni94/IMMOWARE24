<?php

declare(strict_types=1);

namespace App\Modules\Connector\Support;

use App\Modules\Connector\Enums\ConnectorType;
use App\Modules\Connector\Models\ImmowareConnection;
use InvalidArgumentException;

/**
 * Herkunftsangabe connector für den Provenance-Block der API (09-api-documentation.md): der Adaptername
 * (webdav, carddav, caldav, file_import, rest_api_slot) statt des rohen connector_type der Connection.
 * Auflösung über die Connection (connector_type) oder, ohne Connection, über den Entitätstyp.
 * Änderungsvermerk 12.09.2026.
 */
final class ConnectorProvenance
{
    /** @var array<string, string> Entitätstyp => Adaptername */
    private const array ENTITY_ADAPTERS = [
        'document' => 'webdav',
        'document_folder' => 'webdav',
        'contact' => 'carddav',
        'contact_role' => 'carddav',
        'company' => 'carddav',
        'calendar_event' => 'caldav',
        'property' => 'file_import',
        'unit' => 'file_import',
        'contract' => 'file_import',
        'ownership' => 'file_import',
        'open_item' => 'file_import',
        'booking' => 'file_import',
        'bank_transaction' => 'file_import',
        'bank_account' => 'file_import',
        'case' => 'rest_api_slot',
    ];

    /**
     * Adaptername einer Connection bzw. eines connector_type-Werts (webdav_documents => webdav, csv_export => file_import).
     */
    public static function connectorName(ImmowareConnection|string $connection): ?string
    {
        $type = $connection instanceof ImmowareConnection ? (string) $connection->getAttribute('connector_type') : $connection;

        try {
            return ConnectorType::fromConnectorType($type)->value;
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Adaptername je Entitätstyp, wenn keine Connection bekannt ist (z. B. Hub-eigene oder importierte Datensätze).
     */
    public static function forEntityType(string $entityType): ?string
    {
        return self::ENTITY_ADAPTERS[strtolower(trim($entityType))] ?? null;
    }

    /**
     * @return array<int, string>
     */
    public static function adapterNames(): array
    {
        return array_map(static fn (ConnectorType $type): string => $type->value, ConnectorType::cases());
    }
}
