<?php

declare(strict_types=1);

namespace App\Modules\Learning\Enums;

enum LearningRunStatus: string
{
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Running => 'Läuft',
            self::Succeeded => 'Abgeschlossen',
            self::Failed => 'Fehlgeschlagen',
        };
    }
}
