<?php

declare(strict_types=1);

namespace Tests\Feature\MailUi;

use App\Modules\Playbooks\Enums\MatchOutcome;
use App\Modules\Playbooks\Enums\PlaybookStatus;
use App\Modules\Playbooks\Models\Playbook;
use App\Modules\Playbooks\Models\PlaybookMatch;
use App\Modules\Security\Services\LoginService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PlaybooksControllerTest extends TestCase
{
    use CreatesMailCases, RefreshDatabase;

    public function test_index_requires_mail_admin_permission(): void
    {
        $mailbox = $this->createMailbox();
        $this->actingAsMailRole('agent', $mailbox);

        $this->get(route('mail.admin.playbooks.index'))->assertForbidden();
    }

    public function test_index_and_show_render_for_an_admin(): void
    {
        $admin = $this->actingAsMailRole('admin');
        $this->withSession([LoginService::SESSION_TWO_FACTOR_VERIFIED => now()->toIso8601String()]);
        $playbook = Playbook::query()->create([
            'organization_id' => $admin->getAttribute('organization_id'), 'case_type' => 'schaden', 'title' => 'Heizungsausfall',
            'version' => 1, 'status' => PlaybookStatus::Active->value, 'source' => 'learned',
            'steps_json' => [['action_type' => 'internal_review', 'description' => 'prüfen', 'typical_offset_hours' => 2, 'requires_approval' => false]],
        ]);

        $this->get(route('mail.admin.playbooks.index'))->assertOk()->assertSeeText('Heizungsausfall');
        $this->get(route('mail.admin.playbooks.show', ['playbook' => $playbook->getKey()]))->assertOk()->assertSeeText('internal_review');
    }

    public function test_activate_retires_the_previous_version_and_retire_takes_it_out_of_service(): void
    {
        $admin = $this->actingAsMailRole('admin');
        $this->withSession([LoginService::SESSION_TWO_FACTOR_VERIFIED => now()->toIso8601String()]);
        $active = Playbook::query()->create(['organization_id' => $admin->getAttribute('organization_id'), 'case_type' => 'schaden', 'title' => 'v1', 'version' => 1, 'status' => 'active', 'source' => 'learned']);
        $draft = Playbook::query()->create(['organization_id' => $admin->getAttribute('organization_id'), 'case_type' => 'schaden', 'title' => 'v2', 'version' => 2, 'status' => 'draft', 'source' => 'ai_drafted']);

        $this->post(route('mail.admin.playbooks.activate', ['playbook' => $draft->getKey()]))
            ->assertRedirect(route('mail.admin.playbooks.show', ['playbook' => $draft->getKey()]));

        $this->assertSame('active', $draft->fresh()->getAttribute('status'));
        $this->assertSame('retired', $active->fresh()->getAttribute('status'));

        $this->post(route('mail.admin.playbooks.retire', ['playbook' => $draft->getKey()]));
        $this->assertSame('retired', $draft->fresh()->getAttribute('status'));
    }

    public function test_matches_page_lists_open_matches_and_decide_records_the_decision(): void
    {
        $admin = $this->actingAsMailRole('admin');
        $this->withSession([LoginService::SESSION_TWO_FACTOR_VERIFIED => now()->toIso8601String()]);
        $case = $this->createCase($this->createMailbox(null, null, null, ['organization_id' => $admin->getAttribute('organization_id')]));
        $playbook = Playbook::query()->create(['organization_id' => $admin->getAttribute('organization_id'), 'case_type' => 'schaden', 'title' => 'v1', 'version' => 1, 'status' => 'active', 'source' => 'learned']);
        $match = PlaybookMatch::query()->create(['organization_id' => $admin->getAttribute('organization_id'), 'case_id' => $case->getKey(), 'playbook_id' => $playbook->getKey(), 'method' => 'rule', 'outcome' => MatchOutcome::Suggested->value]);

        $this->get(route('mail.admin.playbooks.matches'))->assertOk()->assertSeeText($case->getAttribute('title'));

        $this->post(route('mail.admin.playbooks.matches.decide', ['match' => $match->getKey()]), ['decision' => 'accepted'])
            ->assertRedirect(route('mail.admin.playbooks.matches'));

        $this->assertSame(MatchOutcome::Accepted->value, $match->fresh()->getAttribute('outcome'));
        $this->assertSame(1, $playbook->fresh()->times_accepted);
    }
}
