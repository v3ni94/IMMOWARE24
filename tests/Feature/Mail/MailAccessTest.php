<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use App\Core\Enums\Role;
use App\Modules\Actions\Enums\ActionStatus;
use App\Modules\Actions\Enums\RiskClass;
use App\Modules\Actions\Enums\TargetSystem;
use App\Modules\Actions\Models\ActionPlan;
use App\Modules\Actions\Models\ActionPlanVersion;
use App\Modules\Actions\Models\Approval;
use App\Modules\Cases\Enums\CaseStatus;
use App\Modules\Cases\Enums\CommunicationStatus;
use App\Modules\Cases\Enums\Priority;
use App\Modules\Cases\Models\MailCase;
use App\Modules\Mail\Models\Mailbox;
use App\Modules\Mail\Models\MailboxPermission;
use App\Modules\Mail\Services\MailAccess;
use App\Modules\Security\Models\User;
use App\Modules\Security\Services\PermissionMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

final class MailAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_mail_permissions_are_merged_additively_into_security_config(): void
    {
        $catalog = (array) config('hub.security.permission_catalog');
        $map = $this->app->make(PermissionMap::class);

        foreach (['connections.manage', 'audit.view', 'records.view', 'payloads.view'] as $existing) {
            $this->assertContains($existing, $catalog);
        }

        foreach ((array) config('hub.mail.permission_catalog') as $permission) {
            $this->assertContains($permission, $catalog);
        }

        $this->assertTrue($map->allows(Role::Owner, 'mail.admin'));
        $this->assertTrue($map->allows(Role::Administrator, 'mail.integrations.manage'));
        $this->assertTrue($map->allows(Role::Operator, 'mail.send'));
        $this->assertFalse($map->allows(Role::Operator, 'mail.admin'));
        $this->assertFalse($map->allows(Role::ReadOnly, 'mail.approve.standard'));
        $this->assertFalse($map->allows(Role::ApiClient, 'mail.inbox.view'));
        // Bestehende Rollenrechte unverändert.
        $this->assertSame(['audit.view', 'exports.run', 'records.view', 'mail.inbox.view', 'mail.export'], (array) config('hub.security.permissions.read_only'));
        $this->assertFalse($map->allows(Role::Operator, 'connections.manage'));
    }

    public function test_mailbox_content_requires_permission_row_even_for_administrator(): void
    {
        $access = $this->app->make(MailAccess::class);
        $admin = $this->actingAsMailRole('admin');
        $mailbox = $this->mailbox;
        $this->assertInstanceOf(Mailbox::class, $mailbox);

        $this->assertTrue($access->canViewMailbox($admin, $mailbox));
        $this->assertTrue(Gate::forUser($admin)->allows('mail.mailbox.view', $mailbox));
        $this->assertTrue(Gate::forUser($admin)->allows('mail.mailbox.view', (int) $mailbox->getKey()));

        MailboxPermission::query()->where('mailbox_id', $mailbox->getKey())->where('user_id', $admin->getKey())->delete();

        $this->assertFalse($access->canViewMailbox($admin, $mailbox));
        $this->assertFalse(Gate::forUser($admin)->allows('mail.mailbox.view', $mailbox));
    }

    public function test_mailbox_of_other_organization_is_never_visible(): void
    {
        $access = $this->app->make(MailAccess::class);
        $user = $this->actingAsMailRole('lead');
        $foreign = $this->createMailbox();

        MailboxPermission::query()->create(['mailbox_id' => $foreign->getKey(), 'user_id' => $user->getKey(), 'can_read' => true]);

        $this->assertFalse($access->canViewMailbox($user, $foreign));
    }

    public function test_agent_cannot_view_bank_data_but_lead_can(): void
    {
        $access = $this->app->make(MailAccess::class);
        $agent = $this->actingAsMailRole('agent');
        $mailbox = $this->mailbox;
        $lead = User::factory()->role(Role::Operator)->for($mailbox->organization)->create();
        $this->attachMailRole($lead, $mailbox, 'lead');

        $this->assertFalse($access->canViewBankData($agent, $mailbox));
        $this->assertTrue($access->canViewBankData($lead, $mailbox));
        $this->assertTrue($access->canSendFromMailbox($agent, $mailbox));
        $this->assertFalse($access->can($agent, 'mail.case.assign', (int) $mailbox->team_id));
        $this->assertTrue($access->can($lead, 'mail.case.assign', (int) $mailbox->team_id));
    }

    public function test_author_cannot_approve_own_version_and_four_eyes_requires_matching_hash(): void
    {
        $access = $this->app->make(MailAccess::class);
        $author = $this->actingAsMailRole('lead');
        $mailbox = $this->mailbox;
        $approver = User::factory()->role(Role::Operator)->for($mailbox->organization)->create();
        $this->attachMailRole($approver, $mailbox, 'approver');
        $agent = User::factory()->role(Role::Operator)->for($mailbox->organization)->create();
        $this->attachMailRole($agent, $mailbox, 'agent');

        $case = MailCase::query()->create([
            'organization_id' => $mailbox->organization_id,
            'case_number' => 'V-2026-000001',
            'mailbox_id' => $mailbox->getKey(),
            'team_id' => $mailbox->team_id,
            'title' => 'Bankdatenänderung Mieter',
            'case_type' => 'bankdaten',
            'priority' => Priority::P2,
            'status_processing' => CaseStatus::Open,
            'status_communication' => CommunicationStatus::Acknowledged,
            'status_business' => ActionStatus::Proposed,
            'assignee_user_id' => $author->getKey(),
            'next_step' => 'Alt/Neu erfassen',
            'due_at' => now()->addDay(),
            'opened_at' => now(),
        ]);
        $plan = ActionPlan::query()->create([
            'organization_id' => $mailbox->organization_id,
            'case_id' => $case->getKey(),
            'status' => ActionStatus::ApprovalRequired,
            'risk_class' => RiskClass::Bank,
            'target_system' => TargetSystem::Manual,
            'created_by' => $author->getKey(),
        ]);
        $steps = [['action_key' => 'manual.change.immoware_bank', 'target_system' => 'manual']];
        $version = ActionPlanVersion::query()->create([
            'action_plan_id' => $plan->getKey(),
            'version' => 1,
            'steps_json' => $steps,
            'steps_hash' => hash('sha256', json_encode($steps, JSON_THROW_ON_ERROR)),
            'generated_by' => 'user',
            'author_user_id' => $author->getKey(),
        ]);
        $plan->update(['current_version_id' => $version->getKey()]);
        $plan = $plan->fresh(['currentVersion', 'case']);

        $this->assertFalse($access->canApprove($author, $plan), 'Autor darf eigene Version nicht freigeben.');
        $this->assertFalse($access->canApprove($agent, $plan), 'Sachbearbeitung ohne approve-Recht.');
        $this->assertTrue($access->canApprove($approver, $plan));
        $this->assertFalse($access->fourEyesSatisfied($plan));

        Approval::query()->create([
            'action_plan_version_id' => $version->getKey(),
            'approver_user_id' => $approver->getKey(),
            'decision' => 'approved',
            'steps_hash' => str_repeat('0', 64),
            'reauth_confirmed_at' => now(),
        ]);
        $this->assertFalse($access->fourEyesSatisfied($plan), 'Freigabe mit falschem Hash zählt nicht.');

        Approval::query()->where('action_plan_version_id', $version->getKey())->update(['steps_hash' => $version->steps_hash]);
        $this->assertTrue($access->fourEyesSatisfied($plan->fresh(['currentVersion'])));
    }

    public function test_standard_plan_needs_only_standard_approval_right(): void
    {
        $access = $this->app->make(MailAccess::class);
        $author = $this->actingAsMailRole('agent');
        $mailbox = $this->mailbox;
        $lead = User::factory()->role(Role::Operator)->for($mailbox->organization)->create();
        $this->attachMailRole($lead, $mailbox, 'lead');

        $case = MailCase::query()->create([
            'organization_id' => $mailbox->organization_id, 'case_number' => 'V-2026-000002', 'mailbox_id' => $mailbox->getKey(),
            'team_id' => $mailbox->team_id, 'title' => 'Antwort an Eigentümer', 'priority' => Priority::P3, 'opened_at' => now(),
        ]);
        $plan = ActionPlan::query()->create([
            'organization_id' => $mailbox->organization_id, 'case_id' => $case->getKey(), 'risk_class' => RiskClass::High,
            'target_system' => TargetSystem::Gmail, 'created_by' => $author->getKey(),
        ]);
        $version = ActionPlanVersion::query()->create([
            'action_plan_id' => $plan->getKey(), 'version' => 1, 'steps_json' => [], 'steps_hash' => hash('sha256', '[]'), 'author_user_id' => $author->getKey(),
        ]);
        $plan->update(['current_version_id' => $version->getKey()]);

        $this->assertTrue($access->canApprove($lead, $plan->fresh(['currentVersion', 'case'])), 'Teamleitung hat mail.approve.standard.');
        $this->assertSame(RiskClass::High, $plan->fresh()->risk_class);
    }
}
