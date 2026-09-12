<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Modules\Ai\Enums\AiTask;
use App\Modules\Ai\Schemas\AiSchemas;
use App\Modules\Ai\Services\BusinessRuleValidator;
use Illuminate\Config\Repository;
use PHPUnit\Framework\TestCase;

final class BusinessRuleValidatorTest extends TestCase
{
    private BusinessRuleValidator $rules;

    protected function setUp(): void
    {
        parent::setUp();
        $config = new Repository(['hub' => ['ai' => ['allowed_action_types' => ['reply_question', 'no_action'], 'case_types' => ['sonstiges']]]]);
        $this->rules = new BusinessRuleValidator(new AiSchemas($config));
    }

    public function test_rule_based_p0_is_never_downgraded(): void
    {
        $result = $this->rules->apply(AiTask::Classify, ['priority' => 'p3', 'priority_reason' => 'Routine'], ['rule_priority' => 'p0']);

        $this->assertSame('p0', $result['response']['priority']);
        $this->assertStringContainsString('Herabstufung unzulässig', $result['response']['priority_reason']);
        $this->assertSame([], $result['errors']);
        $this->assertCount(1, $result['adjustments']);
    }

    public function test_ai_may_raise_priority_above_rule(): void
    {
        $result = $this->rules->apply(AiTask::Classify, ['priority' => 'p1', 'priority_reason' => 'Dringend'], ['rule_priority' => 'p2']);

        $this->assertSame('p1', $result['response']['priority']);
        $this->assertSame([], $result['adjustments']);
    }

    public function test_foreign_candidates_are_rejected(): void
    {
        $result = $this->rules->apply(AiTask::MatchCandidates, ['matches' => [['candidate_id' => 'c-1'], ['candidate_id' => 'erfunden']]], ['candidate_ids' => ['c-1', 'c-2']]);

        $this->assertSame(['matches[1]: Kandidat erfunden stammt nicht aus der Eingabeliste.'], $result['errors']);
    }

    public function test_action_types_outside_allowlist_are_rejected(): void
    {
        $result = $this->rules->apply(AiTask::NextSteps, ['steps' => [['action_type' => 'no_action'], ['action_type' => 'grant_admin_rights']]], []);

        $this->assertSame(['steps[1]: Aktionstyp grant_admin_rights nicht erlaubt.'], $result['errors']);
    }

    public function test_draft_reply_completion_terms_require_verified_facts(): void
    {
        $unverified = $this->rules->apply(AiTask::DraftReply, ['draft_kind' => 'final', 'subject' => 'Re', 'body' => 'Der Handwerker wurde beauftragt.'], ['verified_facts' => [['text' => 'Handwerker beauftragt', 'verified' => false]]]);
        $this->assertContains('Entwurf enthält "beauftragt" ohne verifizierten Fakt.', $unverified['errors']);
        $this->assertContains('Endgültiger Entwurf ohne verifizierte Fakten unzulässig (nur question oder interim).', $unverified['errors']);

        $verified = $this->rules->apply(AiTask::DraftReply, ['draft_kind' => 'final', 'subject' => 'Re', 'body' => 'Der Handwerker wurde beauftragt.'], ['verified_facts' => [['text' => 'Handwerker Firma X am 10.09.2026 beauftragt', 'verified' => true]]]);
        $this->assertSame([], $verified['errors']);

        $interim = $this->rules->apply(AiTask::DraftReply, ['draft_kind' => 'interim', 'subject' => 'Re', 'body' => 'Wir prüfen den Sachverhalt und melden uns.'], []);
        $this->assertSame([], $interim['errors']);
    }
}
