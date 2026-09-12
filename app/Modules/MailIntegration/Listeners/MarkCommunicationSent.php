<?php

declare(strict_types=1);

namespace App\Modules\MailIntegration\Listeners;

use App\Modules\Cases\Enums\CommunicationStatus;
use App\Modules\Cases\Exceptions\InvalidTransitionException;
use App\Modules\Cases\Models\MailCase;
use App\Modules\Cases\Services\CaseService;
use App\Modules\Gmail\Events\SendVerificationCompleted;
use App\Modules\Gmail\Models\MailDraft;
use App\Modules\Gmail\Models\MailMessage;
use App\Modules\Gmail\Services\SendReconciliationService;
use Illuminate\Support\Facades\Log;

/**
 * Gmail SendVerificationCompleted: erst der verifizierte Versand eines Hub-Entwurfs (Label SENT, Message-ID)
 * setzt den Kommunikationsstatus des Vorgangs auf gesendet und stoppt die Antwortuhren. Unverifiziert oder unklar
 * lässt den Vorgang offen und protokolliert den Zustand. Direkt in Gmail gesendete Antworten verarbeitet das Modul
 * Cases über GmailReplyDetected.
 */
final class MarkCommunicationSent
{
    public function __construct(private readonly CaseService $cases) {}

    public function handle(SendVerificationCompleted $event): void
    {
        $draft = MailDraft::query()->withoutGlobalScopes()->find($event->draftId);

        if (! $draft instanceof MailDraft || $draft->getAttribute('case_id') === null) {
            return;
        }

        $case = MailCase::query()->withoutGlobalScopes()->find($draft->getAttribute('case_id'));

        if (! $case instanceof MailCase) {
            return;
        }

        if ($event->verification !== SendReconciliationService::VERIFIED) {
            Log::warning('MailIntegration: Versand nicht verifiziert, Kommunikationsstatus bleibt offen.', ['draft_id' => $draft->getKey(), 'case_id' => $case->getKey(), 'verification' => $event->verification]);

            return;
        }

        $sent = $draft->getAttribute('sent_message_id') !== null ? MailMessage::query()->withoutGlobalScopes()->find($draft->getAttribute('sent_message_id')) : null;

        if ($sent instanceof MailMessage) {
            $this->cases->handleReplyDetected($sent);

            if ($this->cases->communicationStatus($case->refresh()) === CommunicationStatus::Sent) {
                return;
            }
        }

        try {
            $this->cases->transitionCommunication($case->refresh(), CommunicationStatus::Sent, null, 'Versand des Hub-Entwurfs verifiziert (Label SENT, Message-ID).', 'gmail');
        } catch (InvalidTransitionException $e) {
            Log::warning('MailIntegration: Kommunikationsstatus nicht gesetzt.', ['case_id' => $case->getKey(), 'reason' => $e->getMessage()]);
        }
    }
}
