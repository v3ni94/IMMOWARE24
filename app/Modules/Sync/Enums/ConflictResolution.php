<?php

declare(strict_types=1);

namespace App\Modules\Sync\Enums;

enum ConflictResolution: string
{
    case Local = 'local';
    case Remote = 'remote';
    case Manual = 'manual';

    public function status(): string
    {
        return match ($this) {
            self::Local => 'resolved_keep_local',
            self::Remote => 'resolved_keep_remote',
            self::Manual => 'resolved_manual',
        };
    }
}
