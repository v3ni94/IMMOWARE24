<?php

declare(strict_types=1);

namespace App\Modules\MailIntegration\Services;

use App\Modules\Cases\Enums\CaseStatus;
use App\Modules\Cases\Exceptions\CaseNotClosableException;
use App\Modules\Cases\Exceptions\InvalidTransitionException;
use App\Modules\Cases\Models\CaseLock;
use App\Modules\Cases\Models\MailCase;
use App\Modules\Cases\Services\CaseService;
use App\Modules\Cases\Services\CaseStatusLogger;
use App\Modules\Cases\Services\DelegationService;
use App\Modules\Cases\Services\LockService;
use App\Modules\Cases\Services\TaskService;
use App\Modules\MailUi\Contracts\CaseCommandInterface;
use App\Modules\MailUi\DTO\WorkflowResult;
use App\Modules\MailUi\Services\NullCaseCommand;
use App\Modules\Security\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Live-Verdrahtung der Oberfläche an das Modul Cases: Zuweisung über DelegationService (Vertretung), Statuswechsel
 * über CaseService mit Übergangsmatrix und Abschlussbedingungen, Aufgaben über TaskService, Sperren über
 * LockService. Beantwortet ist nicht erledigt: resolved oder closed scheitern an offenen Abschlussbedingungen.
 */
final class LiveCaseCommand implements CaseCommandInterface
{
    public function __construct(
        private readonly CaseService $cases,
        private readonly DelegationService $delegation,
        private readonly TaskService $tasks,
        private readonly LockService $locks,
        private readonly CaseStatusLogger $log,
    ) {}

    public function assign(MailCase $case, ?User $assignee, User $actor, ?string $nextStep = null, ?string $dueAt = null): WorkflowResult
    {
        if (($locked = $this->lockedByOther($case, $actor)) !== null) {
            return $locked;
        }

        $due = $dueAt !== null && $dueAt !== '' ? NullCaseCommand::parseBerlin($dueAt) : null;

        if ($dueAt !== null && $dueAt !== '' && $due === null) {
            return WorkflowResult::failed('Fälligkeit nicht lesbar (Format TT.MM.JJJJ HH:MM).');
        }

        return DB::transaction(function () use ($case, $assignee, $actor, $nextStep, $due): WorkflowResult {
            $changes = [];

            if ($nextStep !== null && $nextStep !== '') {
                $changes['next_step'] = $nextStep;
            }

            if ($due !== null) {
                $changes['due_at'] = $due;
            }

            if ($changes !== []) {
                $case->forceFill($changes)->save();
            }

            if ($assignee === null) {
                $before = $case->getAttribute('assignee_user_id');
                $case->forceFill(['assignee_user_id' => null])->save();
                $this->log->log($case, 'assignment', $before !== null ? (string) $before : null, '', 'Zuweisung entfernt (Oberfläche).', $actor->getKey());

                return WorkflowResult::ok('Zuweisung entfernt.');
            }

            $this->delegation->assign($case, $assignee, $actor, 'Zuweisung durch Oberfläche');
            $case->refresh();

            if ($case->status_processing === CaseStatus::AssignmentOpen) {
                try {
                    $this->cases->transitionProcessing($case, CaseStatus::Open, $actor, 'Verantwortlicher gesetzt.');
                } catch (InvalidTransitionException) {
                    // Zuordnung bleibt offen, die Zuweisung ist trotzdem gespeichert.
                }
            }

            $case->refresh();
            $missing = $this->missingMandatoryFields($case);

            return $missing === []
                ? WorkflowResult::ok('Vorgang zugewiesen an '.$this->assigneeName($case, $assignee).'.')
                : WorkflowResult::ok('Zuweisung gespeichert. Unvollständig: '.implode(', ', $missing).'.');
        });
    }

    public function setCategory(MailCase $case, string $caseType, User $actor): WorkflowResult
    {
        if (($locked = $this->lockedByOther($case, $actor)) !== null) {
            return $locked;
        }

        $before = (string) $case->getAttribute('case_type');
        $case->forceFill(['case_type' => $caseType])->save();
        $this->log->log($case, 'category', $before, $caseType, 'Kategorie durch Oberfläche', $actor->getKey());

        return WorkflowResult::ok('Kategorie gesetzt.');
    }

    public function setProcessingStatus(MailCase $case, string $status, User $actor, ?string $reason = null): WorkflowResult
    {
        $target = CaseStatus::tryFrom($status);

        if ($target === null) {
            return WorkflowResult::failed('Unbekannter Bearbeitungsstatus.');
        }

        if (($locked = $this->lockedByOther($case, $actor)) !== null) {
            return $locked;
        }

        try {
            $this->cases->transitionProcessing($case, $target, $actor, $reason ?? 'Statuswechsel durch Oberfläche');
        } catch (CaseNotClosableException $e) {
            return WorkflowResult::failed('Abschluss nicht möglich, offene Bedingungen: '.implode('; ', $e->unmet).'. Beantwortet ist nicht erledigt.');
        } catch (InvalidTransitionException|InvalidArgumentException $e) {
            return WorkflowResult::failed($e->getMessage());
        }

        return WorkflowResult::ok('Bearbeitungsstatus: '.$target->label().'.');
    }

    public function createInternalTask(MailCase $case, array $data, User $actor): WorkflowResult
    {
        $due = $data['due_at'] !== null && $data['due_at'] !== '' ? NullCaseCommand::parseBerlin($data['due_at']) : null;

        try {
            $task = $this->tasks->create($case, null, [
                'task_type' => 'other',
                'title' => $data['title'],
                'instructions' => $data['instructions'],
                'target_system' => 'manual',
                'assignee_user_id' => $data['assignee_user_id'],
                'due_at' => $due,
            ], $actor);
        } catch (InvalidArgumentException $e) {
            return WorkflowResult::failed($e->getMessage());
        }

        return WorkflowResult::ok('Interne Aufgabe angelegt.', (int) $task->getKey());
    }

    public function addInternalNote(MailCase $case, string $note, User $actor): WorkflowResult
    {
        $this->log->log($case, 'note', null, 'note', mb_substr($note, 0, 500), $actor->getKey());

        return WorkflowResult::ok('Interne Notiz gespeichert (nie Teil einer Antwort).');
    }

    /**
     * @return array<int, string>
     */
    public function missingMandatoryFields(MailCase $case): array
    {
        if (! $case->status_processing->isOpen()) {
            return [];
        }

        $missing = [];

        if ($case->getAttribute('assignee_user_id') === null) {
            $missing[] = 'Verantwortlicher';
        }

        if (trim((string) $case->getAttribute('next_step')) === '') {
            $missing[] = 'nächster Schritt';
        }

        if ($case->getAttribute('due_at') === null) {
            $missing[] = 'Fälligkeit';
        }

        return $missing;
    }

    private function lockedByOther(MailCase $case, User $actor): ?WorkflowResult
    {
        $holder = $this->locks->holder($case);

        if ($holder instanceof CaseLock && (int) $holder->getAttribute('user_id') !== (int) $actor->getKey()) {
            return WorkflowResult::failed('Der Vorgang ist durch eine andere Person in Bearbeitung gesperrt.');
        }

        return null;
    }

    private function assigneeName(MailCase $case, User $requested): string
    {
        $effective = (int) $case->getAttribute('assignee_user_id');

        if ($effective !== (int) $requested->getKey()) {
            $substitute = User::query()->find($effective);

            return (string) ($substitute?->getAttribute('name') ?? $effective).' (Vertretung für '.(string) $requested->getAttribute('name').')';
        }

        return (string) $requested->getAttribute('name');
    }
}
