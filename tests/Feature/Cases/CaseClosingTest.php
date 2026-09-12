<?php

declare(strict_types=1);

namespace Tests\Feature\Cases;

use App\Modules\Actions\Enums\ActionStatus;
use App\Modules\Cases\Enums\CaseStatus;
use App\Modules\Cases\Enums\CommunicationStatus;
use App\Modules\Cases\Events\OutboundReplyDetected;
use App\Modules\Cases\Exceptions\CaseNotClosableException;
use App\Modules\Cases\Exceptions\InvalidTransitionException;
use App\Modules\Cases\Exceptions\PermissionDeniedException;
use App\Modules\Cases\Models\CaseStatusLog;
use App\Modules\Cases\Services\CaseService;
use App\Modules\Cases\Services\TaskService;
use App\Modules\Security\Models\User;
use App\Modules\Sla\Enums\ClockType;
use App\Modules\Sla\Models\SlaClock;
use App\Modules\Sla\Models\SlaClockLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

/**
 * Abnahmefall 18: reine Auskunft ohne Schreibaktion ist abschließbar, Stammdatenänderung erst nach verifizierter
 * Aufgabe; Ausnahmeabschluss nur mit Recht und Begründung; Archivieren ist keine Erledigung; Zwischenzustände protokolliert.
 */
final class CaseClosingTest extends CasesTestCase
{
    public function test_information_case_closes_without_write_action_once_communication_is_sent(): void
    {
        Queue::fake();
        $user = $this->actingAsMailRole('agent');
        $message = $this->inboundMessage($this->mailbox, ['subject' => 'Frage zum Hausgeld']);
        $cases = $this->app->make(CaseService::class);
        $case = $cases->openFromMessage($message, [['item_type' => 'anfrage_allgemein', 'title' => 'Auskunft Hausgeld', 'assignee_user_id' => $user->getKey(), 'next_step' => 'Antwort schreiben']], $user);
        $item = $case->items()->firstOrFail();

        try {
            $cases->transitionProcessing($item, CaseStatus::Resolved, $user, 'zu früh');
            $this->fail('Ohne Antwort darf die Auskunft nicht gelöst werden.');
        } catch (CaseNotClosableException $e) {
            $this->assertStringContainsString('Kommunikation nicht abgeschlossen', implode(' ', $e->unmet));
        }

        $cases->transitionProcessing($item->refresh(), CaseStatus::InProgress, $user, 'Prüfe Unterlagen.');
        $this->app->make('events')->dispatch(new OutboundReplyDetected($this->outboundReply($message)));

        $item = $cases->transitionProcessing($item->refresh(), CaseStatus::Resolved, $user, 'Auskunft erteilt.');
        $this->assertSame(CaseStatus::Resolved, $item->status_processing);
        $this->assertSame(ActionStatus::Proposed, $item->status_business, 'Keine Schreibaktion nötig.');
        $this->assertSame(CaseStatus::Resolved, $case->refresh()->status_processing);
        $this->assertNotNull($case->resolved_at);

        $closed = $cases->transitionProcessing($case, CaseStatus::Closed, $user, 'Abgeschlossen.');
        $this->assertSame(CaseStatus::Closed, $closed->status_processing);
        $this->assertFalse((bool) $closed->closed_by_exception);
        $this->assertSame('met', SlaClock::query()->where('case_item_id', $item->getKey())->where('clock_type', ClockType::Resolution->value)->value('state'));

        $sequence = CaseStatusLog::query()->where('case_id', $case->getKey())->where('dimension', 'processing')->whereNotNull('case_item_id')->orderBy('id')->pluck('to_status')->all();
        $this->assertSame(['new', 'open', 'in_progress', 'resolved'], $sequence, 'Jeder Zwischenzustand ist protokolliert.');
        $this->assertGreaterThan(0, SlaClockLog::query()->where('case_id', $case->getKey())->where('event', 'stopped_met')->count());
    }

    public function test_direct_close_from_new_is_refused_while_conditions_are_open_and_needs_exception_close(): void
    {
        Queue::fake();
        $agent = $this->actingAsMailRole('agent');
        $message = $this->inboundMessage($this->mailbox, ['subject' => 'Frage zur Abrechnung']);
        $cases = $this->app->make(CaseService::class);
        // Ohne Verantwortlichen bleibt der Vorgang new, Kommunikation reply_needed.
        $case = $cases->openFromMessage($message, [['item_type' => 'anfrage_allgemein', 'title' => 'Auskunft Abrechnung']], null, [], false);
        $this->assertSame(CaseStatus::New, $case->status_processing);

        try {
            $cases->transitionProcessing($case, CaseStatus::Closed, $agent, 'Erledigt.');
            $this->fail('Ein neuer Vorgang mit offener Kommunikation darf nicht direkt geschlossen werden.');
        } catch (CaseNotClosableException $e) {
            $this->assertStringContainsString('Kommunikation nicht abgeschlossen', implode(' ', $e->unmet));
        }

        $case->refresh();
        $this->assertSame(CaseStatus::New, $case->status_processing);
        $this->assertNull($case->closed_at);
        $this->assertFalse((bool) $case->closed_by_exception);
        $this->assertSame(CaseStatus::New, $case->items()->firstOrFail()->status_processing, 'Teilanliegen bleibt offen.');
        $this->assertSame(0, CaseStatusLog::query()->where('case_id', $case->getKey())->where('to_status', 'closed')->count());

        // Ausnahmeabschluss (Spam, Fehlzuordnung) nur mit Recht und Begründung, als Ausnahme protokolliert.
        $lead = $this->actingAsMailRole('lead', $this->mailbox);
        $this->attachMailRole($lead, $this->mailbox, 'lead');
        $closed = $cases->closeWithException($case, $lead, 'Spam, keine Anfrage.');
        $this->assertSame(CaseStatus::Closed, $closed->status_processing);
        $this->assertTrue((bool) $closed->closed_by_exception);
    }

    public function test_master_data_change_requires_verified_task_and_business_result(): void
    {
        Queue::fake();
        $agent = $this->actingAsMailRole('agent');
        $reviewer = User::factory()->for($agent->organization)->create();
        $message = $this->inboundMessage($this->mailbox, ['subject' => 'Neue Adresse']);
        $cases = $this->app->make(CaseService::class);
        $tasks = $this->app->make(TaskService::class);
        $case = $cases->openFromMessage($message, [['item_type' => 'adressaenderung', 'title' => 'Adresse ändern', 'assignee_user_id' => $agent->getKey()]], $agent);
        $item = $case->items()->firstOrFail();
        $this->app->make('events')->dispatch(new OutboundReplyDetected($this->outboundReply($message)));

        $task = $tasks->create($case, $item, ['task_type' => 'manual_change_immoware', 'title' => 'Anschrift in Immoware24 ändern', 'old_value_json' => ['street' => 'Alt 1'], 'new_value_json' => ['street' => 'Neu 2']], $agent);
        $tasks->confirmManual($task, $agent);

        try {
            $tasks->verify($task->refresh(), $agent);
            $this->fail('Verifikation durch dieselbe Person ist unzulässig.');
        } catch (InvalidTransitionException) {
            $this->addToAssertionCount(1);
        }

        $tasks->verify($task->refresh(), $reviewer);
        $this->assertSame('done_verified', $task->refresh()->status);

        try {
            $cases->transitionProcessing($item->refresh(), CaseStatus::Resolved, $agent, 'Versuch');
            $this->fail('Ohne verifiziertes Geschäftsergebnis nicht lösbar.');
        } catch (CaseNotClosableException $e) {
            $this->assertStringContainsString('Verifiziert', implode(' ', $e->unmet));
        }

        // Ein erfolgreicher Aufruf ist nur executed, erst das Nachlesen ergibt verified.
        foreach ([ActionStatus::Validated, ActionStatus::Scheduled, ActionStatus::Executing, ActionStatus::Executed] as $status) {
            $item = $cases->transitionBusiness($item->refresh(), $status, $agent);
        }

        try {
            $cases->transitionProcessing($item->refresh(), CaseStatus::Resolved, $agent, 'Versuch');
            $this->fail('executed ist kein Geschäftsergebnis.');
        } catch (CaseNotClosableException) {
            $this->addToAssertionCount(1);
        }

        $cases->transitionBusiness($item->refresh(), ActionStatus::Verified, $reviewer, 'Im Zielsystem nachgelesen.');
        $item = $cases->transitionProcessing($item->refresh(), CaseStatus::Resolved, $agent, 'Änderung verifiziert.');
        $this->assertSame(CaseStatus::Resolved, $item->status_processing);
        $this->assertSame(ActionStatus::Verified, $case->refresh()->status_business);
    }

    public function test_exception_close_needs_permission_and_reason_and_archive_is_not_completion(): void
    {
        Queue::fake();
        $agent = $this->actingAsMailRole('agent');
        $message = $this->inboundMessage($this->mailbox);
        $cases = $this->app->make(CaseService::class);
        $case = $cases->openFromMessage($message, [['item_type' => 'rechnung', 'title' => 'Rechnungsfrage', 'assignee_user_id' => $agent->getKey()]], $agent);

        $archived = $cases->archive($case, $agent);
        $this->assertNotNull($archived->archived_at);
        $this->assertSame(CaseStatus::Open, $archived->status_processing, 'Archivieren ändert den Bearbeitungsstatus nicht.');

        try {
            $cases->closeWithException($case, $agent, 'Kunde hat zurückgezogen.');
            $this->fail('Sachbearbeitung hat kein Recht zum Ausnahmeabschluss.');
        } catch (PermissionDeniedException) {
            $this->addToAssertionCount(1);
        }

        $lead = $this->actingAsMailRole('lead', $this->mailbox);
        $this->attachMailRole($lead, $this->mailbox, 'lead');

        try {
            $cases->closeWithException($case, $lead, '  ');
            $this->fail('Begründung ist Pflicht.');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $closed = $cases->closeWithException($case, $lead, 'Kunde hat die Anfrage zurückgezogen.');
        $this->assertSame(CaseStatus::Closed, $closed->status_processing);
        $this->assertTrue((bool) $closed->closed_by_exception);
        $this->assertSame((int) $lead->getKey(), (int) $closed->closed_by);
        $this->assertSame(CaseStatus::Closed, $closed->items()->firstOrFail()->status_processing);

        $exceptionLog = CaseStatusLog::query()->where('case_id', $case->getKey())->where('to_status', 'closed')->whereNull('case_item_id')->firstOrFail();
        $this->assertTrue((bool) ($exceptionLog->context_json['exception'] ?? false));
        $this->assertNotEmpty($exceptionLog->context_json['unmet_conditions']);
    }

    public function test_waiting_external_requires_party_follow_up_and_customer_update_and_pauses_only_allowed_priorities(): void
    {
        Queue::fake();
        $this->travelTo(CarbonImmutable::parse('2026-09-09 10:00', 'Europe/Berlin'));
        $user = $this->actingAsMailRole('agent');
        $cases = $this->app->make(CaseService::class);
        $message = $this->inboundMessage($this->mailbox, ['subject' => 'Rechnung Hausmeister', 'body_text' => 'Bitte prüfen Sie die beigefügte Rechnung.']);
        $case = $cases->openFromMessage($message, [['item_type' => 'rechnung', 'title' => 'Rechnung prüfen', 'assignee_user_id' => $user->getKey()]], $user);
        $item = $case->items()->firstOrFail();

        try {
            $cases->transitionProcessing($item, CaseStatus::WaitingExternal, $user, 'Handwerker gefragt', ['external_party' => 'Handwerker Müller']);
            $this->fail('Ohne Nachfassdatum und Kundenzwischenstand kein waiting_external.');
        } catch (InvalidTransitionException $e) {
            $this->assertStringContainsString('Nachfassdatum', $e->getMessage());
            $this->assertStringContainsString('Kundenzwischenstand', $e->getMessage());
        }

        $item = $cases->transitionProcessing($item->refresh(), CaseStatus::WaitingExternal, $user, 'Rückfrage beim Handwerker', [
            'external_party' => 'Handwerker Müller',
            'follow_up_at' => CarbonImmutable::parse('2026-09-11 09:00', 'Europe/Berlin'),
            'next_customer_update_at' => CarbonImmutable::parse('2026-09-12 09:00', 'Europe/Berlin'),
        ]);
        $this->assertSame('Handwerker Müller', $item->waiting_external_party);
        $this->assertStringContainsString('Nachfassen', (string) $item->next_step);
        $resolution = SlaClock::query()->where('case_item_id', $item->getKey())->where('clock_type', ClockType::Resolution->value)->firstOrFail();
        $this->assertSame('paused', $resolution->state, 'P2 darf pausieren.');
        $originalTarget = $resolution->target_at;

        $this->travelTo(CarbonImmutable::parse('2026-09-10 10:00', 'Europe/Berlin'));
        $cases->transitionProcessing($item->refresh(), CaseStatus::InProgress, $user, 'Antwort erhalten.');
        $resolution->refresh();
        $this->assertSame('running', $resolution->state);
        $this->assertTrue($resolution->target_at->greaterThan($originalTarget), 'Pause verlängert die Frist um die Wartezeit.');
        $this->assertSame(['started', 'paused', 'resumed'], SlaClockLog::query()->where('sla_clock_id', $resolution->getKey())->orderBy('id')->pluck('event')->all());

        // P1 pausiert nicht.
        $urgent = $this->inboundMessage($this->mailbox, ['subject' => 'Heizungsausfall', 'body_text' => 'Heizung ausgefallen.']);
        $urgentCase = $cases->openFromMessage($urgent, [['item_type' => 'schaden', 'title' => 'Heizung', 'assignee_user_id' => $user->getKey()]], $user);
        $urgentItem = $cases->transitionProcessing($urgentCase->items()->firstOrFail(), CaseStatus::WaitingExternal, $user, 'Notdienst', [
            'external_party' => 'Heizungsnotdienst', 'follow_up_at' => CarbonImmutable::now()->addHours(2), 'next_customer_update_at' => CarbonImmutable::now()->addHours(3),
        ]);
        $this->assertSame('running', SlaClock::query()->where('case_item_id', $urgentItem->getKey())->where('clock_type', ClockType::Resolution->value)->value('state'));
    }

    public function test_no_reply_needed_requires_reason(): void
    {
        Queue::fake();
        $user = $this->actingAsMailRole('agent');
        $cases = $this->app->make(CaseService::class);
        $case = $cases->openFromMessage($this->inboundMessage($this->mailbox), [['item_type' => 'sonstiges', 'title' => 'Spam-Verdacht', 'assignee_user_id' => $user->getKey()]], $user);

        try {
            $cases->transitionCommunication($case, CommunicationStatus::NoReplyNeeded, $user);
            $this->fail('Begründung fehlt.');
        } catch (InvalidTransitionException) {
            $this->addToAssertionCount(1);
        }

        $cases->transitionCommunication($case, CommunicationStatus::NoReplyNeeded, $user, 'Newsletter ohne Anliegen.');
        $this->assertSame('Newsletter ohne Anliegen.', $case->refresh()->communication_waived_reason);
        $item = $cases->transitionProcessing($case->items()->firstOrFail(), CaseStatus::Resolved, $user, 'Kein Anliegen.');
        $this->assertSame(CaseStatus::Resolved, $item->status_processing);
    }
}
