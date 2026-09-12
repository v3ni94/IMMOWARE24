<?php

declare(strict_types=1);

namespace App\Modules\Ai\Enums;

/**
 * Entscheidungsstand eines KI-Vorschlags. Nur accepted darf von der Fachlogik übernommen werden, und auch das erst
 * durch die Entscheidung einer Person (decided_by).
 */
enum SuggestionStatus: string
{
    case Proposed = 'proposed';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Superseded = 'superseded';

    public function label(): string
    {
        return match ($this) {
            self::Proposed => 'Vorgeschlagen',
            self::Accepted => 'Übernommen',
            self::Rejected => 'Abgelehnt',
            self::Superseded => 'Überholt',
        };
    }

    public function isDecided(): bool
    {
        return $this === self::Accepted || $this === self::Rejected;
    }
}
