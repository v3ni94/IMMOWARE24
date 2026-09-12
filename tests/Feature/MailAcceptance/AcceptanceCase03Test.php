<?php

declare(strict_types=1);

namespace Tests\Feature\MailAcceptance;

use App\Modules\Cases\Enums\CaseStatus;
use App\Modules\Cases\Enums\CommunicationStatus;
use App\Modules\Cases\Enums\TaskStatus;
use App\Modules\Cases\Models\CaseMessage;
use App\Modules\Cases\Services\TaskService;
use Tests\Feature\MailEndToEnd\MailEndToEndTestCase;

/**
 * Abnahmefall 3: Eine Antwort direkt in Gmail wird erkannt, offene Fachaufgaben bleiben bestehen.
 *
 * Durchstich über den echten Importpfad: Pub/Sub-Push, History-Abgleich mit FakeGmailProvider, Erkennung der
 * gesendeten Nachricht im Thread (Label SENT), Ereigniskette GmailReplyDetected zu CaseService::handleReplyDetected.
 * Kein Entwurf und kein Versand über den Hub.
 */
final class AcceptanceCase03Test extends MailEndToEndTestCase
{
    public function test_reply_sent_directly_in_gmail_sets_communication_sent_and_keeps_open_tasks(): void
    {
        $inbound = $this->receive([
            'from' => 'Max Mieter <mieter@example.com>',
            'to' => self::MAILBOX_EMAIL,
            'subject' => 'Heizung ausgefallen',
            'text' => 'Guten Tag, die Heizung ist seit gestern ausgefallen.',
            'thread_id' => 'thread-fall-3',
        ]);
        $case = $this->caseOf($inbound);
        $item = $case->items()->firstOrFail();
        $task = $this->app->make(TaskService::class)->create($case, $item, ['task_type' => 'contractor_order', 'title' => 'Heizungsbauer beauftragen'], $this->author);

        $this->assertNotSame(CommunicationStatus::Sent, $case->status_communication);

        // Interne Weiterleitung im selben Thread an einen Dritten ist keine Antwort an den Absender.
        $this->gmail->seedMessage((int) $this->box->getKey(), [
            'from' => self::MAILBOX_EMAIL,
            'to' => 'handwerker@example.org',
            'subject' => 'WG: Heizung ausgefallen',
            'text' => 'Bitte um Terminvorschlag.',
            'thread_id' => 'thread-fall-3',
            'label_ids' => ['SENT'],
        ]);
        $this->push($this->gmail->currentHistoryId((int) $this->box->getKey()), 'push-fall-3-forward')->assertNoContent();

        $case->refresh();
        $this->assertNotSame(CommunicationStatus::Sent, $case->status_communication, 'Weiterleitung an Dritte ist keine Antwort.');
        $this->assertTrue(CaseMessage::query()->where('case_id', $case->getKey())->where('link_type', 'forwarded')->exists());

        // Antwort direkt in Gmail an den Absender, ohne Hub-Entwurf.
        $sentId = $this->gmail->seedMessage((int) $this->box->getKey(), [
            'from' => self::MAILBOX_EMAIL,
            'to' => 'mieter@example.com',
            'subject' => 'Re: Heizung ausgefallen',
            'text' => 'Der Heizungsbauer meldet sich morgen bei Ihnen.',
            'thread_id' => 'thread-fall-3',
            'label_ids' => ['SENT'],
        ]);
        $this->push($this->gmail->currentHistoryId((int) $this->box->getKey()), 'push-fall-3-reply')->assertNoContent();

        $case->refresh();
        $this->assertSame(CommunicationStatus::Sent, $case->status_communication, 'Antwort in Gmail erkannt.');
        $this->assertNotNull($case->first_response_at);
        $this->assertNotContains($case->status_processing, [CaseStatus::Resolved, CaseStatus::Closed], 'Beantwortet ist nicht erledigt.');
        $this->assertNotContains($item->refresh()->status_processing, [CaseStatus::Resolved, CaseStatus::Closed], 'Teilanliegen bleibt offen.');
        $this->assertSame(TaskStatus::Open->value, $task->refresh()->status, 'Fachaufgabe bleibt offen.');
        $this->assertNull($case->resolved_at);

        $link = CaseMessage::query()->where('case_id', $case->getKey())->where('link_type', 'reply')->firstOrFail();
        $this->assertSame($sentId, $link->message()->firstOrFail()->getAttribute('gmail_message_id'));

        $this->assertSame([], $this->gmail->calls('sendDraft'), 'Kein Versand über den Hub.');
        $this->assertSame([], $this->gmail->calls('createDraft'), 'Kein Entwurf über den Hub.');
        $this->assertDatabaseCount('mail_drafts', 0);
    }
}
