<?php

declare(strict_types=1);

namespace App\Modules\Connector\Enums;

enum CapabilityKey: string
{
    case ContactsRead = 'contacts.read';
    case ContactsWrite = 'contacts.write';
    case CalendarRead = 'calendar.read';
    case CalendarWrite = 'calendar.write';
    case DocumentsRead = 'documents.read';
    case DocumentsWrite = 'documents.write';
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
