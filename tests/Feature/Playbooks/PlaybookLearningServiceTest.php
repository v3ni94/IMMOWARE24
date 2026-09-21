<?php

declare(strict_types=1);

namespace Tests\Feature\Playbooks;

use App\Modules\Ai\Testing\FakeAiProvider;
use App\Modules\Cases\Models\Task;
use App\Modules\Playbooks\Enums\MatchOutcome;
use App\Modules\Playbooks\Enums\PlaybookSource;
use App\Modules\Playbooks\Enums\PlaybookStatus;
use App\Modules\Playbooks\Models\Playbook;
use App\Modules\Playbooks\Models\PlaybookMatch;
use App\Modules\Playbooks\Services\PlaybookLearningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\MailUi\CreatesMailCases;
use Tests\TestCase;

final class PlaybookLearningServiceTest extends TestCase
{
    use CreatesMailCases, RefreshDatabase;

    private function enableAi(): void
    {
        config()->set('hub.playbooks.flags.enabled', true);
        config()->set('hub.playbooks.flags.ai', true);
        config()->set('hub.mail.flags.ai', true);
        config()->set('hub.mail.providers.ai', 'fake');
    }

    public function test_disabled_ai_does_nothing(): void
    {
        config()->set('hub.playbooks.flags.ai', false);
        $case = $this->createCase($this->createMailbox());

        $this->app->make(PlaybookLearningService::class)->learnFromClosedCase($case);

        $this->assertDatabaseCount('mail_playbooks', 0);
    }

    public function test_a_closed_case_without_a_playbook_proposes_a_new_draft_from_its_history(): void
    {
        $this->enableAi();
        $case = $this->createCase($this->createMailbox(), ['case_type' => 'schaden']);
        Task::query()->create([
            'organization_id' => $case->getAttribute('organization_id'), 'case_id' => $case->getKey(),
            'task_type' => 'other', 'title' => 'Handwerker beauftragt', 'status' => 'done_manual_confirmed',
        ]);

        /** @var FakeAiProvider $fake */
        $fake = $this->app->make(FakeAiProvider::class);
        $fake->setDefault('playbook_draft_steps', [
            'title' => 'Schadensmeldung bearbeiten',
            'steps' => [['action_type' => 'internal_review', 'description' => 'Handwerker beauftragen', 'typical_offset_hours' => 4, 'requires_approval' => false]],
            'notes' => [],
        ]);

        $this->app->make(PlaybookLearningService::class)->learnFromClosedCase($case);

        $this->assertDatabaseHas('mail_playbooks', [
            'case_type' => 'schaden', 'status' => PlaybookStatus::Draft->value, 'source' => PlaybookSource::AiDrafted->value,
            'created_from_case_id' => $case->getKey(),
        ]);
    }

    public function test_a_second_closed_case_of_the_same_type_does_not_create_a_duplicate_draft(): void
    {
        $this->enableAi();
        $caseType = 'schaden';
        $mailbox = $this->createMailbox();
        Playbook::query()->create([
            'organization_id' => $mailbox->getAttribute('organization_id'), 'case_type' => $caseType, 'title' => 'Entwurf 1', 'version' => 1,
            'status' => PlaybookStatus::Draft->value, 'source' => PlaybookSource::AiDrafted->value,
        ]);
        $case = $this->createCase($mailbox, ['case_type' => $caseType]);

        $this->app->make(PlaybookLearningService::class)->learnFromClosedCase($case);

        $this->assertDatabaseCount('mail_playbooks', 1);
    }

    public function test_an_adjusted_match_proposes_a_refined_version_of_the_matched_playbook(): void
    {
        $this->enableAi();
        $case = $this->createCase($this->createMailbox(), ['case_type' => 'schaden']);
        $playbook = Playbook::query()->create([
            'organization_id' => $case->getAttribute('organization_id'), 'case_type' => 'schaden', 'title' => 'Vorlage',
            'version' => 1, 'status' => PlaybookStatus::Active->value, 'source' => 'learned',
            'steps_json' => [['action_type' => 'internal_review', 'description' => 'alt', 'typical_offset_hours' => null, 'requires_approval' => false]],
        ]);
        PlaybookMatch::query()->create([
            'organization_id' => $case->getAttribute('organization_id'), 'case_id' => $case->getKey(),
            'playbook_id' => $playbook->getKey(), 'method' => 'rule', 'outcome' => MatchOutcome::Adjusted->value,
            'deviations_json' => ['zusätzlich Foto angefordert'],
        ]);

        /** @var FakeAiProvider $fake */
        $fake = $this->app->make(FakeAiProvider::class);
        $fake->setDefault('playbook_draft_steps', [
            'title' => 'Vorlage', 'notes' => [],
            'steps' => [
                ['action_type' => 'internal_review', 'description' => 'alt', 'typical_offset_hours' => null, 'requires_approval' => false],
                ['action_type' => 'request_documents', 'description' => 'Foto anfordern', 'typical_offset_hours' => 1, 'requires_approval' => false],
            ],
        ]);

        $this->app->make(PlaybookLearningService::class)->learnFromClosedCase($case);

        $this->assertDatabaseHas('mail_playbooks', [
            'previous_version_id' => $playbook->getKey(), 'version' => 2, 'status' => PlaybookStatus::Draft->value,
        ]);
    }

    public function test_a_rejected_match_is_treated_like_no_match_and_proposes_a_new_draft(): void
    {
        $this->enableAi();
        $case = $this->createCase($this->createMailbox(), ['case_type' => 'schaden']);
        $playbook = Playbook::query()->create([
            'organization_id' => $case->getAttribute('organization_id'), 'case_type' => 'schaden', 'title' => 'Vorlage',
            'version' => 1, 'status' => PlaybookStatus::Active->value, 'source' => 'learned',
        ]);
        PlaybookMatch::query()->create([
            'organization_id' => $case->getAttribute('organization_id'), 'case_id' => $case->getKey(),
            'playbook_id' => $playbook->getKey(), 'method' => 'rule', 'outcome' => MatchOutcome::Rejected->value,
        ]);

        /** @var FakeAiProvider $fake */
        $fake = $this->app->make(FakeAiProvider::class);
        $fake->setDefault('playbook_draft_steps', [
            'title' => 'Neu',
            'steps' => [['action_type' => 'internal_review', 'description' => 'prüfen', 'typical_offset_hours' => null, 'requires_approval' => false]],
            'notes' => [],
        ]);

        $this->app->make(PlaybookLearningService::class)->learnFromClosedCase($case);

        $this->assertDatabaseHas('mail_playbooks', ['previous_version_id' => null, 'source' => PlaybookSource::AiDrafted->value, 'created_from_case_id' => $case->getKey()]);
    }
}
