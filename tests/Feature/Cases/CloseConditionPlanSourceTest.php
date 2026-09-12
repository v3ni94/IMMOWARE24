<?php

declare(strict_types=1);

namespace Tests\Feature\Cases;

use App\Core\Enums\Role;
use App\Modules\Actions\Enums\ActionStatus;
use App\Modules\Cases\Enums\CaseStatus;
use App\Modules\Cases\Enums\CommunicationStatus;
use App\Modules\Cases\Enums\Priority;
use App\Modules\Cases\Models\CaseItem;
use App\Modules\Cases\Services\CloseConditionChecker;
use App\Modules\Security\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\MailUi\CreatesMailCases;
use Tests\TestCase;

/**
 * CloseConditionChecker liest das Geschäftsergebnis aus beiden Quellen (status_business des Teilanliegens sowie
 * mail_action_plans und mail_executions). Die strengste Aussage gewinnt, auch wenn die Spiegelung ausblieb.
 */
final class CloseConditionPlanSourceTest extends TestCase
{
    use CreatesMailCases;
    use RefreshDatabase;

    public function test_failed_plan_blocks_close_even_if_item_status_was_not_mirrored(): void
    {
        [$item, $checker, $planId] = $this->itemWithPlan(ActionStatus::Failed);

        $this->assertSame(ActionStatus::Proposed, $item->fresh()->status_business, 'Spiegelung wurde bewusst umgangen.');
        $this->assertSame(ActionStatus::Failed, $checker->effectiveBusinessStatus($item->fresh()));
        $unmet = $checker->unmetForItem($item->fresh());
        $this->assertTrue(collect($unmet)->contains(static fn (string $l): bool => str_contains($l, 'Teilfehler')), implode(' | ', $unmet));
        $this->assertIsInt($planId);
    }

    public function test_failed_execution_of_current_version_blocks_close_although_plan_and_item_look_clean(): void
    {
        [$item, $checker, $planId] = $this->itemWithPlan(ActionStatus::Executed);
        DB::table('mail_case_items')->where('id', $item->getKey())->update(['status_business' => ActionStatus::Verified->value]);

        $versionId = (int) DB::table('mail_action_plans')->where('id', $planId)->value('current_version_id');
        DB::table('mail_executions')->insert([
            'action_plan_version_id' => $versionId,
            'step_index' => 0,
            'execution_uuid' => (string) Str::uuid(),
            'idempotency_key' => 'idem-'.Str::random(12),
            'action_key' => 'bank_change',
            'target_system' => 'lexware',
            'status' => 'failed',
            'attempts' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(ActionStatus::Failed, $checker->effectiveBusinessStatus($item->fresh()));
        $this->assertNotSame([], $checker->unmetForItem($item->fresh()));
    }

    public function test_verified_item_with_verified_plan_and_executions_is_closable(): void
    {
        [$item, $checker, $planId] = $this->itemWithPlan(ActionStatus::Verified);
        DB::table('mail_case_items')->where('id', $item->getKey())->update(['status_business' => ActionStatus::Verified->value]);
        $versionId = (int) DB::table('mail_action_plans')->where('id', $planId)->value('current_version_id');
        DB::table('mail_executions')->insert([
            'action_plan_version_id' => $versionId,
            'step_index' => 0,
            'execution_uuid' => (string) Str::uuid(),
            'idempotency_key' => 'idem-'.Str::random(12),
            'action_key' => 'bank_change',
            'target_system' => 'lexware',
            'status' => 'verified',
            'attempts' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(ActionStatus::Verified, $checker->effectiveBusinessStatus($item->fresh()));
        $this->assertSame([], $checker->unmetForItem($item->fresh()));
    }

    /**
     * Plan ohne Eloquent-Ereignisse anlegen, damit die Spiegelung (MirrorPlanStatusToCaseItem) nicht greift.
     *
     * @return array{0: CaseItem, 1: CloseConditionChecker, 2: int}
     */
    private function itemWithPlan(ActionStatus $planStatus): array
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

        $planId = (int) DB::table('mail_action_plans')->insertGetId([
            'organization_id' => $case->getAttribute('organization_id'),
            'case_id' => $case->getKey(),
            'case_item_id' => $item->getKey(),
            'status' => $planStatus->value,
            'risk_class' => 'bank',
            'target_system' => 'lexware',
            'created_by' => $author->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $versionId = (int) DB::table('mail_action_plan_versions')->insertGetId([
            'action_plan_id' => $planId,
            'version' => 1,
            'steps_json' => json_encode([['target_system' => 'lexware', 'action_type' => 'bank_change']]),
            'steps_hash' => hash('sha256', 'steps'),
            'generated_by' => 'user',
            'author_user_id' => $author->getKey(),
            'created_at' => now(),
        ]);
        DB::table('mail_action_plans')->where('id', $planId)->update(['current_version_id' => $versionId]);

        return [$item, $this->app->make(CloseConditionChecker::class), $planId];
    }
}
