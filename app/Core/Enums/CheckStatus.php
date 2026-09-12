<?php

declare(strict_types=1);

namespace App\Core\Enums;

enum CheckStatus: string
{
    case Ok = 'ok';
    case Failed = 'failed';
    case Disabled = 'disabled';
    case Unknown = 'unknown';
    case Skipped = 'skipped';
}
