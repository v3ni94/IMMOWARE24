<?php

declare(strict_types=1);

namespace App\Modules\Actions\Services;

use App\Core\Contracts\AuditLoggerInterface;
use App\Core\Enums\AuditSource;
use App\Core\Support\CorrelationId;
use App\Modules\Actions\Enums\TargetSystem;
use App\Modules\Actions\Enums\VerificationStatus;
use App\Modules\Actions\Events\ExecutionVerified;
use App\Modules\Actions\Exceptions\ActionPolicyException;
use App\Modules\Actions\Models\ActionTarget;
use App\Modules\Actions\Models\Execution;
use App\Modules\Actions\Models\Verification;
use App\Modules\Cases\Models\Task;
use App\Modules\Security\Models\User;
use App\Modules\Sync\Enums\ProposedChangeStatus;
use App\Modules\Sync\Services\ProposedChangeService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Database\ConnectionInterface;

/**
 * Erledigung manueller Aufgaben (Immoware24-Änderung von Hand): Nutzer bestätigt, Status "Manuell bestätigt"
 * (VerificationStatus manually_confirmed). Der Befehl mail:actions:recheck-manual (Zeitplan) liest nach dem nächsten
 * Sync über recheck() den Spiegel nach; api_verified nur bei tatsächlichem Wert-Match, sonst bleibt es bei der
 * manuellen Bestätigung.
 */
final class ManualTaskService
{
    public const string TASK_OPEN = 'open';

    public const string TASK_DONE_MANUAL = 'done_manual_confirmed';

    public const string TASK_DONE_VERIFIED = 'done_verified';

    public function __construct(
        private readonly ExecutionService $executions,
        private readonly AdapterRegistry $adapters,
        private readonly ProposedChangeService $proposedChanges,
        private readonly ConnectionInterface $db,
        private readonly AuditLoggerInterface $audit,
        private readonly CorrelationId $correlation,
        private readonly EventDispatcher $events,
    ) {}

    /**
     * Nutzer hat die Änderung im Zielsystem vorgenommen.
     */
    public function confirm(Task $task, User $confirmedBy): Verification
    {
        if (! in_array($task->getAttribute('status'), [self::TASK_OPEN, 'in_progress', 'waiting'], true)) {
            throw new ActionPolicyException('Aufgabe ist nicht offen.', 'task_not_open');
        }

        /** @var Execution|null $execution */
        $execution = Execution::query()->where('task_id', $task->getKey())->first();

        if ($execution === null) {
            throw new ActionPolicyException('Zur Aufgabe existiert kein Ausführungsbeleg.', 'execution_missing');
        }

        $verification = $this->db->transaction(function () use ($task, $confirmedBy, $execution): Verification {
            $task->forceFill(['status' => self::TASK_DONE_MANUAL, 'confirmed_by' => $confirmedBy->getKey(), 'confirmed_at' => CarbonImmutable::now()]);
            $task->save();

            $proposedChangeId = $execution->getAttribute('proposed_change_id');

            if ($proposedChangeId !== null) {
                $change = $execution->proposedChange;

                if ($change !== null && $change->getAttribute('status') === ProposedChangeStatus::Open) {
                    $this->proposedChanges->markTransferred((int) $proposedChangeId, $confirmedBy);
                }
            }

            $verification = new Verification;
            $verification->forceFill([
                'execution_id' => $execution->getKey(),
                'method' => 'manual_confirmation',
                'expected_json' => null,
                'observed_json' => ['task_id' => $task->getKey(), 'confirmed_by' => $confirmedBy->getKey()],
                'result' => VerificationStatus::ManuallyConfirmed,
                'verified_by' => $confirmedBy->getKey(),
                'verified_at' => CarbonImmutable::now(),
            ]);
            $verification->save();

            $execution->forceFill(['status' => ExecutionService::STATUS_VERIFIED, 'verification_status' => VerificationStatus::ManuallyConfirmed]);
            $execution->save();

            $target = $execution->version?->targets()->where('step_index', $execution->getAttribute('step_index'))->first();

            if ($target !== null) {
                $target->forceFill(['status' => ActionTarget::VERIFIED, 'last_error' => null]);
                $target->save();
            }

            $version = $execution->version()->with('plan')->first();

            if ($version !== null) {
                $this->executions->refreshPlanStatus($version);
            }

            $this->audit->log('mail.task.manually_confirmed', $task, ['status' => self::TASK_OPEN], ['status' => self::TASK_DONE_MANUAL, 'confirmed_by' => $confirmedBy->getKey()], AuditSource::Mail->value, $this->correlation->current());

            return $verification;
        });

        $this->events->dispatch(new ExecutionVerified((int) $execution->getKey(), (int) $execution->getAttribute('action_plan_version_id'), (int) $execution->getAttribute('step_index'), VerificationStatus::ManuallyConfirmed, (int) $confirmedBy->getKey()));

        return $verification;
    }

    /**
     * Nachlesen im Spiegel nach dem nächsten Sync (mail:actions:recheck-manual). api_verified nur bei tatsächlichem
     * Wert-Match; ohne Match bleibt die manuelle Bestätigung unverändert und es entsteht kein Beleg (Rückgabe null).
     */
    public function recheck(Execution $execution): ?Verification
    {
        if ($execution->getAttribute('verification_status') !== VerificationStatus::ManuallyConfirmed) {
            return null;
        }

        $version = $execution->version()->with('plan')->first();

        if ($version === null) {
            return null;
        }

        // Lesender Vorabvergleich, damit ein Lauf ohne Wert-Match keine Belege, Ereignisse oder Outbox-Einträge erzeugt.
        $system = TargetSystem::tryFrom((string) $execution->getAttribute('target_system'));
        $outcome = $system !== null ? $this->adapters->for($system)->verifyStep($version, (int) $execution->getAttribute('step_index'), $execution) : ['status' => VerificationStatus::Unverified->value];

        if (($outcome['status'] ?? null) !== VerificationStatus::ApiVerified->value) {
            return null;
        }

        $verification = $this->executions->verify($execution);

        if ($verification->getAttribute('result') === VerificationStatus::ApiVerified) {
            $task = $execution->task;

            if ($task !== null) {
                $task->forceFill(['status' => self::TASK_DONE_VERIFIED]);
                $task->save();
                $this->audit->log('mail.task.verified_after_sync', $task, ['status' => self::TASK_DONE_MANUAL], ['status' => self::TASK_DONE_VERIFIED], AuditSource::Mail->value, $this->correlation->current());
            }

            $execution->forceFill(['verification_status' => VerificationStatus::ApiVerified]);
            $execution->save();
        }

        return $verification;
    }
}
