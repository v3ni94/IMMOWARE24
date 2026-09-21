<?php

declare(strict_types=1);

namespace App\Modules\Playbooks\Services;

use App\Modules\Ai\Enums\AiTask;
use App\Modules\Ai\Services\AiSuggestionService;
use App\Modules\Cases\Models\MailCase;
use App\Modules\Playbooks\DTO\MatchResult;
use App\Modules\Playbooks\Enums\MatchMethod;
use App\Modules\Playbooks\Enums\MatchOutcome;
use App\Modules\Playbooks\Models\Playbook;
use App\Modules\Playbooks\Models\PlaybookMatch;
use App\Modules\Security\Models\User;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Collection;

/**
 * Vergleicht einen Vorgang mit den aktiven Prozessvorlagen der eigenen Kategorie. Erst regelbasiert und
 * kostenlos (PlaybookSimilarityScorer); nur bei einem unklaren, aber nicht aussichtslosen Ergebnis wird die KI
 * zur Einschätzung und für mögliche Abweichungen hinzugezogen (hub.playbooks.match.low_threshold bis
 * high_threshold). Jeder Abgleich wird protokolliert (mail_playbook_matches), unabhängig vom Ergebnis.
 */
final class PlaybookMatchService
{
    public function __construct(
        private readonly PlaybookSimilarityScorer $scorer,
        private readonly AiSuggestionService $suggestions,
        private readonly Repository $config,
    ) {}

    public function match(MailCase $case, ?User $actor = null): MatchResult
    {
        $candidates = $this->scorer->candidatesFor($case);

        if ($candidates->isEmpty()) {
            return $this->record($case, null, null, MatchMethod::Rule, MatchOutcome::NoMatch, []);
        }

        $caseKeywords = $this->scorer->keywordsFor($case);
        $scored = $candidates
            ->map(fn (Playbook $playbook): array => ['playbook' => $playbook, 'score' => $this->scorer->score($case, $playbook, $caseKeywords)])
            ->sortByDesc('score')
            ->values();

        $best = $scored->first();
        $highThreshold = (int) $this->config->get('hub.playbooks.match.high_threshold', 80);
        $lowThreshold = (int) $this->config->get('hub.playbooks.match.low_threshold', 35);

        if ((int) $best['score'] >= $highThreshold) {
            return $this->record($case, $best['playbook'], (int) $best['score'], MatchMethod::Rule, MatchOutcome::Suggested, []);
        }

        if ((int) $best['score'] < $lowThreshold) {
            return $this->record($case, null, (int) $best['score'], MatchMethod::Rule, MatchOutcome::NoMatch, []);
        }

        if (! $this->aiEnabled()) {
            return $this->record($case, $best['playbook'], (int) $best['score'], MatchMethod::Rule, MatchOutcome::Suggested, []);
        }

        return $this->matchWithAi($case, $scored, $actor);
    }

    /**
     * @param  Collection<int, array{playbook: Playbook, score: int}>  $scored
     */
    private function matchWithAi(MailCase $case, Collection $scored, ?User $actor): MatchResult
    {
        $maxCandidates = max(1, (int) $this->config->get('hub.playbooks.match.max_candidates', 5));
        $top = $scored->take($maxCandidates);

        $result = $this->suggestions->run(
            AiTask::PlaybookMatch,
            $case,
            null,
            $actor,
            trusted: [
                'case' => [
                    'case_type' => (string) $case->getAttribute('case_type'),
                    'title' => (string) $case->getAttribute('title'),
                ],
                'candidates' => $top->map(fn (array $row): array => [
                    'playbook_id' => (string) $row['playbook']->getKey(),
                    'title' => (string) $row['playbook']->getAttribute('title'),
                    'steps' => (array) $row['playbook']->getAttribute('steps_json'),
                    'rule_based_similarity_percent' => $row['score'],
                ])->values()->all(),
            ],
        );

        $suggestion = $result->suggestions[0] ?? null;
        $payload = is_array($suggestion?->getAttribute('payload_json')) ? $suggestion->getAttribute('payload_json') : null;
        $bestMatch = is_array($payload['best_match'] ?? null) ? $payload['best_match'] : null;
        $aiRunId = $result->run?->getKey();

        if ($bestMatch === null) {
            return $this->record($case, null, null, MatchMethod::Ai, MatchOutcome::NoMatch, (array) ($payload['deviations'] ?? []), $aiRunId);
        }

        $chosen = $top->firstWhere(fn (array $row): bool => (string) $row['playbook']->getKey() === (string) ($bestMatch['playbook_id'] ?? ''));

        if ($chosen === null) {
            // Die KI darf nur aus der übergebenen Liste wählen; ein unbekannter Wert gilt als kein Treffer.
            return $this->record($case, null, null, MatchMethod::Ai, MatchOutcome::NoMatch, (array) ($payload['deviations'] ?? []), $aiRunId);
        }

        return $this->record(
            $case,
            $chosen['playbook'],
            isset($bestMatch['confidence_percent']) ? (int) $bestMatch['confidence_percent'] : null,
            MatchMethod::Ai,
            MatchOutcome::Suggested,
            (array) ($payload['deviations'] ?? []),
            $aiRunId,
        );
    }

    /**
     * @param  array<int, string>  $deviations
     */
    private function record(MailCase $case, ?Playbook $playbook, ?int $score, MatchMethod $method, MatchOutcome $outcome, array $deviations, ?int $aiRunId = null): MatchResult
    {
        $match = PlaybookMatch::query()->create([
            'organization_id' => $case->getAttribute('organization_id'),
            'case_id' => $case->getKey(),
            'playbook_id' => $playbook?->getKey(),
            'similarity_score' => $score,
            'method' => $method->value,
            'outcome' => $outcome->value,
            'ai_run_id' => $aiRunId,
            'deviations_json' => $deviations,
        ]);

        if ($playbook !== null) {
            Playbook::query()->whereKey($playbook->getKey())->increment('times_suggested');
        }

        return new MatchResult($match, $playbook, $outcome, $score, $deviations);
    }

    private function aiEnabled(): bool
    {
        return (bool) $this->config->get('hub.playbooks.flags.ai', false) && $this->suggestions->isEnabled();
    }
}
