<?php

declare(strict_types=1);

namespace App\Modules\Playbooks\Enums;

/**
 * Status einer Prozessvorlage. Nur active wird beim Vergleich neuer Vorgänge herangezogen; draft muss erst durch
 * eine Person geprüft und aktiviert werden (Vier-Augen-Grundsatz wie bei anderen KI-Vorschlägen).
 */
enum PlaybookStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Retired = 'retired';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Entwurf, noch nicht geprüft',
            self::Active => 'Aktiv',
            self::Retired => 'Außer Betrieb',
        };
    }
}
