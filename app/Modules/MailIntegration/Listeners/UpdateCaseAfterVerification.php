<?php

declare(strict_types=1);

namespace App\Modules\MailIntegration\Listeners;

use App\Modules\Actions\Enums\ActionStatus;
use App\Modules\Actions\Enums\VerificationStatus;
use App\Modules\Actions\Events\ExecutionVerified;
use App\Modules\Actions\Models\Execution;
use App\Modules\Cases\Enums\CommunicationStatus;
use App\Modules\Cases\Enums\TaskStatus;
use App\Modules\Cases\Exceptions\InvalidTransitionException;
use App\Modules\Cases\Models\MailCase;
use App\Modules\Cases\Models\Task;
use App\Modules\Cases\Services\CaseService;
use App\Modules\Cases\Services\CaseStatusLogger;
use App\Modules\MailIntegration\Services\ReplyProposalService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Actions ExecutionVerified: schreibt den Aufgabenstatus fort (api_verified nach Nachlesen ergibt done_verified),
 * hebt den Geschäftsstatus der Teilanliegen bei vollständig verifiziertem Plan an und erzeugt einen
 * Kommunikationsvorschlag: nächster Schritt "Kunde informieren", Kommunikationsstatus Zwischenstand fällig, lokaler
 * Antwortentwurf, der nur verifizierte Änderungen nennt. Teilerfolge bleiben als solche sichtbar.
 */
final class UpdateCaseAfterVerification
{
    public function __construct(
        private readonly CaseService $cases,
        private readonly CaseStatusLogger $log,
        private readonly ReplyProposalService $proposals,
    ) {}

    public function handle(ExecutionVerified $event): void
    {
        $execution = Execution::query()->with(['version.plan'])->find($event->executionId);

        if (! $execution instanceof Execution) {
            return;
        }

        $plan = $execution->version?->plan;
        $case = $plan !== null ? MailCase::query()->withoutGlobalScopes()->find($plan->getAttribute('case_id')) : null;

        if ($plan === null || ! $case instanceof MailCase) {
            return;
        }

        $this->updateTask($execution, $event->status);

        $plan = $plan->fresh();
        $planVerified = $plan !== null && $plan->status === ActionStatus::Verified;

        if ($planVerified) {
            foreach ($case->items()->get() as $item) {
                try {
                    $current = $item->status_business instanceof ActionStatus ? $item->status_business : ActionStatus::from((string) $item->status_business);

                    if ($current !== ActionStatus::Verified && $current->canTransitionTo(ActionStatus::Verified)) {
                        $this->cases->transitionBusiness($item, ActionStatus::Verified, null, 'Aktionsplan #'.$plan->getKey().' vollständig verifiziert.', 'system');
                    }
                } catch (Throwable $e) {
                    Log::info('MailIntegration: Geschäftsstatus des Teilanliegens nicht geändert.', ['item_id' => $item->getKey(), 'reason' => $e->getMessage()]);
                }
            }
        }

        $summary = $planVerified ? 'Alle Schritte des Plans #'.$plan->getKey().' verifiziert.' : 'Schritt '.($event->stepIndex + 1).' des Plans #'.$plan->getKey().' verifiziert, weitere Schritte offen (Teilerfolg).';
        $this->log->log($case, 'communication_proposal', null, 'inform_customer', $summary.' Vorschlag: Kunde über die verifizierten Änderungen informieren.', $event->verifiedBy, null, 'system', ['plan_id' => $plan->getKey(), 'step_index' => $event->stepIndex, 'verification' => $event->status->value]);

        if (trim((string) $case->getAttribute('next_step')) === '' || str_starts_with((string) $case->getAttribute('next_step'), 'Änderung')) {
            $case->forceFill(['next_step' => 'Kunde über verifizierte Änderungen informieren'])->save();
        }

        $communication = $this->cases->communicationStatus($case->refresh());

        if (! in_array($communication, [CommunicationStatus::ReplyNeeded, CommunicationStatus::UpdateRequired, CommunicationStatus::DraftExists, CommunicationStatus::InReview], true)) {
            try {
                $this->cases->transitionCommunication($case, CommunicationStatus::UpdateRequired, null, 'Verifizierte Änderung, Kunde ist zu informieren.', 'system');
            } catch (InvalidTransitionException $e) {
                Log::info('MailIntegration: Kommunikationsstatus unverändert.', ['case_id' => $case->getKey(), 'reason' => $e->getMessage()]);
            }
        }

        $this->proposals->proposeForCase($case->refresh());
    }

    private function updateTask(Execution $execution, VerificationStatus $status): void
    {
        $task = $execution->getAttribute('task_id') !== null ? Task::query()->find($execution->getAttribute('task_id')) : null;

        if (! $task instanceof Task) {
            return;
        }

        $current = TaskStatus::tryFrom((string) $task->getAttribute('status'));

        if ($current === null || in_array($current, [TaskStatus::DoneVerified, TaskStatus::Cancelled], true)) {
            return;
        }

        if ($status === VerificationStatus::ApiVerified) {
            $task->forceFill(['status' => TaskStatus::DoneVerified->value])->save();
        }
    }
}
