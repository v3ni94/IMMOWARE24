<?php

declare(strict_types=1);

namespace App\Modules\Cases\Services;

use App\Modules\Cases\Enums\CaseStatus;
use App\Modules\Cases\Enums\TaskStatus;
use App\Modules\Cases\Exceptions\AssignmentOpenException;
use App\Modules\Cases\Exceptions\InvalidTransitionException;
use App\Modules\Cases\Models\CaseItem;
use App\Modules\Cases\Models\MailCase;
use App\Modules\Cases\Models\Task;
use App\Modules\Cases\StateMachines\TaskStateMachine;
use App\Modules\Security\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;

/**
 * Aufgaben eines Vorgangs. Erledigt heißt done_manual_confirmed (mit Bestätiger) oder done_verified (Zweitbestätigung
 * durch eine andere Person oder Nachlesen im Zielsystem). Sensible Aufgaben sind bei offener Zuordnung gesperrt.
 */
final class TaskService
{
    public function __construct(
        private readonly TaskStateMachine $machine,
        private readonly CaseStatusLogger $log,
        private readonly Repository $config,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  task_type, title, instructions, old_value_json, new_value_json, target_system, assignee_user_id, due_at
     */
    public function create(MailCase $case, ?CaseItem $item, array $attributes, ?User $actor = null): Task
    {
        $type = (string) ($attributes['task_type'] ?? 'other');
        $types = (array) $this->config->get('hub.cases.task_types', []);

        if (! array_key_exists($type, $types)) {
            throw new InvalidArgumentException(sprintf('Unbekannter Aufgabentyp %s.', $type));
        }

        $this->assertSensitiveAllowed($case, $item, $type);

        $task = Task::query()->create([
            'organization_id' => $case->organization_id,
            'case_id' => $case->getKey(),
            'case_item_id' => $item?->getKey(),
            'task_type' => $type,
            'title' => mb_substr((string) ($attributes['title'] ?? $types[$type]), 0, 300),
            'instructions' => $attributes['instructions'] ?? null,
            'old_value_json' => $attributes['old_value_json'] ?? null,
            'new_value_json' => $attributes['new_value_json'] ?? null,
            'target_system' => $attributes['target_system'] ?? null,
            'assignee_user_id' => $attributes['assignee_user_id'] ?? ($item !== null ? $item->assignee_user_id : null) ?? $case->assignee_user_id,
            'due_at' => $attributes['due_at'] ?? ($item !== null ? $item->due_at : null) ?? $case->due_at,
            'status' => TaskStatus::Open->value,
            'created_by' => $actor?->getKey(),
        ]);

        $this->log->log($case, 'task', null, TaskStatus::Open->value, 'Aufgabe angelegt: '.$task->title, $actor?->getKey(), $item, 'user', ['task_id' => $task->getKey(), 'task_type' => $type]);

        return $task;
    }

    public function transition(Task $task, TaskStatus $to, ?User $actor = null, ?string $reason = null): Task
    {
        $from = TaskStatus::from((string) $task->status);
        $this->machine->assertTransition($from, $to);

        if ($to === TaskStatus::DoneManualConfirmed) {
            if ($actor === null) {
                throw new InvalidTransitionException(TaskStateMachine::DIMENSION, $from->value, $to->value, 'Manuelle Bestätigung braucht eine bestätigende Person.');
            }

            $task->forceFill(['confirmed_by' => $actor->getKey(), 'confirmed_at' => CarbonImmutable::now()]);
        }

        if ($to === TaskStatus::DoneVerified) {
            if ($actor === null || ($task->confirmed_by !== null && (int) $task->confirmed_by === (int) $actor->getKey())) {
                throw new InvalidTransitionException(TaskStateMachine::DIMENSION, $from->value, $to->value, 'Verifikation verlangt eine zweite Person (Vier-Augen-Prinzip).');
            }
        }

        $task->forceFill(['status' => $to->value])->save();

        $case = $task->loadMissing(['case', 'caseItem'])->case;

        if ($case instanceof MailCase) {
            $this->log->log($case, 'task', $from->value, $to->value, $reason ?? ('Aufgabe: '.$task->title), $actor?->getKey(), $task->caseItem, 'user', ['task_id' => $task->getKey()]);
        }

        return $task;
    }

    public function confirmManual(Task $task, User $actor, ?string $reason = null): Task
    {
        return $this->transition($task, TaskStatus::DoneManualConfirmed, $actor, $reason ?? 'Manuell im Zielsystem erledigt und bestätigt.');
    }

    public function verify(Task $task, User $verifier, ?string $reason = null): Task
    {
        return $this->transition($task, TaskStatus::DoneVerified, $verifier, $reason ?? 'Im Zielsystem nachgelesen, Zweitbestätigung.');
    }

    public function cancel(Task $task, User $actor, string $reason): Task
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Stornierung einer Aufgabe braucht eine Begründung.');
        }

        return $this->transition($task, TaskStatus::Cancelled, $actor, $reason);
    }

    /**
     * Bei mehrdeutiger Zuordnung (assignment_open) sind Aufgaben mit Außenwirkung auf Stammdaten gesperrt.
     */
    public function assertSensitiveAllowed(MailCase $case, ?CaseItem $item, string $taskType): void
    {
        $status = $case->status_processing instanceof CaseStatus ? $case->status_processing->value : (string) $case->status_processing;

        if ($status !== 'assignment_open') {
            return;
        }

        $sensitiveTypes = (array) $this->config->get('hub.cases.sensitive_case_types', []);
        $sensitiveTasks = ['manual_change_immoware', 'manual_change_lexware'];

        if (in_array($taskType, $sensitiveTasks, true) || in_array((string) ($item !== null ? $item->item_type : $case->case_type), $sensitiveTypes, true)) {
            throw new AssignmentOpenException(sprintf('Zuordnung des Vorgangs %s ist offen, sensible Änderung (%s) gesperrt.', (string) $case->case_number, $taskType));
        }
    }
}
