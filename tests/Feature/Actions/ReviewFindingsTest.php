<?php

declare(strict_types=1);

namespace Tests\Feature\Actions;

use App\Core\Contracts\WebhookDispatcherInterface;
use App\Core\Support\CorrelationId;
use App\Modules\Actions\Enums\ActionStatus;
use App\Modules\Actions\Enums\RiskClass;
use App\Modules\Actions\Enums\VerificationStatus;
use App\Modules\Actions\Jobs\DispatchActionOutboxJob;
use App\Modules\Actions\Jobs\ExecuteActionJob;
use App\Modules\Actions\Jobs\RunScheduledActionsJob;
use App\Modules\Actions\Jobs\VerifyActionJob;
use App\Modules\Actions\Models\ActionOutbox;
use App\Modules\Actions\Models\ActionTarget;
use App\Modules\Actions\Models\Execution;
use App\Modules\Actions\Models\Verification;
use App\Modules\Actions\Services\ActionOutboxDispatcher;
use App\Modules\Actions\Services\ActionOutboxWriter;
use App\Modules\Actions\Services\ActionPolicy;
use App\Modules\Actions\Services\ExecutionService;
use App\Modules\Actions\Services\ManualTaskService;
use App\Modules\Actions\Services\PreconditionChecker;
use App\Modules\Cases\Models\Task;
use App\Modules\Lexware\Models\LexwareConnection;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Sleep;
use Tests\Feature\Actions\Concerns\BuildsActionPlans;
use Tests\TestCase;

/**
 * Review-Findings der Action-Engine: 5xx nach PUT ist unklar statt Konflikt, hängendes http_ok_unverified wird
 * nachgefasst, Ablehnung bindet, write_enabled je Verbindung gilt, keine Freigabe mit 0, Outbox hat einen Verarbeiter,
 * Scheduler fasst freigegebene Pläne ohne Beleg nach, manuelle Bestätigungen werden nach Sync aufgewertet.
 */
final class ReviewFindingsTest extends TestCase
{
    use BuildsActionPlans, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpActionFixtures();
    }

    public function test_gateway_error_after_put_is_result_unclear_and_reread_decides_instead_of_conflict(): void
    {
        $this->seedLexwareContact();
        $version = $this->propose([$this->lexwareAddressStep()]);
        Bus::fake([VerifyActionJob::class]);

        // 502 des Gateways: der PUT kann angewandt worden sein. Nicht failed und blind wiederholen, sondern nachlesen.
        $this->lexware->failNextMethod('PUT', 'contacts/lx-1', 502);
        $this->approveBy($version, $this->approverOne);

        $execution = Execution::query()->where('action_plan_version_id', $version->getKey())->firstOrFail();
        $this->assertSame(ExecutionService::STATUS_RESULT_UNCLEAR, $execution->status);
        $this->assertStringContainsString('HTTP 502', (string) $execution->error_message);
        $this->assertSame(ActionStatus::ResultUnclear, $version->plan->fresh()->status);
        $this->assertSame(ActionTarget::RESULT_UNCLEAR, ActionTarget::query()->where('action_plan_version_id', $version->getKey())->value('status'));
        Bus::assertDispatched(VerifyActionJob::class);

        // Nachlesen zeigt unverändert: genau eine kontrollierte Wiederholung, danach verifiziert. Kein Konflikttext.
        $this->app->make(ExecutionService::class)->verify($execution->fresh());
        $this->assertCount(2, $this->lexware->requests('PUT'));
        $this->assertSame(ExecutionService::STATUS_VERIFIED, $execution->fresh()->status);
        $this->assertSame(ActionStatus::Verified, $version->plan->fresh()->status);
        $this->assertStringNotContainsString('seit Planerstellung geändert', (string) ActionTarget::query()->where('action_plan_version_id', $version->getKey())->value('last_error'));
    }

    public function test_unverifiable_execution_schedules_reverification_and_ends_in_result_unclear_after_max_attempts(): void
    {
        Sleep::fake();
        $this->seedLexwareContact('lx-1');
        $this->seedLexwareContact('lx-2');

        // Referenzlauf: Anzahl der Lexware-Aufrufe einer Freigabe mit Ausführung (Vorprüfung, Lesen, PUT, Nachlesen).
        $reference = $this->propose([$this->lexwareAddressStep('lx-1')]);
        $before = count($this->lexware->requests());
        $this->approveBy($reference, $this->approverOne);
        $perApproval = count($this->lexware->requests()) - $before;
        $this->assertSame(ActionStatus::Verified, $reference->plan->fresh()->status);

        // Zweiter Plan: alle Aufrufe bis auf das Nachlesen gelingen, das Nachlesen scheitert an 429 (Lexware nicht lesbar).
        $version = $this->propose([$this->lexwareAddressStep('lx-2')]);
        Bus::fake([VerifyActionJob::class]);
        $this->lexware->limitRequests($perApproval - 1);
        $this->approveBy($version, $this->approverOne);

        $execution = Execution::query()->where('action_plan_version_id', $version->getKey())->firstOrFail();
        $this->assertSame('Neustraße 2', $this->lexware->contacts()['lx-2']['addresses']['billing'][0]['street'], 'PUT wurde angewandt.');
        $this->assertSame(ExecutionService::STATUS_HTTP_OK_UNVERIFIED, $execution->status);
        $this->assertSame(1, Verification::query()->where('execution_id', $execution->getKey())->count());
        Bus::assertDispatched(VerifyActionJob::class, static fn (VerifyActionJob $job): bool => $job->executionId === (int) $execution->getKey());

        // Zielsystem bleibt unlesbar: nach der Höchstzahl an Versuchen ehrlich result_unclear mit manueller Prüfung.
        config()->set('hub.actions.jobs.verify_max_attempts', 3);
        $service = $this->app->make(ExecutionService::class);
        $service->verify($execution->fresh());
        $this->assertSame(ExecutionService::STATUS_HTTP_OK_UNVERIFIED, $execution->fresh()->status);
        $service->verify($execution->fresh());

        $execution->refresh();
        $this->assertSame(3, Verification::query()->where('execution_id', $execution->getKey())->count());
        $this->assertSame(ExecutionService::STATUS_RESULT_UNCLEAR, $execution->status);
        $this->assertStringContainsString('Manuelle Prüfung', (string) $execution->error_message);
        $this->assertSame(ActionStatus::ResultUnclear, $version->plan->fresh()->status);
        $this->assertTrue(ActionOutbox::query()->where('event', 'execution.result_unclear')->where('aggregate_id', $execution->getKey())->exists());
        Bus::assertDispatchedTimes(VerifyActionJob::class, 2, 'Nach Erschöpfung wird kein weiteres Nachlesen eingeplant.');
        $this->assertCount(2, $this->lexware->requests('PUT'), 'Je Plan genau ein PUT, nie ein zweiter ohne Nachleseergebnis.');
    }

    public function test_rejection_binds_even_when_enough_approvals_exist(): void
    {
        $this->seedLexwareContact();
        $version = $this->propose([$this->lexwareAddressStep()]);
        Bus::fake([ExecuteActionJob::class]);

        $this->approveBy($version, $this->approverOne);
        $this->assertTrue($this->app->make(ActionPolicy::class)->isFullyApproved($version->fresh(['plan'])));
        Bus::assertDispatched(ExecuteActionJob::class);

        $this->approvals()->reject($version->fresh(['plan']), $this->approverTwo, 'Adresse ist falsch.');
        $fresh = $version->fresh(['plan']);
        $this->assertSame(ActionStatus::ManualReview, $fresh->plan->status);
        $this->assertFalse($this->app->make(ActionPolicy::class)->isFullyApproved($fresh), 'Ablehnung bindet trotz vorhandener Freigabe.');
        $this->assertStringContainsString('abgelehnt', implode(' ', $this->app->make(PreconditionChecker::class)->check($fresh)));

        // Wiederholung (DLQ, retry) am Plan vorbei: keine Ausführung, kein PUT.
        $this->assertNull($this->app->make(ExecutionService::class)->executeStep($fresh, 0, null, 'test'));
        $this->assertSame([], $this->lexware->requests('PUT'));
        $this->assertSame(ActionStatus::ManualReview, $version->plan->fresh()->status);
        $this->assertStringContainsString('abgelehnt', (string) ActionTarget::query()->where('action_plan_version_id', $version->getKey())->value('last_error'));
    }

    public function test_connection_without_write_enabled_blocks_put_despite_global_flag(): void
    {
        $this->seedLexwareContact();
        LexwareConnection::query()->where('legal_entity_code', 'HVM')->update(['write_enabled' => false]);
        config()->set('hub.mail.flags.lexware_write', true);
        $version = $this->propose([$this->lexwareAddressStep()]);

        $this->approveBy($version, $this->approverOne);

        $this->assertSame([], $this->lexware->requests('PUT'), 'Kein Schreibzugriff über eine gesperrte Verbindung.');
        $this->assertSame(ActionStatus::ManualReview, $version->plan->fresh()->status);
        $target = ActionTarget::query()->where('action_plan_version_id', $version->getKey())->firstOrFail();
        $this->assertSame(ActionTarget::BLOCKED, $target->status);
        $this->assertStringContainsString('write_enabled', (string) $target->last_error);
        $this->assertSame('Altstraße 1', $this->lexware->contacts()['lx-1']['addresses']['billing'][0]['street']);
    }

    public function test_zero_required_approvals_never_execute_without_a_second_person(): void
    {
        config()->set('hub.actions.approvals.required.low', 0);
        $policy = $this->app->make(ActionPolicy::class);
        $this->assertSame(1, $policy->requiredApprovals(RiskClass::Low));

        $version = $this->propose([['target_system' => 'manual', 'action_type' => 'manual_task', 'external_ref' => [], 'new_values' => ['title' => 'Rückruf']]]);
        $version->forceFill(['required_approvals' => 0])->save();
        $fresh = $version->fresh(['plan']);

        $this->assertFalse($policy->isFullyApproved($fresh));
        $this->approvals()->requestApproval($fresh, $this->author);
        $this->assertSame(ActionStatus::ApprovalRequired, $version->plan->fresh()->status, 'Kein Selbstvollzug bei 0.');
        $this->assertDatabaseCount('mail_executions', 0);
        $this->assertNotEmpty($this->app->make(PreconditionChecker::class)->check($fresh));
    }

    public function test_outbox_entries_are_dispatched_and_marked_with_attempts(): void
    {
        $recorder = new class implements WebhookDispatcherInterface
        {
            /** @var array<int, array{event: string, payload: array<string, mixed>, organization_id: int}> */
            public array $events = [];

            public bool $fail = false;

            public function dispatch(string $event, array $payload, int $organizationId): void
            {
                if ($this->fail) {
                    throw new \RuntimeException('Bus nicht erreichbar.');
                }

                $this->events[] = ['event' => $event, 'payload' => $payload, 'organization_id' => $organizationId];
            }
        };
        $this->app->instance(WebhookDispatcherInterface::class, $recorder);
        $this->app->forgetInstance(ActionOutboxDispatcher::class);

        $writer = $this->app->make(ActionOutboxWriter::class);
        $first = $writer->write($this->box->organization_id, 'plan.approved', 'action_plan', 1, ['version_id' => 1]);
        $second = $writer->write($this->box->organization_id, 'execution.manual_task', 'execution', 2, ['task_id' => 9]);

        $stats = (new DispatchActionOutboxJob)->handle($this->app->make(ActionOutboxDispatcher::class), $this->app->make(CorrelationId::class));
        $this->assertSame(['dispatched' => 2, 'failed' => 0, 'retried' => 0], $stats);
        $this->assertSame(['mail.plan.approved', 'mail.execution.manual_task'], array_column($recorder->events, 'event'));
        $this->assertSame(['version_id' => 1], $recorder->events[0]['payload']['payload']);
        $this->assertSame('dispatched', $first->fresh()->status);
        $this->assertSame(1, $first->fresh()->attempts);
        $this->assertNotNull($second->fresh()->dispatched_at);

        // Fehler: Versuch zählt, bleibt pending; nach max_attempts failed. Verarbeitete Einträge werden nicht erneut übergeben.
        config()->set('hub.actions.outbox.max_attempts', 2);
        $recorder->fail = true;
        $third = $writer->write($this->box->organization_id, 'execution.result_unclear', 'execution', 3, []);
        $stats = (new DispatchActionOutboxJob)->handle($this->app->make(ActionOutboxDispatcher::class), $this->app->make(CorrelationId::class));
        $this->assertSame(['dispatched' => 0, 'failed' => 0, 'retried' => 1], $stats);
        $this->assertSame('pending', $third->fresh()->status);
        $stats = (new DispatchActionOutboxJob)->handle($this->app->make(ActionOutboxDispatcher::class), $this->app->make(CorrelationId::class));
        $this->assertSame(['dispatched' => 0, 'failed' => 1, 'retried' => 0], $stats);
        $this->assertSame('failed', $third->fresh()->status);
        $this->assertSame(2, $third->fresh()->attempts);
        $this->assertCount(2, $recorder->events);
    }

    public function test_scheduler_recovers_approved_plan_without_execution_and_sets_status_only_with_dispatch(): void
    {
        $this->seedLexwareContact();
        $tomorrow = CarbonImmutable::now()->addDay();
        $version = $this->propose([$this->lexwareAddressStep()], $tomorrow);
        $this->approveBy($version, $this->approverOne);
        $this->assertSame(ActionStatus::Scheduled, $version->plan->fresh()->status);

        // Abbruch eines früheren Laufs zwischen Statuswechsel und Einplanung: approved ohne Beleg.
        $this->travelTo($tomorrow->addHours(7));
        $version->plan->fresh()->forceFill(['status' => ActionStatus::Approved, 'updated_at' => CarbonImmutable::now()->subMinutes(30)])->save();
        $this->assertDatabaseCount('mail_executions', 0);

        $job = new RunScheduledActionsJob;
        $this->assertGreaterThanOrEqual(120, $job->timeout);
        $stats = $job->handle(...array_map(fn (string $c) => $this->app->make($c), [PreconditionChecker::class, Dispatcher::class, ActionOutboxWriter::class, CorrelationId::class]));

        $this->assertSame(1, $stats['recovered']);
        $this->assertSame(0, $stats['dispatched']);
        $this->assertCount(1, $this->lexware->requests('PUT'));
        $this->assertSame(ActionStatus::Verified, $version->plan->fresh()->status);

        // Ein zweiter Lauf fasst nichts erneut nach (Beleg vorhanden).
        $stats = (new RunScheduledActionsJob)->handle(...array_map(fn (string $c) => $this->app->make($c), [PreconditionChecker::class, Dispatcher::class, ActionOutboxWriter::class, CorrelationId::class]));
        $this->assertSame(0, $stats['recovered']);
        $this->assertCount(1, $this->lexware->requests('PUT'));
    }

    public function test_recheck_command_upgrades_manually_confirmed_task_after_mirror_sync(): void
    {
        $account = $this->seedBankAccount();
        $version = $this->propose([$this->bankChangeStep($account)]);
        $this->approvals()->recordIdentityCheck($version, $this->approverOne, 'phone_callback');
        $this->approveBy($version, $this->approverOne);
        $this->approveBy($version, $this->approverTwo);

        $execution = Execution::query()->where('action_plan_version_id', $version->getKey())->firstOrFail();
        $task = Task::query()->findOrFail($execution->task_id);
        $this->app->make(ManualTaskService::class)->confirm($task, $this->approverTwo);
        $verificationsBefore = Verification::query()->where('execution_id', $execution->getKey())->count();

        // Vor dem Sync: kein Match, kein neuer Beleg, Aufgabe bleibt manuell bestätigt.
        $this->artisan('mail:actions:recheck-manual')->assertExitCode(0)->expectsOutputToContain('gehoben: 0');
        $this->assertSame(ManualTaskService::TASK_DONE_MANUAL, $task->fresh()->status);
        $this->assertSame($verificationsBefore, Verification::query()->where('execution_id', $execution->getKey())->count());

        // Nach dem Sync trägt der Spiegel die neue IBAN: api_verified, done_verified.
        $account->forceFill(['iban' => 'DE02120300000000202051', 'iban_masked' => 'DE02***2051'])->save();
        $this->artisan('mail:actions:recheck-manual')->assertExitCode(0)->expectsOutputToContain('gehoben: 1');
        $this->assertSame(ManualTaskService::TASK_DONE_VERIFIED, $task->fresh()->status);
        $this->assertSame(VerificationStatus::ApiVerified, $execution->fresh()->verification_status);
    }
}
