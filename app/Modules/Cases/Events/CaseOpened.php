<?php

declare(strict_types=1);

namespace App\Modules\Cases\Events;

use App\Modules\Cases\Models\MailCase;

final class CaseOpened
{
    public function __construct(public readonly MailCase $case) {}
}
