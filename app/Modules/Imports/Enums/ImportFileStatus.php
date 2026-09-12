<?php

declare(strict_types=1);

namespace App\Modules\Imports\Enums;

enum ImportFileStatus: string
{
    case Received = 'received';
    case Quarantined = 'quarantined';
    case Processing = 'processing';
    case Imported = 'imported';
    case Failed = 'failed';
}
