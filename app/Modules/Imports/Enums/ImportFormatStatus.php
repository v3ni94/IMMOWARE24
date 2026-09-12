<?php

declare(strict_types=1);

namespace App\Modules\Imports\Enums;

/**
 * Statuswerte gemäß docs/immoware/07-sync-strategy.md Abschnitt 9 und Spaltendefault der Migration (Änderung 12.09.2026,
 * vorher unknown/confirmed):
 * draft: Fingerprint automatisch erfasst, Mapping fehlt, Import gesperrt.
 * active: Mapping und Schlüsselschema vom Administrator bestätigt (confirmed_by, confirmed_at), Import erlaubt.
 * retired: Format nicht mehr in Gebrauch.
 */
enum ImportFormatStatus: string
{
    case Unknown = 'draft';
    case Confirmed = 'active';
    case Retired = 'retired';
}
