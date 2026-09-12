<?php

declare(strict_types=1);

namespace Tests\Feature\Actions;

use App\Core\Support\CorrelationId;
use App\Modules\Actions\Enums\ActionStatus;
use App\Modules\Actions\Enums\VerificationStatus;
use App\Modules\Actions\Jobs\ExecuteActionJob;
use App\Modules\Actions\Jobs\RunScheduledActionsJob;
use App\Modules\Actions\Jobs\VerifyActionJob;
use App\Modules\Actions\Models\Execution;
use App\Modules\Actions\Services\ActionOutboxWriter;
use App\Modules\Actions\Services\ExecutionService;
use App\Modules\Actions\Services\PreconditionChecker;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\Feature\Actions\Concerns\BuildsActionPlans;
use Tests\TestCase;

/**
 * Abnahmefälle 17 (Timeout-Retry ohne Doppeländerung) und 19 (zukünftiges Datum nicht vorzeitig).
 */
final class TimeoutAndScheduleTest extends TestCase
{
    use BuildsActionPlans, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpActionFixtures();
    }

    public function test_timeout_leads_to_result_unclear_and_retry_verifies_instead_of_writing_again(): void
    {
        $this->seedLexwareContact();
        $version = $this->propose([$this->lexwareAddressStep()]);

        // PUT wird serverseitig ausgeführt, die Antwort geht verloren. VerifyActionJob wird nur aufgezeichnet.
        Bus::fake([VerifyActionJob::class]);
        $this->lexware->timeoutNext('PUT', 'contacts/lx-1', applyChange: true);
        $this->approveBy($version, $this->approverOne);

        $execution = Execution::query()->where('action_plan_version_id', $version->getKey())->firstOrFail();
        $this->assertSame(ExecutionService::STATUS_RESULT_UNCLEAR, $execution->status);
        $this->assertSame(ActionStatus::ResultUnclear, $version->plan->fresh()->status);
        $this->assertCount(1, $this->lexware->requests('PUT'));
        Bus::assertDispatched(VerifyActionJob::class);

        // Worker-Neustart: derselbe Job (gleicher action_key) läuft erneut. Kein zweiter PUT, nur Nachlesen.
        $job = ExecuteActionJob::forStep((int) $version->getKey(), 0, $this->author->getKey());
        $this->assertSame(ExecutionService::idempotencyKey((int) $version->getKey(), 0), $job->uniqueId());
        $job->handle($this->app->make(ExecutionService::class), $this->app->make('cache'), $this->app->make(CorrelationId::class));

        $this->assertCount(1, $this->lexware->requests('PUT'), 'Keine Doppeländerung nach Timeout.');
        $this->assertSame(ExecutionService::STATUS_VERIFIED, $execution->fresh()->status);
        $this->assertSame(VerificationStatus::ApiVerified, $execution->fresh()->verification_status);
        $this->assertSame(ActionStatus::Verified, $version->plan->fresh()->status);
        $this->assertSame(1, Execution::query()->where('action_plan_version_id', $version->getKey())->count());
    }

    public function test_timeout_without_server_side_change_allows_exactly_one_controlled_retry(): void
    {
        $this->seedLexwareContact();
        $version = $this->propose([$this->lexwareAddressStep()]);

        $this->lexware->timeoutNext('PUT', 'contacts/lx-1', applyChange: false);
        $this->approveBy($version, $this->approverOne);

        // Sync-Queue: VerifyActionJob lief direkt, hat "unverändert" festgestellt und genau einen Retry eingeplant.
        $execution = Execution::query()->where('action_plan_version_id', $version->getKey())->firstOrFail();
        $this->assertSame(2, $execution->attempts);
        $this->assertCount(2, $this->lexware->requests('PUT'));
        $this->assertSame('Neustraße 2', $this->lexware->contacts()['lx-1']['addresses']['billing'][0]['street']);
        $this->assertSame(ActionStatus::Verified, $version->plan->fresh()->status);
    }

    public function test_future_effective_date_is_scheduled_and_never_executed_early(): void
    {
        $this->seedLexwareContact();
        $tomorrow = CarbonImmutable::now()->addDay();
        $version = $this->propose([$this->lexwareAddressStep()], $tomorrow);

        $this->approveBy($version, $this->approverOne);
        $this->assertSame(ActionStatus::Scheduled, $version->plan->fresh()->status);
        $this->assertDatabaseCount('mail_executions', 0);
        $this->assertSame([], $this->lexware->requests('PUT'));

        // Direkter Versuch am Scheduler vorbei: Vorbedingung "Datum in der Zukunft" sperrt.
        $this->assertNull($this->app->make(ExecutionService::class)->executeStep($version->fresh(['plan']), 0, null, 'test'));
        $this->assertSame([], $this->lexware->requests('PUT'));
        $version->plan->fresh()->forceFill(['status' => ActionStatus::Scheduled])->save();
        $version->targets()->update(['status' => 'pending', 'last_error' => null]);

        $stats = (new RunScheduledActionsJob)->handle(...array_map(fn (string $c) => $this->app->make($c), [PreconditionChecker::class, Dispatcher::class, ActionOutboxWriter::class, CorrelationId::class]));
        $this->assertSame(1, $stats['waiting']);
        $this->assertSame([], $this->lexware->requests('PUT'));

        $this->travelTo($tomorrow->addHours(7));
        $stats = (new RunScheduledActionsJob)->handle(...array_map(fn (string $c) => $this->app->make($c), [PreconditionChecker::class, Dispatcher::class, ActionOutboxWriter::class, CorrelationId::class]));
        $this->assertSame(1, $stats['dispatched']);
        $this->assertCount(1, $this->lexware->requests('PUT'));
        $this->assertSame(ActionStatus::Verified, $version->plan->fresh()->status);
    }

    public function test_expired_approval_blocks_execution(): void
    {
        $this->seedLexwareContact();
        $version = $this->propose([$this->lexwareAddressStep()]);
        Bus::fake([ExecuteActionJob::class]);
        $this->approveBy($version, $this->approverOne);
        Bus::assertDispatched(ExecuteActionJob::class);

        $this->travel(4)->days();
        $this->assertNull($this->app->make(ExecutionService::class)->executeStep($version->fresh(['plan']), 0, null, 'test'));
        $this->assertSame(ActionStatus::ManualReview, $version->plan->fresh()->status);
        $this->assertSame([], $this->lexware->requests('PUT'));
    }
}
