<?php

declare(strict_types=1);

namespace Tests\Feature\Playbooks;

use App\Modules\Ai\Testing\FakeAiProvider;
use App\Modules\Cases\Models\MailCase;
use App\Modules\Playbooks\Enums\MatchMethod;
use App\Modules\Playbooks\Enums\MatchOutcome;
use App\Modules\Playbooks\Enums\PlaybookStatus;
use App\Modules\Playbooks\Models\Playbook;
use App\Modules\Playbooks\Services\PlaybookMatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\MailUi\CreatesMailCases;
use Tests\TestCase;

final class PlaybookMatchServiceTest extends TestCase
{
    use CreatesMailCases, RefreshDatabase;

    private function activePlaybook(MailCase $case, array $overrides = []): Playbook
    {
        return Playbook::query()->create(array_merge([
            'organization_id' => $case->getAttribute('organization_id'),
            'case_type' => $case->getAttribute('case_type'),
            'title' => 'Heizungsausfall bearbeiten',
            'version' => 1,
            'status' => PlaybookStatus::Active->value,
            'source' => 'learned',
            'match_signature_json' => ['keywords' => ['heizung', 'kaputt', 'ausfall']],
            'steps_json' => [['action_type' => 'internal_review', 'description' => 'Prüfen', 'typical_offset_hours' => 2, 'requires_approval' => false]],
        ], $overrides));
    }

    public function test_without_any_candidate_it_records_no_match_without_calling_ai(): void
    {
        Http::fake();
        $mailbox = $this->createMailbox();
        $case = $this->createCase($mailbox, ['title' => 'Heizung kaputt', 'case_type' => 'schaden']);

        $result = $this->app->make(PlaybookMatchService::class)->match($case);

        $this->assertSame(MatchOutcome::NoMatch, $result->outcome);
        $this->assertNull($result->playbook);
        $this->assertDatabaseHas('mail_playbook_matches', ['case_id' => $case->getKey(), 'outcome' => 'no_match', 'method' => 'rule']);
        Http::assertNothingSent();
    }

    public function test_a_strong_keyword_match_is_suggested_without_calling_ai(): void
    {
        Http::fake();
        $mailbox = $this->createMailbox();
        $case = $this->createCase($mailbox, ['title' => 'Heizung kaputt Ausfall', 'case_type' => 'schaden']);
        $playbook = $this->activePlaybook($case);

        $result = $this->app->make(PlaybookMatchService::class)->match($case);

        $this->assertSame(MatchOutcome::Suggested, $result->outcome);
        $this->assertSame($playbook->getKey(), $result->playbook?->getKey());
        $this->assertSame(MatchMethod::Rule, $result->match->method());
        Http::assertNothingSent();
        $this->assertSame(1, $playbook->fresh()->times_suggested);
    }

    public function test_a_weak_match_below_the_low_threshold_is_no_match_without_calling_ai(): void
    {
        Http::fake();
        config()->set('hub.playbooks.match.low_threshold', 90);
        $mailbox = $this->createMailbox();
        $case = $this->createCase($mailbox, ['title' => 'Ganz anderes Anliegen zur Nebenkostenabrechnung', 'case_type' => 'schaden']);
        $this->activePlaybook($case);

        $result = $this->app->make(PlaybookMatchService::class)->match($case);

        $this->assertSame(MatchOutcome::NoMatch, $result->outcome);
        Http::assertNothingSent();
    }

    public function test_a_borderline_match_without_ai_enabled_still_suggests_the_rule_based_best_candidate(): void
    {
        Http::fake();
        config()->set('hub.playbooks.match.high_threshold', 95);
        config()->set('hub.playbooks.match.low_threshold', 10);
        config()->set('hub.playbooks.flags.ai', false);
        $mailbox = $this->createMailbox();
        $case = $this->createCase($mailbox, ['title' => 'Heizung wird bald ausgetauscht, Frage zum Termin', 'case_type' => 'schaden']);
        $playbook = $this->activePlaybook($case);

        $result = $this->app->make(PlaybookMatchService::class)->match($case);

        $this->assertSame(MatchOutcome::Suggested, $result->outcome);
        $this->assertSame(MatchMethod::Rule, $result->match->method());
        $this->assertSame($playbook->getKey(), $result->playbook?->getKey());
        Http::assertNothingSent();
    }

    public function test_a_borderline_match_with_ai_enabled_asks_the_ai_and_records_deviations(): void
    {
        config()->set('hub.playbooks.match.high_threshold', 95);
        config()->set('hub.playbooks.match.low_threshold', 10);
        config()->set('hub.playbooks.flags.ai', true);
        config()->set('hub.mail.flags.ai', true);
        config()->set('hub.mail.providers.ai', 'fake');

        $mailbox = $this->createMailbox();
        $case = $this->createCase($mailbox, ['title' => 'Heizung wird bald ausgetauscht, Frage zum Termin', 'case_type' => 'schaden']);
        $playbook = $this->activePlaybook($case);

        /** @var FakeAiProvider $fake */
        $fake = $this->app->make(FakeAiProvider::class);
        $fake->setDefault('playbook_match', [
            'best_match' => ['playbook_id' => (string) $playbook->getKey(), 'confidence_percent' => 70, 'reason' => 'passt im Kern'],
            'deviations' => ['Termin ist noch offen, kein akuter Ausfall'],
            'no_match_reason' => null,
        ]);

        $result = $this->app->make(PlaybookMatchService::class)->match($case);

        $this->assertSame(MatchOutcome::Suggested, $result->outcome);
        $this->assertSame(MatchMethod::Ai, $result->match->method());
        $this->assertSame($playbook->getKey(), $result->playbook?->getKey());
        $this->assertSame(['Termin ist noch offen, kein akuter Ausfall'], $result->deviations);
        $this->assertNotNull($result->match->getAttribute('ai_run_id'));
    }

    public function test_ai_declining_a_match_is_recorded_as_no_match(): void
    {
        config()->set('hub.playbooks.match.high_threshold', 95);
        config()->set('hub.playbooks.match.low_threshold', 10);
        config()->set('hub.playbooks.flags.ai', true);
        config()->set('hub.mail.flags.ai', true);
        config()->set('hub.mail.providers.ai', 'fake');

        $mailbox = $this->createMailbox();
        $case = $this->createCase($mailbox, ['title' => 'Heizung wird bald ausgetauscht, Frage zum Termin', 'case_type' => 'schaden']);
        $this->activePlaybook($case);

        /** @var FakeAiProvider $fake */
        $fake = $this->app->make(FakeAiProvider::class);
        $fake->setDefault('playbook_match', [
            'best_match' => null,
            'deviations' => [],
            'no_match_reason' => 'Passt inhaltlich nicht.',
        ]);

        $result = $this->app->make(PlaybookMatchService::class)->match($case);

        $this->assertSame(MatchOutcome::NoMatch, $result->outcome);
        $this->assertSame(MatchMethod::Ai, $result->match->method());
        $this->assertNull($result->playbook);
    }
}
