<?php

declare(strict_types=1);

namespace App\Modules\Cases\Listeners;

use App\Modules\Cases\Services\CaseService;
use App\Modules\Gmail\Models\MailMessage;

/**
 * In Gmail erkannte Antwort: Kommunikationsstatus der verknüpften Vorgänge auf sent, Aufgaben bleiben unverändert.
 * Beantwortet ist nicht erledigt.
 */
final class HandleGmailReplyDetected
{
    public function __construct(private readonly CaseService $cases) {}

    public function handle(object $event): void
    {
        $message = MessageFromEvent::resolve($event);

        if ($message instanceof MailMessage) {
            $this->cases->handleReplyDetected($message);
        }
    }
}
