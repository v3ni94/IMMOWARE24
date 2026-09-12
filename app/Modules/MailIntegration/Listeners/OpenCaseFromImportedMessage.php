<?php

declare(strict_types=1);

namespace App\Modules\MailIntegration\Listeners;

use App\Modules\Cases\Services\CaseService;
use App\Modules\Gmail\Events\MessageImported;
use App\Modules\Gmail\Models\MailMessage;
use App\Modules\MailIntegration\Services\CaseIntakeService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Gmail MessageImported: eingehende Nachricht einem bestehenden Vorgang des Threads zuordnen (Wiedereröffnung,
 * Kommunikationsbedarf), sonst neuen Vorgang mit regelbasierten Teilanliegen anlegen. Zuordnung zu Kontakt, Objekt
 * und Vertrag nur über Kennungen (AssignmentService), Mehrdeutigkeit ergibt assignment_open. Nur neu importierte
 * Nachrichten (created), nie Aktualisierungen. Der technische Status der Nachricht (processing_status imported)
 * bleibt unverändert: gelesen oder zugeordnet ist nicht bearbeitet. Fehler werden protokolliert, nie als Erfolg.
 */
final class OpenCaseFromImportedMessage
{
    public function __construct(
        private readonly CaseService $cases,
        private readonly CaseIntakeService $intake,
    ) {}

    public function handle(MessageImported $event): void
    {
        if (! $event->created) {
            return;
        }

        $message = MailMessage::query()->withoutGlobalScopes()->find($event->messageId);

        if (! $message instanceof MailMessage || (string) $message->getAttribute('direction') !== 'inbound') {
            return;
        }

        try {
            $affected = $this->cases->handleInboundMessage($message);

            if ($affected !== []) {
                return;
            }

            $case = $this->cases->openFromMessage($message, $this->intake->specsFor($message));

            Log::info('MailIntegration: Vorgang aus Nachricht angelegt.', ['message_id' => $message->getKey(), 'case_id' => $case->getKey(), 'case_number' => $case->getAttribute('case_number')]);
        } catch (Throwable $e) {
            Log::error('MailIntegration: Vorgangsanlage fehlgeschlagen, Nachricht bleibt ohne Vorgang.', ['message_id' => $message->getKey(), 'error' => $e::class, 'reason' => $e->getMessage()]);
        }
    }
}
