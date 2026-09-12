<?php

declare(strict_types=1);

namespace Tests\Feature\Cases;

use App\Modules\Cases\Enums\CaseStatus;
use App\Modules\Cases\Enums\CommunicationStatus;
use App\Modules\Cases\Enums\TaskStatus;
use App\Modules\Cases\Events\InboundMessageSynced;
use App\Modules\Cases\Events\OutboundReplyDetected;
use App\Modules\Cases\Exceptions\CaseNotClosableException;
use App\Modules\Cases\Models\CaseMessage;
use App\Modules\Cases\Services\CaseService;
use App\Modules\Cases\Services\TaskService;
use App\Modules\Sla\Enums\ClockType;
use App\Modules\Sla\Models\SlaClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

/**
 * Abnahmefall 3: Eine in Gmail erkannte Antwort schließt nur den Kommunikationsbedarf, nicht den Vorgang.
 */
final class GmailReplyTest extends CasesTestCase
{
    public function test_reply_sets_communication_sent_but_keeps_tasks_and_processing(): void
    {
        Queue::fake();
        $this->travelTo(CarbonImmutable::parse('2026-09-09 10:00', 'Europe/Berlin'));
        $user = $this->actingAsMailRole('agent');
        $message = $this->inboundMessage($this->mailbox, ['subject' => 'Neue Bankverbindung', 'body_text' => 'Bitte ändern Sie meine Bankverbindung.']);
        $cases = $this->app->make(CaseService::class);
        $case = $cases->openFromMessage($message, [['item_type' => 'bankdaten', 'title' => 'Bankdaten ändern', 'assignee_user_id' => $user->getKey()]], $user);
        $item = $case->items()->firstOrFail();
        $task = $this->app->make(TaskService::class)->create($case, $item, ['task_type' => 'manual_change_immoware', 'title' => 'IBAN in Immoware24 ändern'], $user);

        $this->travelTo(CarbonImmutable::parse('2026-09-09 10:20', 'Europe/Berlin'));
        $reply = $this->outboundReply($message);
        $this->app->make('events')->dispatch(new OutboundReplyDetected($reply));

        $case->refresh();
        $this->assertSame(CommunicationStatus::Sent, $case->status_communication, 'Beantwortet.');
        $this->assertSame(CaseStatus::Open, $case->status_processing, 'Aber nicht erledigt.');
        $this->assertSame(TaskStatus::Open->value, $task->refresh()->status);
        $this->assertNotNull($case->first_response_at);
        $this->assertTrue(CaseMessage::query()->where('case_id', $case->getKey())->where('message_id', $reply->getKey())->where('link_type', 'reply')->exists());

        $reset = SlaClock::query()->where('case_item_id', $item->getKey())->where('clock_type', ClockType::FirstQualifiedReply->value)->firstOrFail();
        $this->assertSame('met', $reset->state);
        $resolution = SlaClock::query()->where('case_item_id', $item->getKey())->where('clock_type', ClockType::Resolution->value)->firstOrFail();
        $this->assertSame('running', $resolution->state, 'Lösungsuhr läuft weiter.');

        try {
            $cases->transitionProcessing($item->refresh(), CaseStatus::Resolved, $user, 'Versuch');
            $this->fail('Teilanliegen darf mit offener Pflichtaufgabe nicht gelöst werden.');
        } catch (CaseNotClosableException $e) {
            $this->assertStringContainsString('Pflichtaufgabe', implode(' ', $e->unmet));
            $this->assertStringContainsString('Verifiziert', implode(' ', $e->unmet));
        }
    }

    public function test_automatic_acknowledgement_stops_only_acknowledge_clock(): void
    {
        Queue::fake();
        $user = $this->actingAsMailRole('agent');
        $message = $this->inboundMessage($this->mailbox);
        $cases = $this->app->make(CaseService::class);
        $case = $cases->openFromMessage($message, [['item_type' => 'anfrage_allgemein', 'title' => 'Frage', 'assignee_user_id' => $user->getKey()]], $user);

        $cases->acknowledgeAutomatically($case, CarbonImmutable::now());

        $this->assertSame(CommunicationStatus::Acknowledged, $case->refresh()->status_communication);
        $item = $case->items()->firstOrFail();
        $this->assertSame('met', SlaClock::query()->where('case_item_id', $item->getKey())->where('clock_type', ClockType::Acknowledge->value)->value('state'));
        $this->assertSame('running', SlaClock::query()->where('case_item_id', $item->getKey())->where('clock_type', ClockType::FirstQualifiedReply->value)->value('state'));
    }

    public function test_new_inbound_message_reopens_resolved_case_and_keeps_thread_link(): void
    {
        Queue::fake();
        $user = $this->actingAsMailRole('agent');
        $message = $this->inboundMessage($this->mailbox);
        $cases = $this->app->make(CaseService::class);
        $case = $cases->openFromMessage($message, [['item_type' => 'anfrage_allgemein', 'title' => 'Frage', 'assignee_user_id' => $user->getKey()]], $user);
        $item = $case->items()->firstOrFail();

        $this->app->make('events')->dispatch(new OutboundReplyDetected($this->outboundReply($message)));
        $cases->transitionProcessing($item->refresh(), CaseStatus::Resolved, $user, 'Auskunft erteilt.');
        $this->assertSame(CaseStatus::Resolved, $case->refresh()->status_processing);

        $followUp = $this->inboundMessage($this->mailbox, ['gmail_thread_id' => $message->thread->gmail_thread_id, 'subject' => 'Re: Anfrage', 'body_text' => 'Danke, noch eine Frage.']);
        $this->app->make('events')->dispatch(new InboundMessageSynced($followUp));

        $case->refresh();
        $this->assertSame(CaseStatus::Reopened, $case->status_processing);
        $this->assertSame(CommunicationStatus::ReplyNeeded, $case->status_communication);
        $this->assertSame(1, (int) $case->reopen_count);
        $this->assertNull($case->resolved_at);
        $this->assertSame(3, CaseMessage::query()->where('case_id', $case->getKey())->count(), 'Thread und Vorgang bleiben n:m verknüpft.');
    }
}
