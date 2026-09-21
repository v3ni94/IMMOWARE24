<?php

declare(strict_types=1);

namespace App\Modules\Playbooks\Services;

use App\Modules\Ai\Enums\AiTask;
use App\Modules\Ai\Services\AiSuggestionService;
use App\Modules\Cases\Models\CaseStatusLog;
use App\Modules\Cases\Models\MailCase;
use App\Modules\Playbooks\Enums\MatchOutcome;
use App\Modules\Playbooks\Enums\PlaybookSource;
use App\Modules\Playbooks\Enums\PlaybookStatus;
use App\Modules\Playbooks\Models\Playbook;
use App\Modules\Playbooks\Models\PlaybookMatch;
use App\Modules\Security\Models\User;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Lernt aus einem abgeschlossenen Vorgang: bestätigt eine bereits genutzte Vorlage (Zähler), schlägt bei
 * vermerkten Abweichungen eine verbesserte Schrittfolge vor oder schlägt bei fehlender oder abgelehnter Vorlage
 * eine neue Prozessvorlage aus dem tatsächlichen Verlauf des Vorgangs vor. Jeder Vorschlag bleibt ein Entwurf
 * (status draft), bis eine Person ihn prüft und aktiviert (PlaybookService::activate()).
 */
final class PlaybookLearningService
{
    public function __construct(
        private readonly AiSuggestionService $suggestions,
        private readonly PlaybookSimilarityScorer $scorer,
        private readonly Repository $config,
    ) {}

    public function learnFromClosedCase(MailCase $case, ?User $actor = null): void
    {
        if (! $this->aiEnabled()) {
            return;
        }

        $latestMatch = PlaybookMatch::query()->allOrganizations()->where('case_id', $case->getKey())->latest('id')->first();

        try {
            if ($latestMatch instanceof PlaybookMatch && $latestMatch->getAttribute('playbook_id') !== null && $latestMatch->outcome() !== MatchOutcome::Rejected) {
                if ($latestMatch->outcome() === MatchOutcome::Adjusted) {
                    $this->proposeRefinedSteps($case, $latestMatch, $actor);
                }

                return;
            }

            $this->proposeNewPlaybook($case, $actor);
        } catch (Throwable $e) {
            Log::warning('Playbooks: Lernen aus abgeschlossenem Vorgang fehlgeschlagen', ['case_id' => $case->getKey(), 'class' => $e::class]);
        }
    }

    private function proposeNewPlaybook(MailCase $case, ?User $actor): void
    {
        $caseType = (string) $case->getAttribute('case_type');

        $alreadyDrafted = Playbook::query()
            ->where('case_type', $caseType)
            ->where('source', PlaybookSource::AiDrafted->value)
            ->where('status', PlaybookStatus::Draft->value)
            ->exists();

        if ($alreadyDrafted) {
            // Es liegt bereits ein ungeprüfter Entwurf für diese Kategorie vor; kein weiterer KI-Aufruf, bis
            // eine Person entschieden hat (vermeidet eine wachsende Zahl unbeachteter Entwürfe).
            return;
        }

        $result = $this->suggestions->run(
            AiTask::PlaybookDraftSteps,
            $case,
            null,
            $actor,
            trusted: [
                'case_type' => $caseType,
                'title' => (string) $case->getAttribute('title'),
                'history' => $this->historyFor($case),
            ],
        );

        $suggestion = $result->suggestions[0] ?? null;
        $payload = is_array($suggestion?->getAttribute('payload_json')) ? $suggestion->getAttribute('payload_json') : null;

        if ($payload === null || ! isset($payload['steps'])) {
            return;
        }

        Playbook::query()->create([
            'organization_id' => $case->getAttribute('organization_id'),
            'case_type' => $caseType,
            'title' => (string) ($payload['title'] ?? $case->getAttribute('title')),
            'version' => 1,
            'status' => PlaybookStatus::Draft->value,
            'source' => PlaybookSource::AiDrafted->value,
            'match_signature_json' => ['keywords' => $this->scorer->keywordsFor($case)],
            'steps_json' => $payload['steps'],
            'created_from_case_id' => $case->getKey(),
        ]);
    }

    private function proposeRefinedSteps(MailCase $case, PlaybookMatch $match, ?User $actor): void
    {
        $playbook = $match->playbook;

        if (! $playbook instanceof Playbook) {
            return;
        }

        $result = $this->suggestions->run(
            AiTask::PlaybookDraftSteps,
            $case,
            null,
            $actor,
            trusted: [
                'case_type' => (string) $playbook->getAttribute('case_type'),
                'title' => (string) $case->getAttribute('title'),
                'existing_steps' => (array) $playbook->getAttribute('steps_json'),
                'deviations_noted_by_the_handler' => (array) $match->getAttribute('deviations_json'),
                'history' => $this->historyFor($case),
            ],
        );

        $suggestion = $result->suggestions[0] ?? null;
        $payload = is_array($suggestion?->getAttribute('payload_json')) ? $suggestion->getAttribute('payload_json') : null;

        if ($payload === null || ! isset($payload['steps'])) {
            return;
        }

        Playbook::query()->create([
            'organization_id' => $playbook->getAttribute('organization_id'),
            'case_type' => $playbook->getAttribute('case_type'),
            'title' => (string) ($payload['title'] ?? $playbook->getAttribute('title')),
            'version' => (int) $playbook->getAttribute('version') + 1,
            'status' => PlaybookStatus::Draft->value,
            'source' => PlaybookSource::AiDrafted->value,
            'match_signature_json' => $playbook->getAttribute('match_signature_json'),
            'steps_json' => $payload['steps'],
            'created_from_case_id' => $case->getKey(),
            'previous_version_id' => $playbook->getKey(),
        ]);
    }

    /**
     * Tatsächlicher Verlauf des Vorgangs (Aufgaben und Statuswechsel der Bearbeitungsdimension) als vertrauenswürdige
     * Grundlage für den Entwurf. Kein Fremdinhalt (E-Mailtext) enthalten.
     *
     * @return array<string, mixed>
     */
    private function historyFor(MailCase $case): array
    {
        $taskQuery = $case->tasks();
        $taskQuery->orderBy('created_at');
        $tasks = [];

        foreach ($taskQuery->get(['task_type', 'title', 'status', 'created_at']) as $task) {
            $tasks[] = [
                'task_type' => (string) $task->getAttribute('task_type'),
                'title' => (string) $task->getAttribute('title'),
                'status' => (string) $task->getAttribute('status'),
            ];
        }

        $statusQuery = CaseStatusLog::query()->where('case_id', $case->getKey());
        $statusQuery->where('dimension', 'processing');
        $statusQuery->orderBy('changed_at');
        $statusChanges = [];

        foreach ($statusQuery->get(['from_status', 'to_status', 'reason']) as $log) {
            $statusChanges[] = [
                'from' => $log->getAttribute('from_status'),
                'to' => $log->getAttribute('to_status'),
                'reason' => $log->getAttribute('reason'),
            ];
        }

        return ['tasks' => $tasks, 'status_changes' => $statusChanges];
    }

    private function aiEnabled(): bool
    {
        return (bool) $this->config->get('hub.playbooks.flags.ai', false) && $this->suggestions->isEnabled();
    }
}
