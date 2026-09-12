<?php

declare(strict_types=1);

namespace App\Core\Enums;

enum SyncStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Aborted = 'aborted';
    case Skipped = 'skipped';
}
