<?php

declare(strict_types=1);

namespace App\Modules\Cases\Events;

use App\Modules\Gmail\Models\MailMessage;

/**
 * Fallback-Ereignis für eine in Gmail erkannte gesendete Antwort (Versandabgleich). Das Modul Gmail liefert später
 * App\Modules\Gmail\Events\GmailReplyDetected mit gleicher Eigenschaft $message.
 */
final class OutboundReplyDetected
{
    public function __construct(public readonly MailMessage $message) {}
}
