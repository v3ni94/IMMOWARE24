<?php

declare(strict_types=1);

namespace App\Modules\Playbooks\Enums;

enum MatchMethod: string
{
    case Rule = 'rule';
    case Ai = 'ai';

    public function label(): string
    {
        return match ($this) {
            self::Rule => 'Regelbasiert (ohne KI)',
            self::Ai => 'KI-gestützt',
        };
    }
}
