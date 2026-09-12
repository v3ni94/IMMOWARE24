<?php

declare(strict_types=1);

namespace App\Modules\MailUi\Contracts;

use App\Modules\Cases\Models\MailCase;
use App\Modules\MailUi\DTO\WorkflowResult;
use App\Modules\Security\Models\User;

/**
 * Dünne Schnittstelle zum Modul Cases (CaseAssignmentService): Kandidaten für Person und Objekt mit Quelle und Grund,
 * Bestätigung durch einen Menschen. KI-Vorschläge sind nur Kandidaten, nie Zuordnung.
 */
interface CandidateResolverInterface
{
    /**
     * @return array<int, array{type: string, local_id: int, label: string, source: string, reason: string, confidence: ?int}>
     */
    public function candidates(MailCase $case): array;

    public function confirm(MailCase $case, string $type, int $localId, User $actor): WorkflowResult;
}
