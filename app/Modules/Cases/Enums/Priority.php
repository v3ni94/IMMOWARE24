<?php

declare(strict_types=1);

namespace App\Modules\Cases\Enums;

/**
 * Prioritäten P0 bis P3 (docs/mail/04-status-und-sla.md). P0 läuft in Kalenderzeit, alle anderen in Arbeitszeit.
 */
enum Priority: string
{
    case P0 = 'p0';
    case P1 = 'p1';
    case P2 = 'p2';
    case P3 = 'p3';

    public function label(): string
    {
        return match ($this) {
            self::P0 => 'P0 Notfall',
            self::P1 => 'P1 Dringend',
            self::P2 => 'P2 Normal',
            self::P3 => 'P3 Niedrig',
        };
    }

    public function short(): string
    {
        return strtoupper($this->value);
    }

    public function usesCalendarTime(): bool
    {
        return $this === self::P0;
    }

    public function rank(): int
    {
        return (int) substr($this->value, 1);
    }

    public function isHigherThan(self $other): bool
    {
        return $this->rank() < $other->rank();
    }
}
