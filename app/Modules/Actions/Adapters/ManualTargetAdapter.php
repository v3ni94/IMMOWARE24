<?php

declare(strict_types=1);

namespace App\Modules\Actions\Adapters;

use App\Modules\Actions\Contracts\StepAwareAdapterInterface;
use App\Modules\Actions\DTO\StepResult;
use App\Modules\Actions\Enums\TargetSystem;
use App\Modules\Actions\Enums\VerificationStatus;
use App\Modules\Actions\Models\ActionPlanVersion;
use App\Modules\Actions\Models\Execution;
use App\Modules\Actions\Services\ManualTaskFactory;
use App\Modules\Actions\Services\ManualTaskService;

/**
 * Zielsystem "manuell": erzeugt ausschließlich Aufgaben im Hub mit Alt/Neu. Verifikation nur durch menschliche Bestätigung.
 */
class ManualTargetAdapter implements StepAwareAdapterInterface
{
    public function __construct(private readonly ManualTaskFactory $tasks) {}

    public function targetSystem(): string
    {
        return TargetSystem::Manual->value;
    }

    /**
     * @return array<string, array{status: string, reason: ?string}>
     */
    public function capabilities(): array
    {
        return [
            'manual_task' => ['status' => 'available', 'reason' => null],
            'address_change' => ['status' => 'restricted', 'reason' => 'Nur als Aufgabe mit Alt/Neu, Umsetzung von Hand.'],
            'bank_change' => ['status' => 'restricted', 'reason' => 'Nur als Aufgabe mit Alt/Neu, Identitätsprüfung und zwei Freigaben.'],
        ];
    }

    /**
     * @param  array{reference_type: string, external_id: string, local_id?: ?int}  $ref
     * @return array<string, mixed>
     */
    public function readCurrent(array $ref): array
    {
        return [];
    }

    /**
     * @return array{steps: array<int, array<string, mixed>>, risk_class: string, approval_required: bool, warnings: array<int, string>}
     */
    public function prepareChange(object $plan): array
    {
        return ['steps' => [], 'risk_class' => 'medium', 'approval_required' => true, 'warnings' => []];
    }

    /**
     * @return array<int, array{step_index: int, status: string, response_status: ?int, execution_uuid: string}>
     */
    public function executeApprovedChange(object $planVersion, object $approval): array
    {
        return [];
    }

    /**
     * @return array{status: string, expected: array<string, mixed>, observed: array<string, mixed>}
     */
    public function verifyChange(object $execution): array
    {
        return ['status' => VerificationStatus::Unverified->value, 'expected' => [], 'observed' => []];
    }

    /**
     * @param  array<string, mixed>  $step
     * @return array<int, string>
     */
    public function validateStep(array $step): array
    {
        return [];
    }

    public function executeStep(ActionPlanVersion $version, int $stepIndex, Execution $execution): StepResult
    {
        $step = $version->steps()[$stepIndex] ?? [];
        $old = (array) (($version->getAttribute('old_values') ?? [])[$stepIndex] ?? []);
        $new = (array) (($version->getAttribute('new_values') ?? [])[$stepIndex] ?? []);
        $created = $this->tasks->create($version, $stepIndex, $step, $old, $new, TargetSystem::Manual, $execution->getAttribute('executed_by'));

        return StepResult::manualTask((int) $created['task']->getKey(), null, ['mode' => 'manual_task']);
    }

    /**
     * @return array{status: string, expected: array<string, mixed>, observed: array<string, mixed>, method: string}
     */
    public function verifyStep(ActionPlanVersion $version, int $stepIndex, Execution $execution): array
    {
        $task = $execution->task;
        $confirmed = $task !== null && in_array($task->getAttribute('status'), [ManualTaskService::TASK_DONE_MANUAL, ManualTaskService::TASK_DONE_VERIFIED], true);

        return [
            'status' => $confirmed ? VerificationStatus::ManuallyConfirmed->value : VerificationStatus::Unverified->value,
            'expected' => (array) (($version->getAttribute('new_values') ?? [])[$stepIndex] ?? []),
            'observed' => ['task_status' => $task?->getAttribute('status')],
            'method' => 'manual_confirmation',
        ];
    }
}
