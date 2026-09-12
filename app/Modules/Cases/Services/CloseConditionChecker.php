<?php

declare(strict_types=1);

namespace App\Modules\Cases\Services;

use App\Modules\Actions\Enums\ActionStatus;
use App\Modules\Actions\Models\ActionPlan;
use App\Modules\Actions\Models\Execution;
use App\Modules\Cases\Enums\CaseStatus;
use App\Modules\Cases\Enums\CommunicationStatus;
use App\Modules\Cases\Enums\TaskStatus;
use App\Modules\Cases\Models\CaseItem;
use App\Modules\Cases\Models\MailCase;
use App\Modules\Cases\Models\Task;
use App\Modules\Cases\StateMachines\BusinessStateMachine;
use App\Modules\Cases\StateMachines\CommunicationStateMachine;
use Illuminate\Contracts\Config\Repository;

/**
 * Abschlussbedingungen je Vorgangstyp (hub.cases.close_conditions): Pflichtaufgaben erledigt, Aktionen verifiziert,
 * keine offenen Teilfehler, Kommunikation abgeschlossen oder begründet entbehrlich. Eine reine Auskunft ohne
 * Schreibaktion ist abschließbar. Liefert die offenen Bedingungen im Klartext.
 *
 * Das Geschäftsergebnis wird aus zwei Quellen bestimmt: status_business des Teilanliegens (gespiegelt) und dem
 * tatsächlichen Stand der Aktionspläne (mail_action_plans) samt Ausführungen (mail_executions) des Teilanliegens
 * beziehungsweise des Vorgangs. Die strengste Aussage gewinnt: ein fehlgeschlagener oder unklarer Plan blockiert
 * den Abschluss auch dann, wenn die Spiegelung in status_business ausgeblieben ist.
 */
final class CloseConditionChecker
{
    /**
     * Strenge der Geschäftszustände für die Wahl der strengsten Quelle (höher = strenger).
     *
     * @var array<string, int>
     */
    private const array SEVERITY = [
        'failed' => 100,
        'result_unclear' => 90,
        'manual_review' => 80,
        'executed' => 60,
        'executing' => 55,
        'scheduled' => 50,
        'approved' => 45,
        'approval_required' => 40,
        'validated' => 30,
        'proposed' => 10,
        'verified' => 0,
    ];

    /**
     * Abbildung technischer Ausführungsstatus (mail_executions.status) auf das Geschäftsergebnis.
     *
     * @var array<string, ActionStatus>
     */
    private const array EXECUTION_STATUS = [
        'pending' => ActionStatus::Scheduled,
        'running' => ActionStatus::Executing,
        'http_ok_unverified' => ActionStatus::Executed,
        'verified' => ActionStatus::Verified,
        'failed' => ActionStatus::Failed,
        'blocked_flag' => ActionStatus::ManualReview,
        'blocked_capability' => ActionStatus::ManualReview,
        'blocked_permission' => ActionStatus::ManualReview,
    ];

    public function __construct(
        private readonly Repository $config,
        private readonly BusinessStateMachine $business,
        private readonly CommunicationStateMachine $communication,
    ) {}

    public function groupFor(string $caseType): string
    {
        $groups = (array) $this->config->get('hub.cases.case_type_groups', []);

        return (string) ($groups[$caseType] ?? 'other');
    }

    /**
     * @return array<int, string>
     */
    public function unmetForItem(CaseItem $item): array
    {
        $group = $this->groupFor((string) $item->item_type);
        $rules = (array) $this->config->get('hub.cases.close_conditions.'.$group, []);
        $unmet = [];
        $taskTypes = (array) $this->config->get('hub.cases.task_types', []);

        $tasks = Task::query()->allOrganizations()
            ->where(static function ($q) use ($item): void {
                $q->where('case_item_id', $item->getKey())
                    ->orWhere(static fn ($q2) => $q2->where('case_id', $item->case_id)->whereNull('case_item_id'));
            })
            ->get();

        foreach ((array) ($rules['required_task_types'] ?? []) as $type) {
            $done = $tasks->first(static fn (Task $t): bool => (string) $t->task_type === (string) $type && TaskStatus::from((string) $t->status)->isDone());

            if ($done === null) {
                $unmet[] = sprintf('Pflichtaufgabe "%s" fehlt oder ist nicht erledigt.', (string) ($taskTypes[$type] ?? $type));
            }
        }

        foreach ($tasks as $task) {
            if (TaskStatus::from((string) $task->status)->isOpen()) {
                $unmet[] = sprintf('Aufgabe "%s" ist noch offen.', (string) $task->title);
            }
        }

        $businessStatus = $this->effectiveBusinessStatus($item);

        if ((bool) ($rules['requires_verified_business_result'] ?? false) && ! $businessStatus->isBusinessComplete()) {
            $unmet[] = sprintf('Geschäftsergebnis ist "%s", verlangt ist "Verifiziert".', $businessStatus->label());
        } elseif ($this->business->hasOpenPartialFailure($businessStatus)) {
            $unmet[] = sprintf('Offener Teilfehler: Geschäftsergebnis "%s".', $businessStatus->label());
        } elseif ($this->business->isInFlight($businessStatus)) {
            $unmet[] = sprintf('Aktion begonnen, aber nicht verifiziert (Status "%s").', $businessStatus->label());
        }

        if ((bool) ($rules['requires_communication'] ?? true)) {
            $communicationStatus = $item->status_communication instanceof CommunicationStatus ? $item->status_communication : CommunicationStatus::from((string) $item->status_communication);

            if (! $this->communication->isComplete($communicationStatus)) {
                $unmet[] = sprintf('Kommunikation nicht abgeschlossen (Status "%s").', $communicationStatus->label());
            } elseif ($communicationStatus === CommunicationStatus::NoReplyNeeded) {
                $case = $item->loadMissing('case')->case;
                $reason = $case instanceof MailCase ? trim((string) $case->communication_waived_reason) : '';

                if ($reason === '') {
                    $unmet[] = 'Kommunikation als entbehrlich markiert, aber ohne Begründung.';
                }
            }
        }

        return $unmet;
    }

    /**
     * Strengstes Geschäftsergebnis aus status_business des Teilanliegens, den Aktionsplänen des Teilanliegens
     * (oder des Vorgangs ohne Teilanliegenbezug) und deren Ausführungen der aktuellen Planversion.
     */
    public function effectiveBusinessStatus(CaseItem $item): ActionStatus
    {
        $mirrored = $item->status_business instanceof ActionStatus ? $item->status_business : ActionStatus::from((string) $item->status_business);
        $strictest = $mirrored;

        $plans = ActionPlan::query()->withoutGlobalScopes()
            ->where(static function ($q) use ($item): void {
                $q->where('case_item_id', $item->getKey())
                    ->orWhere(static fn ($q2) => $q2->where('case_id', $item->case_id)->whereNull('case_item_id'));
            })
            ->get(['id', 'status', 'current_version_id']);

        foreach ($plans as $plan) {
            $planStatus = $plan->status instanceof ActionStatus ? $plan->status : ActionStatus::tryFrom((string) $plan->getAttribute('status'));
            $strictest = $this->stricter($strictest, $planStatus);
        }

        $versionIds = $plans->pluck('current_version_id')->filter()->map(static fn (mixed $id): int => (int) $id)->values()->all();

        if ($versionIds !== []) {
            $executionStatuses = Execution::query()
                ->whereIn('action_plan_version_id', $versionIds)
                ->distinct()
                ->pluck('status');

            foreach ($executionStatuses as $status) {
                $strictest = $this->stricter($strictest, self::EXECUTION_STATUS[(string) $status] ?? null);
            }
        }

        return $strictest;
    }

    private function stricter(ActionStatus $current, ?ActionStatus $candidate): ActionStatus
    {
        if ($candidate === null) {
            return $current;
        }

        return (self::SEVERITY[$candidate->value] ?? 0) > (self::SEVERITY[$current->value] ?? 0) ? $candidate : $current;
    }

    /**
     * Ein Vorgang ist abschließbar, wenn alle Teilanliegen gelöst oder geschlossen sind und keine offenen
     * Bedingungen mehr bestehen.
     *
     * @return array<int, string>
     */
    public function unmetForCase(MailCase $case): array
    {
        $unmet = [];

        foreach ($case->items()->get() as $item) {
            $status = $item->status_processing instanceof CaseStatus ? $item->status_processing : CaseStatus::from((string) $item->status_processing);

            if ($status->isOpen()) {
                $unmet[] = sprintf('Teilanliegen "%s" ist noch %s.', (string) $item->title, $status->label());

                foreach ($this->unmetForItem($item) as $line) {
                    $unmet[] = sprintf('Teilanliegen "%s": %s', (string) $item->title, $line);
                }
            }
        }

        return array_values(array_unique($unmet));
    }
}
