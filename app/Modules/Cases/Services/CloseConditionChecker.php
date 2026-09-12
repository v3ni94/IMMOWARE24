<?php

declare(strict_types=1);

namespace App\Modules\Cases\Services;

use App\Modules\Actions\Enums\ActionStatus;
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
 */
final class CloseConditionChecker
{
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

        $businessStatus = $item->status_business instanceof ActionStatus ? $item->status_business : ActionStatus::from((string) $item->status_business);

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
