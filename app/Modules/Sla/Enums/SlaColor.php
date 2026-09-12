<?php

declare(strict_types=1);

namespace App\Modules\Sla\Enums;

/**
 * Ampelfarbe: grün unter warn_percent, gelb ab warn_percent (Standard 50 %), rot bei Überschreitung.
 */
enum SlaColor: string
{
    case Green = 'green';
    case Yellow = 'yellow';
    case Red = 'red';

    public function rank(): int
    {
        return match ($this) {
            self::Green => 0,
            self::Yellow => 1,
            self::Red => 2,
        };
    }

    public function isWorseThan(self $other): bool
    {
        return $this->rank() > $other->rank();
    }

    public function label(): string
    {
        return match ($this) {
            self::Green => 'Grün',
            self::Yellow => 'Gelb',
            self::Red => 'Rot',
        };
    }
}
