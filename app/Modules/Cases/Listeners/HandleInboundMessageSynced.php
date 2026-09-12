<?php

declare(strict_types=1);

namespace App\Modules\Cases\Listeners;

use App\Modules\Cases\Services\CaseService;
use App\Modules\Gmail\Models\MailMessage;

/**
 * Neue eingehende Nachricht: bestehende Vorgänge des Threads verknüpfen und bei Bedarf wiedereröffnen.
 * Ohne passenden Vorgang geschieht nichts; die Vorgangsanlage entscheidet Sachbearbeitung oder Klassifizierung.
 */
final class HandleInboundMessageSynced
{
    public function __construct(private readonly CaseService $cases) {}

    public function handle(object $event): void
    {
        $message = MessageFromEvent::resolve($event);

        if ($message instanceof MailMessage && (string) $message->direction === 'inbound') {
            $this->cases->handleInboundMessage($message);
        }
    }
}
