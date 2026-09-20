<?php

declare(strict_types=1);

namespace App\Modules\Learning\Enums;

/**
 * Datenquelle eines Lernlaufs. Entspricht den belegten Zugangswegen zu Immoware24 (docs/immoware/01-interface-discovery.md):
 * WebDAV, CardDAV, CalDAV und manuell erzeugte Dateiexporte. Es gibt keine weitere Quelle.
 */
enum LearningKind: string
{
    case WebDav = 'webdav';
    case CardDav = 'carddav';
    case CalDav = 'caldav';
    case Imports = 'imports';

    public function label(): string
    {
        return match ($this) {
            self::WebDav => 'WebDAV (Dokumente)',
            self::CardDav => 'CardDAV (Kontakte)',
            self::CalDav => 'CalDAV (Kalender)',
            self::Imports => 'Dateiexporte (CSV, DATEV, CAMT)',
        };
    }

    /**
     * Ob diese Art einer gebundenen Immoware24-Connection bedarf. Dateiexporte werden aus bereits im Hub
     * vorliegenden Formaten ausgewertet (import_formats) und brauchen keine Verbindung.
     */
    public function requiresConnection(): bool
    {
        return $this !== self::Imports;
    }

    /**
     * Werte der Spalte immoware_connections.connector_type, die zu dieser Art passen. Für WebDAV nur die
     * lesende Verbindung (webdav_documents), nicht der Schreibpfad webdav_inbox (create-only Posteingang,
     * eigener, enger Zugang; die Lernphase erkundet ausschließlich lesend).
     *
     * @return array<int, string>
     */
    public function connectorTypeValues(): array
    {
        return match ($this) {
            self::WebDav => ['webdav_documents'],
            self::CardDav => ['carddav_contacts'],
            self::CalDav => ['caldav_calendar'],
            self::Imports => [],
        };
    }
}
