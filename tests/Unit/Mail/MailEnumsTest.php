<?php

declare(strict_types=1);

namespace Tests\Unit\Mail;

use App\Modules\Actions\Enums\ActionStatus;
use App\Modules\Actions\Enums\RiskClass;
use App\Modules\Actions\Enums\VerificationStatus;
use App\Modules\Cases\Enums\CaseStatus;
use App\Modules\Cases\Enums\CommunicationStatus;
use App\Modules\Cases\Enums\Priority;
use PHPUnit\Framework\TestCase;

final class MailEnumsTest extends TestCase
{
    public function test_executed_is_not_a_business_result(): void
    {
        $this->assertFalse(ActionStatus::Executed->isBusinessComplete());
        $this->assertTrue(ActionStatus::Verified->isBusinessComplete());
        $this->assertTrue(ActionStatus::Executed->canTransitionTo(ActionStatus::ResultUnclear));
        $this->assertFalse(ActionStatus::Proposed->canTransitionTo(ActionStatus::Verified));
        $this->assertFalse(VerificationStatus::Unverified->countsAsVerified());
        $this->assertTrue(VerificationStatus::ManuallyConfirmed->countsAsVerified());
    }

    public function test_case_status_open_and_transitions(): void
    {
        $this->assertTrue(CaseStatus::New->isOpen());
        $this->assertFalse(CaseStatus::Closed->isOpen());
        $this->assertTrue(CaseStatus::Closed->canTransitionTo(CaseStatus::Reopened));
        $this->assertFalse(CaseStatus::New->canTransitionTo(CaseStatus::Resolved));
        $this->assertTrue(CaseStatus::WaitingCustomer->pausesClocks());
        $this->assertTrue(CommunicationStatus::Sent->requiresAction() === false);
        $this->assertCount(11, CaseStatus::cases());
    }

    public function test_priority_and_risk(): void
    {
        $this->assertTrue(Priority::P0->usesCalendarTime());
        $this->assertTrue(Priority::P0->isHigherThan(Priority::P1));
        $this->assertSame('P2', Priority::P2->short());
        $this->assertSame('mail.approve.bank', RiskClass::Bank->approvalPermission());
        $this->assertFalse(RiskClass::Low->requiresApproval());
    }
}
