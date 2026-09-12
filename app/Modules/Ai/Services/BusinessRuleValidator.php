<?php

declare(strict_types=1);

namespace App\Modules\Ai\Services;

use App\Modules\Ai\Enums\AiTask;
use App\Modules\Ai\Schemas\AiSchemas;
use App\Modules\Cases\Enums\Priority;

/**
 * Fachliche Prüfung nach der Schemaprüfung. Die KI darf eine regelbasierte Priorität nie senken (P0 bleibt P0),
 * Kandidaten müssen aus der übergebenen Liste stammen, Aktionstypen nur aus der Allowlist, Antwortentwürfe dürfen
 * Erledigungsbegriffe nur bei verifizierten Fakten enthalten. Verstöße liefern Fehler, die Antwort wird verworfen
 * oder, wo eindeutig, korrigiert (Priorität wird auf die regelbasierte angehoben).
 */
final class BusinessRuleValidator
{
    public const array COMPLETION_TERMS = ['beauftragt', 'geändert', 'bezahlt', 'erledigt', 'überwiesen', 'abgeschlossen'];

    public function __construct(private readonly AiSchemas $schemas) {}

    /**
     * @param  array<string, mixed>  $response  schema-gültige Antwort ohne meta
     * @param  array<string, mixed>  $context  Regelkontext: rule_priority, candidate_ids, verified_facts, allowed_action_types
     * @return array{response: array<string, mixed>, errors: array<int, string>, adjustments: array<int, string>}
     */
    public function apply(AiTask $task, array $response, array $context): array
    {
        return match ($task) {
            AiTask::Classify => $this->classify($response, $context),
            AiTask::MatchCandidates => $this->matchCandidates($response, $context),
            AiTask::NextSteps => $this->nextSteps($response, $context),
            AiTask::DraftReply => $this->draftReply($response, $context),
            default => ['response' => $response, 'errors' => [], 'adjustments' => []],
        };
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  array<string, mixed>  $context
     * @return array{response: array<string, mixed>, errors: array<int, string>, adjustments: array<int, string>}
     */
    private function classify(array $response, array $context): array
    {
        $adjustments = [];
        $rule = Priority::tryFrom((string) ($context['rule_priority'] ?? ''));
        $proposed = Priority::tryFrom((string) ($response['priority'] ?? ''));

        if ($rule !== null && ($proposed === null || $rule->isHigherThan($proposed))) {
            $response['priority'] = $rule->value;
            $response['priority_reason'] = sprintf('Regelbasiert %s (KI-Vorschlag %s nicht übernommen, Herabstufung unzulässig). %s', $rule->short(), $proposed?->short() ?? 'ohne', (string) ($response['priority_reason'] ?? ''));
            $adjustments[] = sprintf('Priorität auf regelbasiertes %s angehoben.', $rule->short());
        }

        return ['response' => $response, 'errors' => [], 'adjustments' => $adjustments];
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  array<string, mixed>  $context
     * @return array{response: array<string, mixed>, errors: array<int, string>, adjustments: array<int, string>}
     */
    private function matchCandidates(array $response, array $context): array
    {
        $allowed = array_map('strval', (array) ($context['candidate_ids'] ?? []));
        $errors = [];

        foreach ((array) ($response['matches'] ?? []) as $index => $match) {
            $id = is_array($match) ? (string) ($match['candidate_id'] ?? '') : '';

            if (! in_array($id, $allowed, true)) {
                $errors[] = sprintf('matches[%d]: Kandidat %s stammt nicht aus der Eingabeliste.', (int) $index, $id === '' ? '(leer)' : $id);
            }
        }

        return ['response' => $response, 'errors' => $errors, 'adjustments' => []];
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  array<string, mixed>  $context
     * @return array{response: array<string, mixed>, errors: array<int, string>, adjustments: array<int, string>}
     */
    private function nextSteps(array $response, array $context): array
    {
        $allowed = array_map('strval', (array) ($context['allowed_action_types'] ?? $this->schemas->allowedActionTypes()));
        $errors = [];

        foreach ((array) ($response['steps'] ?? []) as $index => $step) {
            $type = is_array($step) ? (string) ($step['action_type'] ?? '') : '';

            if (! in_array($type, $allowed, true)) {
                $errors[] = sprintf('steps[%d]: Aktionstyp %s nicht erlaubt.', (int) $index, $type === '' ? '(leer)' : $type);
            }
        }

        return ['response' => $response, 'errors' => $errors, 'adjustments' => []];
    }

    /**
     * Erledigungsbegriffe nur, wenn im Faktenblock ein als verifiziert markierter Fakt vorliegt. Sonst wird der Entwurf
     * abgelehnt, damit nie ein unbestätigtes Ergebnis nach außen formuliert wird.
     *
     * @param  array<string, mixed>  $response
     * @param  array<string, mixed>  $context
     * @return array{response: array<string, mixed>, errors: array<int, string>, adjustments: array<int, string>}
     */
    private function draftReply(array $response, array $context): array
    {
        $errors = [];
        $verified = [];

        foreach ((array) ($context['verified_facts'] ?? []) as $fact) {
            if (is_array($fact) && (bool) ($fact['verified'] ?? false)) {
                $verified[] = mb_strtolower((string) ($fact['text'] ?? ''));
            }
        }

        $body = mb_strtolower((string) ($response['body'] ?? '').' '.(string) ($response['subject'] ?? ''));

        foreach (self::COMPLETION_TERMS as $term) {
            if (! str_contains($body, $term)) {
                continue;
            }

            $covered = false;

            foreach ($verified as $fact) {
                if (str_contains($fact, $term)) {
                    $covered = true;
                    break;
                }
            }

            if (! $covered) {
                $errors[] = sprintf('Entwurf enthält "%s" ohne verifizierten Fakt.', $term);
            }
        }

        if ($verified === [] && (string) ($response['draft_kind'] ?? '') === 'final') {
            $errors[] = 'Endgültiger Entwurf ohne verifizierte Fakten unzulässig (nur question oder interim).';
        }

        return ['response' => $response, 'errors' => $errors, 'adjustments' => []];
    }
}
