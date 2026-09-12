<?php

declare(strict_types=1);

namespace Tests\Feature\MailIntegration;

use App\Core\Enums\Role;
use App\Modules\Actions\Enums\ActionStatus;
use App\Modules\Cases\Enums\CaseStatus;
use App\Modules\Cases\Enums\CommunicationStatus;
use App\Modules\Cases\Enums\Priority;
use App\Modules\Cases\Models\CaseItem;
use App\Modules\Cases\Services\CloseConditionChecker;
use App\Modules\Security\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\MailUi\CreatesMailCases;
use Tests\TestCase;

/**
 * Teilerfolg (docs/mail/04): Planstatus result_unclear, manual_review und executing werden in status_business des
 * Teilanliegens gespiegelt, damit CloseConditionChecker den Abschluss blockiert. Verified bleibt ExecutionVerified.
 */
final class PlanStatusMirrorTest extends TestCase
{
    use CreatesMailCases;
    use RefreshDatabase;

    public function test_plan_status_is_mirrored_into_case_item_and_blocks_close(): void
    {
        $lead = $this->actingAsMailRole('lead');
        $author = User::factory()->role(Role::Operator)->for($this->mailbox->organization)->create();
        $case = $this->createCase($this->mailbox, ['assignee_user_id' => $lead->getKey(), 'status_processing' => CaseStatus::InProgress]);
        $item = CaseItem::query()->create([
            'case_id' => $case->getKey(),
            'position' => 1,
            'item_type' => 'anfrage_allgemein',
            'title' => 'Auskunft',
            'status_processing' => CaseStatus::InProgress->value,
            'status_communication' => CommunicationStatus::Sent->value,
            'status_business' => ActionStatus::Proposed->value,
            'priority' => Priority::P2->value,
            'received_at' => now(),
        ]);
        $checker = $this->app->make(CloseConditionChecker::class);
        $this->assertSame([], $checker->unmetForItem($item->fresh()), 'Ohne begonnene Aktion ist die Auskunft abschließbar.');

        // Plan mit Freigabepflicht: bereits die begonnene Aktion blockiert den Abschluss des Teilanliegens.
        $plan = $this->createBankPlan($case, $author);
        $plan->forceFill(['case_item_id' => $item->getKey()])->save();
        $this->assertSame(ActionStatus::ApprovalRequired, $item->fresh()->status_business);
        $this->assertNotSame([], $checker->unmetForItem($item->fresh()));

        $plan->forceFill(['status' => ActionStatus::Executing])->save();
        $this->assertSame(ActionStatus::Executing, $item->fresh()->status_business);
        $this->assertNotSame([], $checker->unmetForItem($item->fresh()));

        $plan->forceFill(['status' => ActionStatus::ResultUnclear])->save();
        $this->assertSame(ActionStatus::ResultUnclear, $item->fresh()->status_business);
        $unmet = $checker->unmetForItem($item->fresh());
        $this->assertTrue(collect($unmet)->contains(static fn (string $line): bool => str_contains($line, 'Teilfehler')), implode(' | ', $unmet));

        $plan->forceFill(['status' => ActionStatus::ManualReview])->save();
        $this->assertSame(ActionStatus::ManualReview, $item->fresh()->status_business);

        // Verified wird nicht gespiegelt (nur über ExecutionVerified nach Nachlesen).
        $plan->forceFill(['status' => ActionStatus::Verified])->save();
        $this->assertSame(ActionStatus::ManualReview, $item->fresh()->status_business);
    }
}
