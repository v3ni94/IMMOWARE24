<?php

declare(strict_types=1);

namespace App\Modules\Imports\Enums;

/**
 * unknown: Fingerprint automatisch erfasst, Mapping fehlt, Import gesperrt.
 * confirmed: Mapping vom Administrator bestätigt, Import erlaubt.
 * retired: Format nicht mehr in Gebrauch.
 */
enum ImportFormatStatus: string
{
    case Unknown = 'unknown';
    case Confirmed = 'confirmed';
    case Retired = 'retired';
}
