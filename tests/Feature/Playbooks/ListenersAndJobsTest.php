<?php

declare(strict_types=1);

namespace Tests\Feature\Playbooks;

use App\Modules\Cases\Enums\CaseStatus;
use App\Modules\Cases\Events\CaseOpened;
use App\Modules\Cases\Events\CaseStatusChanged;
use App\Modules\Cases\StateMachines\ProcessingStateMachine;
use App\Modules\Playbooks\Jobs\LearnPlaybookJob;
use App\Modules\Playbooks\Jobs\MatchPlaybookJob;
use App\Modules\Playbooks\Services\PlaybookLearningService;
use App\Modules\Playbooks\Services\PlaybookMatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\MailUi\CreatesMailCases;
use Tests\TestCase;

final class ListenersAndJobsTest extends TestCase
{
    use CreatesMailCases, RefreshDatabase;

    public function test_case_opened_queues_a_match_job_only_when_enabled(): void
    {
        Queue::fake();
        $case = $this->createCase($this->createMailbox());

        config()->set('hub.playbooks.flags.enabled', false);
        Event::dispatch(new CaseOpened($case));
        Queue::assertNotPushed(MatchPlaybookJob::class);

        config()->set('hub.playbooks.flags.enabled', true);
        Event::dispatch(new CaseOpened($case));
        Queue::assertPushed(MatchPlaybookJob::class, fn (MatchPlaybookJob $job): bool => $job->caseId === $case->getKey());
    }

    public function test_case_closed_queues_a_learn_job_only_for_the_processing_dimension(): void
    {
        Queue::fake();
        config()->set('hub.playbooks.flags.enabled', true);
        $case = $this->createCase($this->createMailbox());

        Event::dispatch(new CaseStatusChanged($case, 'communication', 'open', CaseStatus::Closed->value));
        Queue::assertNotPushed(LearnPlaybookJob::class);

        Event::dispatch(new CaseStatusChanged($case, ProcessingStateMachine::DIMENSION, 'open', 'in_progress'));
        Queue::assertNotPushed(LearnPlaybookJob::class);

        Event::dispatch(new CaseStatusChanged($case, ProcessingStateMachine::DIMENSION, 'open', CaseStatus::Closed->value));
        Queue::assertPushed(LearnPlaybookJob::class, fn (LearnPlaybookJob $job): bool => $job->caseId === $case->getKey());
    }

    public function test_match_job_is_a_noop_for_a_missing_case(): void
    {
        (new MatchPlaybookJob(999999))->handle($this->app->make(PlaybookMatchService::class));
        $this->addToAssertionCount(1);
    }

    public function test_learn_job_is_a_noop_for_a_missing_case(): void
    {
        (new LearnPlaybookJob(999999))->handle($this->app->make(PlaybookLearningService::class));
        $this->addToAssertionCount(1);
    }
}
