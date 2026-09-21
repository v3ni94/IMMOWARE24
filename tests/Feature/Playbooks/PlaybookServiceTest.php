<?php

declare(strict_types=1);

namespace Tests\Feature\Playbooks;

use App\Core\Enums\Role;
use App\Modules\Playbooks\Enums\MatchOutcome;
use App\Modules\Playbooks\Enums\PlaybookStatus;
use App\Modules\Playbooks\Models\Playbook;
use App\Modules\Playbooks\Models\PlaybookMatch;
use App\Modules\Playbooks\Services\PlaybookService;
use App\Modules\Security\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\Feature\MailUi\CreatesMailCases;
use Tests\TestCase;

final class PlaybookServiceTest extends TestCase
{
    use CreatesMailCases, RefreshDatabase;

    public function test_activating_a_draft_retires_the_previously_active_version_of_the_same_category(): void
    {
        $organization = $this->createOrganization();
        $user = User::factory()->role(Role::Administrator)->for($organization)->create();
        $active = Playbook::query()->create(['organization_id' => $organization->getKey(), 'case_type' => 'schaden', 'title' => 'v1', 'version' => 1, 'status' => PlaybookStatus::Active->value, 'source' => 'learned']);
        $draft = Playbook::query()->create(['organization_id' => $organization->getKey(), 'case_type' => 'schaden', 'title' => 'v2', 'version' => 2, 'status' => PlaybookStatus::Draft->value, 'source' => 'ai_drafted']);

        $service = new PlaybookService;
        $service->activate($draft, $user);

        $this->assertSame(PlaybookStatus::Active, $draft->fresh()->status());
        $this->assertSame($user->getKey(), $draft->fresh()->getAttribute('activated_by'));
        $this->assertSame(PlaybookStatus::Retired, $active->fresh()->status());
    }

    public function test_activating_an_already_active_playbook_is_a_no_op(): void
    {
        $organization = $this->createOrganization();
        $user = User::factory()->role(Role::Administrator)->for($organization)->create();
        $playbook = Playbook::query()->create(['organization_id' => $organization->getKey(), 'case_type' => 'schaden', 'title' => 'v1', 'version' => 1, 'status' => PlaybookStatus::Active->value, 'source' => 'learned']);

        (new PlaybookService)->activate($playbook, $user);

        $this->assertNull($playbook->fresh()->getAttribute('activated_by'));
    }

    public function test_decide_accepted_increments_the_acceptance_counter(): void
    {
        $mailbox = $this->createMailbox();
        $case = $this->createCase($mailbox);
        $user = User::factory()->for($mailbox->organization)->create();
        $playbook = Playbook::query()->create(['organization_id' => $case->getAttribute('organization_id'), 'case_type' => 'schaden', 'title' => 'v1', 'version' => 1, 'status' => 'active', 'source' => 'learned']);
        $match = PlaybookMatch::query()->create(['organization_id' => $case->getAttribute('organization_id'), 'case_id' => $case->getKey(), 'playbook_id' => $playbook->getKey(), 'method' => 'rule', 'outcome' => MatchOutcome::Suggested->value]);

        (new PlaybookService)->decide($match, MatchOutcome::Accepted, $user);

        $this->assertSame(1, $playbook->fresh()->times_accepted);
        $this->assertSame(MatchOutcome::Accepted->value, $match->fresh()->getAttribute('outcome'));
        $this->assertSame($user->getKey(), $match->fresh()->getAttribute('decided_by'));
    }

    public function test_deciding_an_already_decided_match_throws(): void
    {
        $mailbox = $this->createMailbox();
        $case = $this->createCase($mailbox);
        $user = User::factory()->for($mailbox->organization)->create();
        $match = PlaybookMatch::query()->create(['organization_id' => $case->getAttribute('organization_id'), 'case_id' => $case->getKey(), 'method' => 'rule', 'outcome' => MatchOutcome::Rejected->value]);

        $this->expectException(LogicException::class);
        (new PlaybookService)->decide($match, MatchOutcome::Accepted, $user);
    }
}
