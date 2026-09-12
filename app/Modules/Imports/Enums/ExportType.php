<?php

declare(strict_types=1);

namespace App\Modules\Imports\Enums;

/**
 * Exporttypen der manuell erzeugten Immoware24-Dateien.
 */
enum ExportType: string
{
    case Properties = 'properties';
    case Units = 'units';
    case TenantsContracts = 'tenants_contracts';
    case OwnersOwnerships = 'owners_ownerships';
    case OpenItems = 'open_items';
    case ContactsCsv = 'contacts_csv';
    case DatevBuchungsstapel = 'datev_buchungsstapel';
    case Camt053 = 'camt053';

    public function isCsvMapped(): bool
    {
        return ! in_array($this, [self::DatevBuchungsstapel, self::Camt053], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Properties => 'Objekte',
            self::Units => 'Verwaltungseinheiten',
            self::TenantsContracts => 'Belegungsliste (Mieter und Verträge)',
            self::OwnersOwnerships => 'Eigentümer und Eigentumsanteile (WEG)',
            self::OpenItems => 'Offene Posten (Snapshot)',
            self::ContactsCsv => 'Adressbuch (CSV)',
            self::DatevBuchungsstapel => 'DATEV-Buchungsstapel',
            self::Camt053 => 'Kontoauszug camt.053',
        };
    }
}
