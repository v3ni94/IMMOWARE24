<?php

declare(strict_types=1);

namespace App\Core\Enums;

enum SyncMode: string
{
    case Full = 'full';
    case Incremental = 'incremental';
    case Event = 'event';
    case Scheduled = 'scheduled';
}
