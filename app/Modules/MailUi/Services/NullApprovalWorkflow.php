<?php

declare(strict_types=1);

namespace App\Modules\MailUi\Services;

use App\Modules\Actions\Enums\ActionStatus;
use App\Modules\Actions\Models\ActionPlan;
use App\Modules\Actions\Models\Approval;
use App\Modules\Mail\Services\MailAccess;
use App\Modules\MailUi\Contracts\ApprovalWorkflowInterface;
use App\Modules\MailUi\DTO\WorkflowResult;
use App\Modules\Security\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Null-Implementierung bis zur Verdrahtung des Moduls Actions: erfasst die Freigabe oder Ablehnung als Zeile in
 * mail_approvals (gebunden an steps_hash und Re-Auth-Zeitpunkt) und setzt den Planstatus auf approved erst,
 * wenn Vier-Augen erfüllt sind. Ausführung und Verifikation finden hier nie statt.
 */
final class NullApprovalWorkflow implements ApprovalWorkflowInterface
{
    public function __construct(private readonly MailAccess $access) {}

    public function approve(ActionPlan $plan, User $approver, ?string $comment, CarbonImmutable $reauthConfirmedAt): WorkflowResult
    {
        $version = $plan->currentVersion;

        if ($version === null) {
            return WorkflowResult::failed('Der Aktionsplan hat keine aktuelle Version.');
        }

        if ($plan->status !== ActionStatus::ApprovalRequired) {
            return WorkflowResult::failed('Der Aktionsplan wartet nicht auf Freigabe (Status '.$plan->status->label().').');
        }

        $exists = Approval::query()
            ->where('action_plan_version_id', $version->getKey())
            ->where('approver_user_id', $approver->getKey())
            ->exists();

        if ($exists) {
            return WorkflowResult::failed('Sie haben zu dieser Version bereits entschieden.');
        }

        return DB::transaction(function () use ($plan, $version, $approver, $comment, $reauthConfirmedAt): WorkflowResult {
            Approval::query()->create([
                'action_plan_version_id' => $version->getKey(),
                'approver_user_id' => $approver->getKey(),
                'decision' => 'approved',
                'steps_hash' => (string) $version->getAttribute('steps_hash'),
                'diff_hash' => $version->getAttribute('diff_hash'),
                'comment' => $comment,
                'reauth_confirmed_at' => $reauthConfirmedAt,
            ]);

            $required = max(1, (int) $version->getAttribute('required_approvals'));
            $count = Approval::query()->where('action_plan_version_id', $version->getKey())->where('decision', 'approved')->count();

            if ($count >= $required && $this->access->fourEyesSatisfied($plan)) {
                $plan->forceFill(['status' => ActionStatus::Approved])->save();

                return WorkflowResult::ok('Freigabe erfasst. Der Plan ist freigegeben; Ausführung und Verifikation erfolgen durch das Modul Aktionen, kein Ergebnis ist damit erreicht.');
            }

            return WorkflowResult::ok('Freigabe erfasst. Es fehlen weitere Freigaben ('.$count.' von '.$required.').');
        });
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

        return DB::transaction(function () use ($plan, $version, $approver, $reason): WorkflowResult {
            Approval::query()->updateOrCreate(
                ['action_plan_version_id' => $version->getKey(), 'approver_user_id' => $approver->getKey()],
                ['decision' => 'rejected', 'steps_hash' => (string) $version->getAttribute('steps_hash'), 'comment' => $reason],
            );
            $plan->forceFill(['status' => ActionStatus::ManualReview])->save();

            return WorkflowResult::ok('Ablehnung mit Begründung erfasst. Der Plan geht in manuelle Prüfung.');
        });
    }
}
