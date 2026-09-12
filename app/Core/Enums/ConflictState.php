<?php

declare(strict_types=1);

namespace App\Core\Enums;

enum ConflictState: string
{
    case NoConflict = 'no_conflict';
    case LocalNewer = 'local_newer';
    case RemoteNewer = 'remote_newer';
    case BothChanged = 'both_changed';
}
