<?php

declare(strict_types=1);

namespace App\Modules\Sync\Enums;

enum ProposedChangeStatus: string
{
    case Open = 'open';
    case Transferred = 'transferred';
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';
}
