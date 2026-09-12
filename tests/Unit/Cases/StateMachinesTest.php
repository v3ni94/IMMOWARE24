<?php

declare(strict_types=1);

namespace Tests\Unit\Cases;

use App\Modules\Actions\Enums\ActionStatus;
use App\Modules\Cases\Enums\CaseStatus;
use App\Modules\Cases\Enums\CommunicationStatus;
use App\Modules\Cases\Enums\TaskStatus;
use App\Modules\Cases\Exceptions\InvalidTransitionException;
use App\Modules\Cases\StateMachines\BusinessStateMachine;
use App\Modules\Cases\StateMachines\CommunicationStateMachine;
use App\Modules\Cases\StateMachines\ProcessingStateMachine;
use App\Modules\Cases\StateMachines\TaskStateMachine;
use PHPUnit\Framework\TestCase;

final class StateMachinesTest extends TestCase
{
    public function test_processing_forbidden_transition_throws(): void
    {
        $machine = new ProcessingStateMachine;

        $this->assertTrue($machine->canTransition(CaseStatus::New, CaseStatus::AssignmentOpen));
        $this->assertTrue($machine->canTransition(CaseStatus::Closed, CaseStatus::Reopened));
        $this->assertFalse($machine->canTransition(CaseStatus::New, CaseStatus::Resolved));

        $this->expectException(InvalidTransitionException::class);
        $machine->assertTransition(CaseStatus::New, CaseStatus::Resolved);
    }

    public function test_communication_matrix(): void
    {
        $machine = new CommunicationStateMachine;

        $this->assertTrue($machine->canTransition(CommunicationStatus::ReplyNeeded, CommunicationStatus::Acknowledged));
        $this->assertTrue($machine->canTransition(CommunicationStatus::Acknowledged, CommunicationStatus::Sent));
        $this->assertTrue($machine->canTransition(CommunicationStatus::Sent, CommunicationStatus::ReplyNeeded));
        $this->assertFalse($machine->canTransition(CommunicationStatus::NoReplyNeeded, CommunicationStatus::Sent));
        $this->assertTrue($machine->isComplete(CommunicationStatus::Sent));
        $this->assertFalse($machine->isComplete(CommunicationStatus::Acknowledged), 'Eingangsbestätigung ist keine Antwort.');

        $this->expectException(InvalidTransitionException::class);
        $machine->assertTransition(CommunicationStatus::NoReplyNeeded, CommunicationStatus::Sent);
    }

    public function test_business_executed_is_not_complete_and_task_verification_matrix(): void
    {
        $business = new BusinessStateMachine;

        $this->assertTrue($business->isInFlight(ActionStatus::Executed));
        $this->assertFalse(ActionStatus::Executed->isBusinessComplete());
        $this->assertTrue($business->hasOpenPartialFailure(ActionStatus::ResultUnclear));
        $this->assertFalse($business->canTransition(ActionStatus::Proposed, ActionStatus::Verified));

        $tasks = new TaskStateMachine;
        $this->assertTrue($tasks->canTransition(TaskStatus::DoneManualConfirmed, TaskStatus::DoneVerified));
        $this->assertFalse($tasks->canTransition(TaskStatus::Open, TaskStatus::DoneVerified));

        $this->expectException(InvalidTransitionException::class);
        $tasks->assertTransition(TaskStatus::DoneVerified, TaskStatus::Open);
    }
}
