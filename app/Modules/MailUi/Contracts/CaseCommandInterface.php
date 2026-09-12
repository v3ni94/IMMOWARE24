<?php

declare(strict_types=1);

namespace App\Modules\MailUi\Contracts;

use App\Modules\Cases\Models\MailCase;
use App\Modules\MailUi\DTO\WorkflowResult;
use App\Modules\Security\Models\User;

/**
 * Dünne Schnittstelle zum Modul Cases (CaseService, CaseStateMachine, LockService): Zuweisung, Kategorie,
 * Statuswechsel, interne Aufgabe, interne Notiz. Die Null-Implementierung schreibt die Modelle direkt und
 * protokolliert in mail_case_status_log; Uhren und Eskalationen übernimmt später das Modul Sla.
 */
interface CaseCommandInterface
{
    public function assign(MailCase $case, ?User $assignee, User $actor, ?string $nextStep = null, ?string $dueAt = null): WorkflowResult;

    public function setCategory(MailCase $case, string $caseType, User $actor): WorkflowResult;

    public function setProcessingStatus(MailCase $case, string $status, User $actor, ?string $reason = null): WorkflowResult;

    /**
     * @param  array{title: string, instructions: ?string, assignee_user_id: ?int, due_at: ?string}  $data
     */
    public function createInternalTask(MailCase $case, array $data, User $actor): WorkflowResult;

    public function addInternalNote(MailCase $case, string $note, User $actor): WorkflowResult;

    /**
     * Fehlende Pflichtfelder eines offenen Vorgangs (Verantwortlicher, nächster Schritt, Fälligkeit) als Klartextliste.
     *
     * @return array<int, string>
     */
    public function missingMandatoryFields(MailCase $case): array;
}
