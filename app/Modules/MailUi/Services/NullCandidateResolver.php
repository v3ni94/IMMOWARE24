<?php

declare(strict_types=1);

namespace App\Modules\MailUi\Services;

use App\Modules\Cases\Models\AssignmentDecision;
use App\Modules\Cases\Models\MailCase;
use App\Modules\MailUi\Contracts\CandidateResolverInterface;
use App\Modules\MailUi\DTO\WorkflowResult;
use App\Modules\Security\Models\User;

/**
 * Null-Implementierung bis zur Verdrahtung des Moduls Cases: liefert nur bereits gespeicherte Vorschläge aus
 * mail_assignment_decisions (proposed_value_json) und bestätigt eine Zuordnung durch Schreiben der Entscheidung
 * (decision_basis manual) und der ID am Vorgang. Namen werden nie zur Zuordnung verwendet.
 */
final class NullCandidateResolver implements CandidateResolverInterface
{
    private const array TYPES = ['contact' => 'primary_contact_id', 'property' => 'property_id', 'unit' => 'unit_id', 'contract' => 'contract_id'];

    public function candidates(MailCase $case): array
    {
        $result = [];

        $query = AssignmentDecision::query()->where('case_id', $case->getKey());
        $query->whereIn('decision_type', array_keys(self::TYPES))->whereNull('decided_by')->orderByDesc('decided_at')->limit(50);
        $decisions = $query->get();

        foreach ($decisions as $decision) {
            $proposed = $decision->getAttribute('proposed_value_json');

            if (! is_array($proposed)) {
                continue;
            }

            foreach ($proposed as $candidate) {
                if (! is_array($candidate) || ! isset($candidate['local_id'])) {
                    continue;
                }

                $result[] = [
                    'type' => (string) $decision->getAttribute('decision_type'),
                    'local_id' => (int) $candidate['local_id'],
                    'label' => (string) ($candidate['label'] ?? 'Kandidat #'.$candidate['local_id']),
                    'source' => (string) ($candidate['source'] ?? $decision->getAttribute('decision_basis')),
                    'reason' => (string) ($candidate['reason'] ?? 'ohne Begründung'),
                    'confidence' => isset($candidate['confidence']) ? (int) $candidate['confidence'] : null,
                ];
            }
        }

        return $result;
    }

    public function confirm(MailCase $case, string $type, int $localId, User $actor): WorkflowResult
    {
        $column = self::TYPES[$type] ?? null;

        if ($column === null) {
            return WorkflowResult::failed('Unbekannter Kandidatentyp.');
        }

        $case->forceFill([$column => $localId])->save();

        AssignmentDecision::query()->create([
            'case_id' => $case->getKey(),
            'decision_type' => $type,
            'chosen_value' => (string) $localId,
            'chosen_local_id' => $localId,
            'decided_by' => $actor->getKey(),
            'decision_basis' => 'manual',
            'decided_at' => now(),
        ]);

        return WorkflowResult::ok('Zuordnung bestätigt (manuell, per ID).');
    }
}
