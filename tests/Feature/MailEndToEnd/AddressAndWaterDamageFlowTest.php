<?php

declare(strict_types=1);

namespace Tests\Feature\MailEndToEnd;

use App\Modules\Actions\Enums\ActionStatus;
use App\Modules\Actions\Models\ActionTarget;
use App\Modules\Actions\Models\Execution;
use App\Modules\Actions\Services\ActionPlanService;
use App\Modules\Actions\Services\ExecutionService;
use App\Modules\Cases\Enums\CaseStatus;
use App\Modules\Cases\Enums\CommunicationStatus;
use App\Modules\Cases\Enums\Priority;
use App\Modules\Cases\Models\CaseStatusLog;
use App\Modules\Cases\Models\MailCase;
use App\Modules\Cases\Models\Task;
use App\Modules\Gmail\Models\MailDraft;
use App\Modules\MailIntegration\Services\ReplyProposalService;
use App\Modules\Security\Services\LoginService;
use App\Modules\Sla\Jobs\EmergencyAlertJob;
use App\Modules\Sla\Models\EmergencyAlert;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

/**
 * End-to-End (a): Pub/Sub-Push, History-Abgleich (FakeGmail), Vorgang mit zwei Teilanliegen (Adressänderung und
 * Wasseraustritt), P0-Alarm auf mail-high, Freigabe eines Zwei-System-Plans: Immoware24 nicht schreibfähig ergibt eine
 * manuelle Aufgabe, Lexware (Fake) wird ausgeführt und per GET verifiziert. Der Teilerfolg bleibt sichtbar; der
 * Antwortentwurf nennt ausschließlich die verifizierte Änderung. Kein Fremdsystem wird kontaktiert.
 */
final class AddressAndWaterDamageFlowTest extends MailEndToEndTestCase
{
    public function test_push_to_partial_success_and_reply_proposal(): void
    {
        Queue::fake([EmergencyAlertJob::class]);

        // 1. Push und History-Abgleich: eine Nachricht mit zwei Anliegen.
        $message = $this->receive([
            'from' => 'Erika Muster <erika.muster@example.com>',
            'subject' => 'Neue Anschrift und Wasser tritt aus',
            'text' => "Guten Tag,\nmeine neue Anschrift lautet Neustraße 2, 40721 Hilden.\nAußerdem tritt in der Küche Wasser aus der Wand, bitte dringend kümmern.\nErika Muster",
        ]);

        $this->assertSame('imported', $message->getAttribute('processing_status'), 'Gelesen oder zugeordnet ist nicht bearbeitet.');
        $this->assertSame(1, MailCase::query()->count());

        $case = $this->caseOf($message);
        $items = $case->items()->orderBy('position')->get();
        $this->assertEqualsCanonicalizing(['adressaenderung', 'schaden_notfall'], $items->pluck('item_type')->all(), 'Zwei Teilanliegen aus einer Nachricht.');
        $this->assertSame(Priority::P0, $case->priority);
        $this->assertNotNull($case->opened_at);
        $this->assertSame(CaseStatus::New, $case->status_processing, 'Ohne Kennung keine automatische Zuweisung, Verantwortlicher bleibt zu setzen.');
        $this->assertSame(CommunicationStatus::ReplyNeeded, $case->status_communication);

        // 2. P0-Regel löst den Notfallalarm auf mail-high aus (Modul Cases ruft Sla EmergencyQueue).
        $alert = EmergencyAlert::query()->where('case_id', $case->getKey())->firstOrFail();
        $this->assertSame('open', $alert->getAttribute('status'));
        Queue::assertPushedOn('mail-high', EmergencyAlertJob::class, static fn (EmergencyAlertJob $job): bool => $job->alertId === (int) $alert->getKey());

        // 3. Zwei-System-Plan: Adresse in Immoware24 (nicht schreibfähig) und Lexware (Fake, schreibbar).
        $this->seedLexwareContact();
        $contact = $this->seedMirrorContact();
        $version = $this->app->make(ActionPlanService::class)->propose($case, [
            $this->immowareAddressStep($contact),
            $this->lexwareAddressStep(),
        ], $this->author, (int) $message->getKey());

        $this->assertSame(ActionStatus::Validated, $version->plan->fresh()->status);
        $this->approvals()->requestApproval($version, $this->author);
        $this->assertSame(ActionStatus::ApprovalRequired, $version->plan->fresh()->status, 'Risikoklasse high: eine Freigabe einer anderen Person nötig.');
        $this->approveBy($version, $this->approverOne);

        // 4. Ausführung (Queue sync im Test): Immoware24 ergibt manuelle Aufgabe, Lexware ausgeführt und verifiziert.
        $targets = ActionTarget::query()->where('action_plan_version_id', $version->getKey())->orderBy('step_index')->pluck('status')->all();
        $this->assertSame([ActionTarget::MANUAL_TASK, ActionTarget::VERIFIED], $targets);
        $this->assertSame(ActionStatus::Executed, $version->plan->fresh()->status, 'Teilerfolg: ausgeführt, nicht verifiziert.');
        $this->assertSame('Neustraße 2', $this->lexware->contacts()['lx-1']['addresses']['billing'][0]['street']);
        $this->assertCount(1, $this->lexware->requests('PUT'));

        $lexwareExecution = Execution::query()->where('action_plan_version_id', $version->getKey())->where('step_index', 1)->firstOrFail();
        $this->assertSame(ExecutionService::STATUS_VERIFIED, $lexwareExecution->getAttribute('status'));
        $task = Task::query()->where('case_id', $case->getKey())->where('task_type', 'manual_change_immoware')->firstOrFail();
        $this->assertSame('open', $task->getAttribute('status'));

        // 5. Actions zu Cases: Kommunikationsvorschlag protokolliert, Entwurf nennt nur die verifizierte Änderung.
        $this->assertTrue(CaseStatusLog::query()->where('case_id', $case->getKey())->where('dimension', 'communication_proposal')->where('reason', 'like', '%Teilerfolg%')->exists());
        $draft = MailDraft::query()->where('case_id', $case->getKey())->where('generated_by', ReplyProposalService::GENERATED_BY)->firstOrFail();
        $this->assertSame('local', $draft->getAttribute('status'), 'Vorschlag bleibt lokal, nie automatisch an Gmail.');
        $this->assertSame(['erika.muster@example.com'], $draft->getAttribute('to_json'));
        $this->assertStringContainsString('Lexware Office', (string) $draft->getAttribute('body_text'));
        $this->assertStringContainsString('Neustraße 2', (string) $draft->getAttribute('body_text'));
        $this->assertStringNotContainsString('Immoware24', (string) $draft->getAttribute('body_text'), 'Unverifizierte Änderung erscheint nicht als erledigt.');
        $this->assertStringContainsString('noch in Bearbeitung', (string) $draft->getAttribute('body_text'));
        $this->assertSame([], $this->gmail->drafts((int) $this->box->getKey()), 'Kein Gmail-Entwurf ohne Nutzeraktion.');

        $case->refresh();
        $this->assertSame(CaseStatus::New, $case->status_processing, 'Verifizierte Teiländerung erledigt den Vorgang nicht.');
        $this->assertSame(CommunicationStatus::ReplyNeeded, $case->status_communication);

        // 6. Teilerfolg in der Oberfläche sichtbar (Vorgangsdetail als Teamleitung).
        $lead = $this->actingAsMailRole('lead', $this->box);
        // Das Postfach wurde in diesem Test angelegt (wasRecentlyCreated), deshalb Team-Rolle und Postfachrecht explizit.
        $this->attachMailRole($lead, $this->box, 'lead');
        $this->actingAs($lead)->withSession([LoginService::SESSION_TWO_FACTOR_VERIFIED => now()->toIso8601String()]);
        $page = $this->get(self::BASE.'/cases/'.$case->getKey())->assertOk();
        $page->assertSee('manual_task')->assertSee('verified')->assertSee('Ausgeführt')->assertSee('Manuell erledigt bestätigen');
        $page->assertSee('Adressänderung')->assertSee('Notfall');

        // 7. Manuelle Bestätigung der Immoware-Aufgabe über die Oberfläche: erst jetzt ist der Plan verifiziert.
        $this->post(self::BASE.'/cases/'.$case->getKey().'/tasks/'.$task->getKey().'/confirm')->assertRedirect(self::BASE.'/cases/'.$case->getKey())->assertSessionHas('status');
        $this->assertSame('done_manual_confirmed', $task->fresh()->getAttribute('status'));
        $this->assertSame(ActionStatus::Verified, $version->plan->fresh()->status);
        $draft->refresh();
        $this->assertStringContainsString('Immoware24', (string) $draft->getAttribute('body_text'), 'Nach Bestätigung nennt der Vorschlag beide Änderungen.');
        $this->assertSame(1, MailDraft::query()->where('case_id', $case->getKey())->count(), 'Ein Vorschlag wird fortgeschrieben, nicht vervielfacht.');
        $this->assertGreaterThan(CarbonImmutable::now()->subMinute(), CarbonImmutable::instance($draft->getAttribute('updated_at')));
    }
}
