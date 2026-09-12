<?php

declare(strict_types=1);

namespace App\Modules\MailUi\Contracts;

use App\Modules\Cases\Models\MailCase;
use App\Modules\Gmail\Models\MailDraft;
use App\Modules\MailUi\DTO\WorkflowResult;
use App\Modules\Security\Models\User;

/**
 * Dünne Schnittstelle der Oberfläche zum Modul Gmail (Entwürfe, Prüfung, Versand). Die Live-Implementierung
 * (GmailDraftService, SendAction mit Versandabgleich) bindet der Integrationsagent; bis dahin NullDraftWorkflow.
 */
interface DraftWorkflowInterface
{
    /**
     * @param  array{alias_id: ?int, to: array<int, string>, cc: array<int, string>, subject: string, body_text: string}  $data
     */
    public function createOrUpdate(MailCase $case, ?MailDraft $draft, array $data, User $actor): WorkflowResult;

    public function submitForReview(MailDraft $draft, User $actor): WorkflowResult;

    /**
     * Versand nur mit Flag gmail_send, Recht mail.send und Postfachrecht can_send. Ein HTTP-Erfolg ist kein Versand:
     * die Implementierung darf erst nach verifiziertem Abgleich (Label SENT) ok liefern.
     */
    public function send(MailDraft $draft, User $actor): WorkflowResult;
}
