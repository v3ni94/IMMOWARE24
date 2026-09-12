<?php

declare(strict_types=1);

namespace App\Modules\Sync\Enums;

/**
 * Entitätstypen, die die Sync-Engine orchestriert. Werte entsprechen SyncRequest::entityType.
 */
enum SyncEntity: string
{
    case Document = 'document';
    case Contact = 'contact';
    case CalendarEvent = 'calendar_event';

    public function label(): string
    {
        return match ($this) {
            self::Document => 'Dokumente',
            self::Contact => 'Kontakte',
            self::CalendarEvent => 'Kalender',
        };
    }

    /**
     * Passender Adaptername (ConnectorType) je Entität.
     */
    public function connectorName(): string
    {
        return match ($this) {
            self::Document => 'webdav',
            self::Contact => 'carddav',
            self::CalendarEvent => 'caldav',
        };
    }

    /**
     * Quellformat des Default-Mappings.
     */
    public function sourceFormat(): string
    {
        return match ($this) {
            self::Document => 'webdav',
            self::Contact => 'vcard',
            self::CalendarEvent => 'ical',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $e): string => $e->value, self::cases());
    }
}
