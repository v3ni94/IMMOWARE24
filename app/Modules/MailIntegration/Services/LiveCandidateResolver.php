<?php

declare(strict_types=1);

namespace App\Modules\MailIntegration\Services;

use App\Modules\Cases\DTO\AssignmentCandidate;
use App\Modules\Cases\Models\CaseMessage;
use App\Modules\Cases\Models\MailCase;
use App\Modules\Cases\Services\AssignmentService;
use App\Modules\Gmail\Models\MailMessage;
use App\Modules\MailUi\Contracts\CandidateResolverInterface;
use App\Modules\MailUi\DTO\WorkflowResult;
use App\Modules\MailUi\Services\NullCandidateResolver;
use App\Modules\Security\Models\User;
use InvalidArgumentException;

/**
 * Live-Verdrahtung der Zuordnungskandidaten an das Modul Cases (AssignmentService): Kandidaten aus Kennungen der
 * Ursprungsnachricht (E-Mail-Adresse, Objekt- und Vertragsnummern, Adressen, bestätigte Absenderregeln) plus
 * gespeicherte Vorschläge (zum Beispiel KI) aus mail_assignment_decisions. Bestätigung nur durch einen Menschen,
 * ausschließlich per ID; die Bestätigung legt eine Absenderregel an.
 */
final class LiveCandidateResolver implements CandidateResolverInterface
{
    public function __construct(
        private readonly AssignmentService $assignment,
        private readonly NullCandidateResolver $stored,
    ) {}

    public function candidates(MailCase $case): array
    {
        $result = $this->stored->candidates($case);
        $message = $this->originMessage($case);

        if ($message === null) {
            return $result;
        }

        foreach ($this->assignment->candidates($message) as $candidate) {
            if (! $candidate instanceof AssignmentCandidate) {
                continue;
            }

            $result[] = [
                'type' => $candidate->type,
                'local_id' => $candidate->localId,
                'label' => $candidate->label ?? ucfirst($candidate->type).' #'.$candidate->localId,
                'source' => $candidate->source,
                'reason' => $candidate->reason,
                'confidence' => $candidate->confidence,
            ];
        }

        $seen = [];

        return array_values(array_filter($result, static function (array $row) use (&$seen): bool {
            $key = $row['type'].':'.$row['local_id'].':'.$row['source'];

            if (isset($seen[$key])) {
                return false;
            }

            $seen[$key] = true;

            return true;
        }));
    }

    public function confirm(MailCase $case, string $type, int $localId, User $actor): WorkflowResult
    {
        try {
            $this->assignment->confirm($case, $type, $localId, $actor, $this->originMessage($case));
        } catch (InvalidArgumentException $e) {
            return WorkflowResult::failed($e->getMessage());
        }

        return WorkflowResult::ok('Zuordnung bestätigt (manuell, per ID). Absenderregel angelegt oder bestätigt.');
    }

    private function originMessage(MailCase $case): ?MailMessage
    {
        $query = CaseMessage::query()->where('case_id', $case->getKey());
        $query->orderByRaw("CASE WHEN link_type = 'origin' THEN 0 ELSE 1 END")->orderBy('id');
        $link = $query->first();

        if (! $link instanceof CaseMessage) {
            return null;
        }

        $message = MailMessage::query()->withoutGlobalScopes()->find($link->getAttribute('message_id'));

        return $message instanceof MailMessage ? $message : null;
    }
}
