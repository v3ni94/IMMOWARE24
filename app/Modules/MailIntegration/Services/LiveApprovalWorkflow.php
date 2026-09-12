<?php

declare(strict_types=1);

namespace App\Modules\MailIntegration\Services;

use App\Modules\Actions\Enums\ActionStatus;
use App\Modules\Actions\Exceptions\ActionPolicyException;
use App\Modules\Actions\Models\ActionPlan;
use App\Modules\Actions\Services\ActionPolicy;
use App\Modules\Actions\Services\ApprovalService;
use App\Modules\MailUi\Contracts\ApprovalWorkflowInterface;
use App\Modules\MailUi\DTO\WorkflowResult;
use App\Modules\Security\Models\User;
use Carbon\CarbonImmutable;

/**
 * Live-Verdrahtung des Freigabecenters an das Modul Actions. Die Freigabe bindet an steps_hash und diff_hash der
 * aktuellen Version und an den Re-Auth-Zeitpunkt; Vier-Augen, Identitätsprüfung bei Bankdaten und Ablauf prüft
 * ActionPolicy. Bei vollständiger Freigabe plant ApprovalService die Ausführung als Job ein; die Oberfläche meldet
 * nie ein Ergebnis, erst die Verifikation zählt.
 */
final class LiveApprovalWorkflow implements ApprovalWorkflowInterface
{
    public function __construct(
        private readonly ApprovalService $approvals,
        private readonly ActionPolicy $policy,
    ) {}

    public function approve(ActionPlan $plan, User $approver, ?string $comment, CarbonImmutable $reauthConfirmedAt): WorkflowResult
    {
        $version = $plan->currentVersion;

        if ($version === null) {
            return WorkflowResult::failed('Der Aktionsplan hat keine aktuelle Version.');
        }

        if ($plan->status !== ActionStatus::ApprovalRequired) {
            return WorkflowResult::failed('Der Aktionsplan wartet nicht auf Freigabe (Status '.$plan->status->label().').');
        }

        try {
            $this->approvals->approve($version, $approver, $comment, $reauthConfirmedAt);
        } catch (ActionPolicyException $e) {
            return WorkflowResult::failed('Freigabe abgelehnt ('.$e->code_key.'): '.$e->getMessage());
        }

        $fresh = $plan->fresh(['currentVersion']);
        $status = $fresh !== null ? $fresh->status : $plan->status;
        $required = max(1, (int) $version->getAttribute('required_approvals'));
        $count = count($this->policy->validApprovals($version->refresh()));

        return match ($status) {
            ActionStatus::Approved => WorkflowResult::ok('Freigabe erfasst, der Plan ist vollständig freigegeben. Die Ausführung ist eingeplant, kein Ergebnis ist damit erreicht; erst die Verifikation im Zielsystem zählt.'),
            ActionStatus::Scheduled => WorkflowResult::ok('Freigabe erfasst, der Plan ist eingeplant und wird zum Wirksamkeitsdatum erneut validiert und ausgeführt; kein Ergebnis ist damit erreicht.'),
            default => WorkflowResult::ok('Freigabe erfasst. Es fehlen weitere Freigaben ('.$count.' von '.$required.'); kein Ergebnis ist damit erreicht.'),
        };
    }

    public function reject(ActionPlan $plan, User $approver, string $reason): WorkflowResult
    {
        $version = $plan->currentVersion;

        if ($version === null) {
            return WorkflowResult::failed('Der Aktionsplan hat keine aktuelle Version.');
        }

        if ($plan->status !== ActionStatus::ApprovalRequired) {
            return WorkflowResult::failed('Der Aktionsplan wartet nicht auf Freigabe (Status '.$plan->status->label().').');
        }

        $this->approvals->reject($version, $approver, $reason);

        return WorkflowResult::ok('Ablehnung mit Begründung erfasst. Der Plan geht in manuelle Prüfung.');
    }
}
