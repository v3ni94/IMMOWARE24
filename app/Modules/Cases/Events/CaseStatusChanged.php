<?php

declare(strict_types=1);

namespace App\Modules\Cases\Events;

use App\Modules\Cases\Models\CaseItem;
use App\Modules\Cases\Models\MailCase;

final class CaseStatusChanged
{
    public function __construct(
        public readonly MailCase $case,
        public readonly string $dimension,
        public readonly ?string $from,
        public readonly string $to,
        public readonly ?CaseItem $item = null,
    ) {}
}
