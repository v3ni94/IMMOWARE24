<?php

declare(strict_types=1);

namespace App\Modules\Sla\Enums;

/**
 * Vier Uhren je Teilanliegen (docs/mail/04-status-und-sla.md). Alle starten mit der Empfangszeit der Nachricht
 * (Gmail internalDate), nie mit dem Importzeitpunkt.
 */
enum ClockType: string
{
    case Acknowledge = 'acknowledge';
    case FirstQualifiedReply = 'first_qualified_reply';
    case NextUpdate = 'next_update';
    case Resolution = 'resolution';

    public function label(): string
    {
        return match ($this) {
            self::Acknowledge => 'Annahme',
            self::FirstQualifiedReply => 'Erste qualifizierte Antwort',
            self::NextUpdate => 'Nächster Zwischenstand',
            self::Resolution => 'Lösung',
        };
    }

    /**
     * Nur die Lösungsuhr darf bei waiting_external pausieren (begrenzt, config hub.sla.max_pause_minutes).
     */
    public function mayPause(): bool
    {
        return $this === self::Resolution;
    }
}
