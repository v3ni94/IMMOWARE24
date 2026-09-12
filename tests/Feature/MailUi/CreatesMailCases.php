<?php

declare(strict_types=1);

namespace Tests\Feature\MailUi;

use App\Modules\Actions\Enums\ActionStatus;
use App\Modules\Actions\Enums\RiskClass;
use App\Modules\Actions\Enums\TargetSystem;
use App\Modules\Actions\Models\ActionPlan;
use App\Modules\Actions\Models\ActionPlanVersion;
use App\Modules\Cases\Enums\CaseStatus;
use App\Modules\Cases\Enums\CommunicationStatus;
use App\Modules\Cases\Enums\Priority;
use App\Modules\Cases\Models\MailCase;
use App\Modules\Mail\Models\Mailbox;
use App\Modules\Security\Models\User;

/**
 * Testdaten der Oberfläche: Vorgang je Postfach, Aktionsplan mit Bankdaten (Alt/Neu verschlüsselt) und aktueller Version.
 */
trait CreatesMailCases
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function createCase(Mailbox $mailbox, array $attributes = []): MailCase
    {
        static $counter = 0;
        $counter++;

        return MailCase::query()->create(array_merge([
            'organization_id' => $mailbox->getAttribute('organization_id'),
            'case_number' => sprintf('V-2026-%06d', $counter),
            'mailbox_id' => $mailbox->getKey(),
            'team_id' => $mailbox->team_id,
            'title' => 'Bankdatenänderung Mieter Beispiel',
            'case_type' => 'bankdaten',
            'priority' => Priority::P1,
            'priority_reason' => 'Regel: Bankdaten',
            'status_processing' => CaseStatus::Open,
            'status_communication' => CommunicationStatus::ReplyNeeded,
            'status_business' => ActionStatus::Proposed,
            'next_step' => 'Alt/Neu prüfen',
            'due_at' => now()->addDay(),
            'opened_at' => now()->subHour(),
        ], $attributes));
    }

    protected function createBankPlan(MailCase $case, User $author, int $requiredApprovals = 1): ActionPlan
    {
        $plan = ActionPlan::query()->create([
            'organization_id' => $case->getAttribute('organization_id'),
            'case_id' => $case->getKey(),
            'status' => ActionStatus::ApprovalRequired,
            'risk_class' => RiskClass::Bank,
            'target_system' => TargetSystem::Lexware,
            'created_by' => $author->getKey(),
        ]);

        $version = ActionPlanVersion::query()->create([
            'action_plan_id' => $plan->getKey(),
            'version' => 1,
            'steps_json' => [['target_system' => 'lexware', 'action_type' => 'bank_change']],
            'steps_hash' => hash('sha256', 'steps-v1'),
            'generated_by' => 'user',
            'author_user_id' => $author->getKey(),
            'target_system' => TargetSystem::Lexware,
            'action_type' => 'bank_change',
            'risk_class' => RiskClass::Bank,
            'required_approvals' => $requiredApprovals,
            'old_values' => ['iban' => 'DE02120300000000202051', 'kontoinhaber' => 'Max Beispiel'],
            'new_values' => ['iban' => 'DE02500105170137075030', 'kontoinhaber' => 'Max Beispiel'],
        ]);

        $plan->forceFill(['current_version_id' => $version->getKey()])->save();

        return $plan->fresh(['currentVersion']);
    }
}
