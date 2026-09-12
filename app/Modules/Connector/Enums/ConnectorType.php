<?php

declare(strict_types=1);

namespace App\Modules\Connector\Enums;

/**
 * Adapter-Namen des ConnectorManagers. Abbildung von immoware_connections.connector_type
 * auf den zuständigen Adapter erfolgt über fromConnectorType().
 */
enum ConnectorType: string
{
    case WebDav = 'webdav';
    case CardDav = 'carddav';
    case CalDav = 'caldav';
    case FileImport = 'file_import';
    case RestApiSlot = 'rest_api_slot';

    public static function fromConnectorType(string $connectorType): self
    {
        $normalized = strtolower(trim($connectorType));

        return match (true) {
            str_starts_with($normalized, 'webdav') => self::WebDav,
            str_starts_with($normalized, 'carddav') => self::CardDav,
            str_starts_with($normalized, 'caldav') => self::CalDav,
            str_starts_with($normalized, 'file_import'),
            str_starts_with($normalized, 'csv'),
            str_starts_with($normalized, 'datev'),
            str_starts_with($normalized, 'camt') => self::FileImport,
            str_starts_with($normalized, 'rest_api') => self::RestApiSlot,
            default => throw new \InvalidArgumentException(sprintf('Unbekannter connector_type "%s".', $connectorType)),
        };
    }

    public function isDav(): bool
    {
        return in_array($this, [self::WebDav, self::CardDav, self::CalDav], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::WebDav => 'WebDAV',
            self::CardDav => 'CardDAV',
            self::CalDav => 'CalDAV',
            self::FileImport => 'Dateiimport',
            self::RestApiSlot => 'REST-API-Slot (WAITING_FOR_VENDOR_ACCESS)',
        };
    }
}
