<?php

declare(strict_types=1);

namespace App\Modules\Imports\Enums;

enum HubExportStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
}
