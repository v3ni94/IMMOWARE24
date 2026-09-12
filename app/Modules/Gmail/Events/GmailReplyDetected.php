<?php

declare(strict_types=1);

namespace App\Modules\Gmail\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Direkte Gmail-Antwort erkannt: Nachricht mit Label SENT in einem bekannten Thread ohne zugehörigen Hub-Entwurf. Das Modul Cases verknüpft den Vorgang; hier nur IDs.
 */
final class GmailReplyDetected
{
    use Dispatchable;

    public function __construct(public readonly int $mailboxId, public readonly int $messageId, public readonly ?int $threadId, public readonly string $gmailThreadId) {}
}
