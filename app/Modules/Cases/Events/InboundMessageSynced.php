<?php

declare(strict_types=1);

namespace App\Modules\Cases\Events;

use App\Modules\Gmail\Models\MailMessage;

/**
 * Fallback-Ereignis für eine importierte eingehende Nachricht. Das Modul Gmail liefert später
 * App\Modules\Gmail\Events\MessageSynced mit gleicher Eigenschaft $message; beide werden vom selben Listener verarbeitet.
 */
final class InboundMessageSynced
{
    public function __construct(public readonly MailMessage $message) {}
}
