<?php

declare(strict_types=1);

namespace App\Modules\Sync\Enums;

enum DlqStatus: string
{
    case Open = 'open';
    case Retrying = 'retrying';
    case Replayed = 'replayed';
    case Ignored = 'ignored';
    case Failed = 'failed';
}
