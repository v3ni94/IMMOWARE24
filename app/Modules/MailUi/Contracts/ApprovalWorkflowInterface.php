<?php

declare(strict_types=1);

namespace App\Modules\MailUi\Contracts;

use App\Modules\Actions\Models\ActionPlan;
use App\Modules\MailUi\DTO\WorkflowResult;
use App\Modules\Security\Models\User;
use Carbon\CarbonImmutable;

/**
 * Dünne Schnittstelle zum Modul Actions (ApprovalService). Freigabe bindet an steps_hash der aktuellen Version und
 * an den Zeitpunkt der Re-Authentifizierung; die Ausführung löst nie die Oberfläche aus.
 */
interface ApprovalWorkflowInterface
{
    public function approve(ActionPlan $plan, User $approver, ?string $comment, CarbonImmutable $reauthConfirmedAt): WorkflowResult;

    public function reject(ActionPlan $plan, User $approver, string $reason): WorkflowResult;
}
