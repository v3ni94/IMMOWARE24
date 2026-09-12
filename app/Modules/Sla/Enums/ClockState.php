<?php

declare(strict_types=1);

namespace App\Modules\Sla\Enums;

enum ClockState: string
{
    case Running = 'running';
    case Paused = 'paused';
    case Met = 'met';
    case Breached = 'breached';
    case Cancelled = 'cancelled';

    public function isActive(): bool
    {
        return in_array($this, [self::Running, self::Paused], true);
    }
}
