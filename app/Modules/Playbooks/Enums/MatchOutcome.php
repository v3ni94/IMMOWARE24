<?php

declare(strict_types=1);

namespace App\Modules\Playbooks\Enums;

/**
 * Ergebnis eines Abgleichs. suggested ist der automatische Zwischenstand; accepted, adjusted und rejected
 * setzt eine Person beim Abschluss des Vorgangs (Grundlage für die Verbesserung der Vorlage).
 */
enum MatchOutcome: string
{
    case Suggested = 'suggested';
    case Accepted = 'accepted';
    case Adjusted = 'adjusted';
    case Rejected = 'rejected';
    case NoMatch = 'no_match';

    public function label(): string
    {
        return match ($this) {
            self::Suggested => 'Vorgeschlagen',
            self::Accepted => 'Übernommen wie vorgeschlagen',
            self::Adjusted => 'Mit Abweichungen übernommen',
            self::Rejected => 'Abgelehnt',
            self::NoMatch => 'Keine passende Vorlage',
        };
    }

    public function isDecided(): bool
    {
        return in_array($this, [self::Accepted, self::Adjusted, self::Rejected], true);
    }
}
