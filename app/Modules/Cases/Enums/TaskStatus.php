<?php

declare(strict_types=1);

namespace App\Modules\Cases\Enums;

/**
 * Aufgabenstatus. Erledigt ist nur done_manual_confirmed (manuell bestätigt) oder done_verified (im Zielsystem
 * nachgelesen oder durch zweite Person bestätigt).
 */
enum TaskStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Waiting = 'waiting';
    case DoneManualConfirmed = 'done_manual_confirmed';
    case DoneVerified = 'done_verified';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Offen',
            self::InProgress => 'In Bearbeitung',
            self::Waiting => 'Wartet',
            self::DoneManualConfirmed => 'Manuell bestätigt',
            self::DoneVerified => 'Verifiziert',
            self::Cancelled => 'Storniert',
        };
    }

    public function isDone(): bool
    {
        return in_array($this, [self::DoneManualConfirmed, self::DoneVerified], true);
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Open, self::InProgress, self::Waiting], true);
    }

    /**
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Open => [self::InProgress, self::Waiting, self::DoneManualConfirmed, self::Cancelled],
            self::InProgress => [self::Waiting, self::DoneManualConfirmed, self::Open, self::Cancelled],
            self::Waiting => [self::InProgress, self::Open, self::Cancelled],
            self::DoneManualConfirmed => [self::DoneVerified, self::Open],
            self::DoneVerified => [],
            self::Cancelled => [self::Open],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
