<?php

declare(strict_types=1);

namespace App\Modules\Connector\Enums;

/**
 * Capability-Schlüssel des Hubs. Die Konzeptdokumente (05-write-capabilities.md 2.2, 01-architecture-decision.md,
 * 02-data-model.md) nennen die Zugangsweg-Schreibweise (webdav.list, webdav.create, webdav.overwrite, carddav.write, ...);
 * der Code verwendet fachliche Schlüssel. Zuordnung (Änderungsvermerk 12.09.2026 in 05 Abschnitt 2.2):
 * webdav.list, webdav.read => documents.read; webdav.create => documents.write; webdav.overwrite => documents.overwrite;
 * webdav.delete => documents.delete; webdav.move => documents.move; carddav.read/write => contacts.read/write;
 * caldav.read/write => calendar.read/write; csv.import.*, datev.import, camt.import => properties.read, units.read,
 * contracts.read, finance.read.
 */
enum CapabilityKey: string
{
    case ContactsRead = 'contacts.read';
    case ContactsWrite = 'contacts.write';
    case CalendarRead = 'calendar.read';
    case CalendarWrite = 'calendar.write';
    case DocumentsRead = 'documents.read';
    case DocumentsWrite = 'documents.write';
    /** Overwrite per WebDAV (PUT ohne If-None-Match: *): hard_locked, nie nutzbar. */
    case DocumentsOverwrite = 'documents.overwrite';
    case DocumentsDelete = 'documents.delete';
    case DocumentsMove = 'documents.move';
    case PropertiesRead = 'properties.read';
    case UnitsRead = 'units.read';
    case ContractsRead = 'contracts.read';
    case FinanceRead = 'finance.read';
    case CasesRead = 'cases.read';
    case CasesWrite = 'cases.write';

    /**
     * @return array<int, string>
     */
    public static function keys(): array
    {
        return array_map(static fn (self $key): string => $key->value, self::cases());
    }
}
