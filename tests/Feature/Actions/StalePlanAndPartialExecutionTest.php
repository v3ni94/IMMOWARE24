<?php

declare(strict_types=1);

namespace Tests\Feature\Actions;

use App\Modules\Actions\Enums\ActionStatus;
use App\Modules\Actions\Exceptions\ActionPolicyException;
use App\Modules\Actions\Models\ActionTarget;
use App\Modules\Actions\Models\Execution;
use App\Modules\Actions\Services\ActionPlanService;
use App\Modules\Actions\Services\ExecutionService;
use App\Modules\Actions\Services\ManualTaskService;
use App\Modules\Cases\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Sleep;
use Tests\Feature\Actions\Concerns\BuildsActionPlans;
use Tests\TestCase;

/**
 * Abnahmefälle 8 (veralteter Plan, geänderter Datensatz) und 9 (Zwei-System-Adressänderung mit Teilerfolg).
 */
final class StalePlanAndPartialExecutionTest extends TestCase
{
    use BuildsActionPlans, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpActionFixtures();
    }

    public function test_changed_target_record_after_approval_leads_to_manual_review_without_write(): void
    {
        $this->seedLexwareContact();
        $version = $this->propose([$this->lexwareAddressStep()]);
        $this->assertSame('Altstraße 1', $version->old_values[0]['street']);

        // Datensatz ändert sich in Lexware zwischen Planerstellung und Ausführung (Version 3 -> 4).
        $this->seedLexwareContact('lx-1', ['addresses' => ['billing' => [['street' => 'Fremdstraße 9']]]], 4);

        $this->approveBy($version, $this->approverOne);

        $this->assertSame(ActionStatus::ManualReview, $version->plan->fresh()->status);
        $this->assertSame([], $this->lexware->requests('PUT'), 'Kein Schreibzugriff bei geändertem Ausgangszustand.');
        $this->assertStringContainsString('geändert', (string) ActionTarget::query()->where('action_plan_version_id', $version->getKey())->value('last_error'));
    }

    public function test_superseded_version_cannot_be_approved_or_executed(): void
    {
        $this->seedLexwareContact();
        $first = $this->propose([$this->lexwareAddressStep()]);
        $second = $this->app->make(ActionPlanService::class)->revise($first->plan, [$this->lexwareAddressStep('lx-1', 'Neustraße 3')], $this->author, 'Hausnummer korrigiert');

        $this->assertNotNull($first->fresh()->superseded_at);
        $this->assertNotSame($first->diff_hash, $second->diff_hash);

        try {
            $this->approveBy($first, $this->approverOne);
            $this->fail('Überholte Version darf nicht freigegeben werden.');
        } catch (ActionPolicyException $e) {
            $this->assertSame('stale_version', $e->code_key);
        }

        $this->assertNull($this->app->make(ExecutionService::class)->executeStep($first->fresh(['plan']), 0, null, 'test'));
        $this->assertSame([], $this->lexware->requests('PUT'));
    }

    public function test_two_system_address_change_with_partial_failure_stays_open_and_retries_only_missing_step(): void
    {
        $this->seedLexwareContact();
        $contact = $this->seedMirrorContact();
        $version = $this->propose([$this->lexwareAddressStep(), $this->immowareAddressStep($contact)]);
        $this->assertSame(2, $version->targets()->count());

        // Lexware lehnt den PUT dauerhaft mit 429 ab (nicht angewandt, technisch wiederholbar), Immoware24 liefert
        // eine manuelle Aufgabe. Ein 5xx nach PUT wäre dagegen "Ergebnis unklar" mit Nachlesen (TimeoutAndScheduleTest).
        Sleep::fake();
        $this->lexware->failNextMethod('PUT', 'contacts/lx-1', 429)->failNextMethod('PUT', 'contacts/lx-1', 429)->failNextMethod('PUT', 'contacts/lx-1', 429);
        $this->approveBy($version, $this->approverOne);

        $targets = $version->targets()->orderBy('step_index')->get()->pluck('status', 'step_index')->all();
        $this->assertSame(ActionTarget::FAILED, $targets[0]);
        $this->assertSame(ActionTarget::MANUAL_TASK, $targets[1]);
        $this->assertSame(ActionStatus::ManualReview, $version->plan->fresh()->status, 'Teilfehler bleibt offen, kein Gesamterfolg.');
        $this->assertCount(3, $this->lexware->requests('PUT'), 'Ein PUT plus zwei 429-Wiederholungen des Clients.');
        $this->assertSame('Altstraße 1', $this->lexware->contacts()['lx-1']['addresses']['billing'][0]['street']);
        $this->assertDatabaseCount('mail_tasks', 1);

        // Nur der fehlende Schritt wird wiederholt; die Immoware-Aufgabe entsteht nicht doppelt.
        $dispatched = $this->app->make(ExecutionService::class)->retryOpenSteps($version->fresh(['plan']), $this->approverOne);
        $this->assertSame([0], $dispatched);
        $this->assertCount(4, $this->lexware->requests('PUT'));
        $this->assertSame('Neustraße 2', $this->lexware->contacts()['lx-1']['addresses']['billing'][0]['street']);
        $this->assertDatabaseCount('mail_tasks', 1);
        $this->assertSame(1, Execution::query()->where('action_plan_version_id', $version->getKey())->where('step_index', 0)->count(), 'Retry nutzt denselben Beleg (idempotency_key).');

        $targets = $version->targets()->orderBy('step_index')->get()->pluck('status', 'step_index')->all();
        $this->assertSame(ActionTarget::VERIFIED, $targets[0], 'Lexware nach PUT per GET verifiziert.');
        $this->assertSame(ActionTarget::MANUAL_TASK, $targets[1]);
        $this->assertSame(ActionStatus::Executed, $version->plan->fresh()->status, 'Manuelle Aufgabe offen, Plan nicht verifiziert.');

        $this->app->make(ManualTaskService::class)->confirm(Task::query()->firstOrFail(), $this->approverOne);
        $this->assertSame(ActionStatus::Verified, $version->plan->fresh()->status);
    }
}
