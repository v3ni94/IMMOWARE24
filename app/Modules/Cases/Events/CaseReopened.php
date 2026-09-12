<?php

declare(strict_types=1);

namespace App\Modules\Cases\Events;

use App\Modules\Cases\Models\MailCase;
use App\Modules\Gmail\Models\MailMessage;

final class CaseReopened
{
    public function __construct(public readonly MailCase $case, public readonly MailMessage $message) {}
}
