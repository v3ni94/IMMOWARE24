<?php

declare(strict_types=1);

namespace Tests\Feature\Actions;

use App\Modules\Actions\Adapters\ManualTargetAdapter;
use App\Modules\Actions\DTO\StepResult;
use App\Modules\Actions\Enums\ActionStatus;
use App\Modules\Actions\Enums\TargetSystem;
use App\Modules\Actions\Jobs\ExecuteActionJob;
use App\Modules\Actions\Models\ActionPlanVersion;
use App\Modules\Actions\Models\ActionTarget;
use App\Modules\Actions\Models\Execution;
use App\Modules\Actions\Services\AdapterRegistry;
use App\Modules\Actions\Services\ExecutionService;
use App\Modules\Cases\Models\Task;
use App\Modules\Sync\Models\DlqItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Actions\Concerns\BuildsActionPlans;
use Tests\TestCase;

/**
 * Abnahmefall 11: Kontakt mit mehreren Adressen verliert nichts (manueller Klärungsweg, kein PUT). Dazu
 * Flag-Sperre und DLQ-Ablage bei technischem Fehler des Adapters.
 */
final class LexwareMultiAddressTest extends TestCase
{
    use BuildsActionPlans, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpActionFixtures();
    }

    public function test_contact_with_multiple_billing_addresses_goes_to_manual_clarification(): void
    {
        $this->seedLexwareContact('lx-2', ['addresses' => ['billing' => [['street' => 'Altstraße 1', 'zip' => '40721', 'city' => 'Hilden', 'countryCode' => 'DE'], ['street' => 'Zweitstraße 5', 'zip' => '40721', 'city' => 'Hilden', 'countryCode' => 'DE']]]]);
        $version = $this->propose([$this->lexwareAddressStep('lx-2')]);
        $this->assertStringContainsString('Rechnungsadressen', json_encode($version->preconditions_json, JSON_THROW_ON_ERROR) ?: '', 'Warnung aus validate/readCurrent sichtbar.');

        $this->approveBy($version, $this->approverOne);

        $this->assertSame([], $this->lexware->requests('PUT'), 'Kein Schreibzugriff.');
        $this->assertCount(2, $this->lexware->contacts()['lx-2']['addresses']['billing'], 'Keine Adresse entfernt.');
        $execution = Execution::query()->firstOrFail();
        $this->assertSame(ExecutionService::STATUS_MANUAL_TASK, $execution->status);
        $this->assertSame('manual_clarification', $execution->result_masked_json['mode']);
        $task = Task::query()->findOrFail($execution->task_id);
        $this->assertSame('manual_change_lexware', $task->task_type);
        $this->assertSame('Neustraße 2', $task->new_value_json['street']);
        $this->assertSame(ActionStatus::Executed, $version->plan->fresh()->status);
    }

    public function test_disabled_write_flag_blocks_api_step_without_remote_call(): void
    {
        $this->seedLexwareContact();
        $version = $this->propose([$this->lexwareAddressStep()]);
        config()->set('hub.mail.flags.lexware_write', false);

        $this->approveBy($version, $this->approverOne);

        $this->assertSame([], $this->lexware->requests('PUT'));
        $this->assertSame(ActionStatus::ManualReview, $version->plan->fresh()->status);
        $this->assertStringContainsString('lexware_write', (string) ActionTarget::query()->value('last_error'));
    }

    public function test_technical_adapter_failure_is_recorded_and_goes_to_dlq_after_retries(): void
    {
        $version = $this->propose([['target_system' => 'manual', 'action_type' => 'manual_task', 'external_ref' => [], 'new_values' => ['title' => 'Rückruf']]]);
        $this->app->make(AdapterRegistry::class)->register(TargetSystem::Manual, ThrowingAdapter::class);
        config()->set('hub.actions.jobs.tries', 1);
        $thrown = null;

        try {
            $this->approveBy($version, $this->approverOne);
        } catch (\RuntimeException $e) {
            $thrown = $e;
        }

        $this->assertSame('Adapter kaputt', $thrown?->getMessage(), 'Technischer Fehler muss den Job scheitern lassen.');

        $this->assertSame(ExecutionService::STATUS_FAILED, Execution::query()->value('status'));
        $this->assertSame(ActionStatus::ManualReview, $version->plan->fresh()->status);
        $this->assertSame(1, DlqItem::query()->where('job_class', ExecuteActionJob::class)->count());
        $this->assertDatabaseHas('mail_outbox', ['event' => 'execution.failed']);
    }
}

final class ThrowingAdapter extends ManualTargetAdapter
{
    public function executeStep(ActionPlanVersion $version, int $stepIndex, Execution $execution): StepResult
    {
        throw new \RuntimeException('Adapter kaputt');
    }
}
