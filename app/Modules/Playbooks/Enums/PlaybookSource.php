<?php

declare(strict_types=1);

namespace App\Modules\Playbooks\Enums;

enum PlaybookSource: string
{
    case Learned = 'learned';
    case AiDrafted = 'ai_drafted';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Learned => 'Aus abgeschlossenen Vorgängen gelernt',
            self::AiDrafted => 'KI-Entwurf',
            self::Manual => 'Von Hand angelegt',
        };
    }
}
