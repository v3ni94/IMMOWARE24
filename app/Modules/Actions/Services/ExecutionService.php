<?php

declare(strict_types=1);

namespace App\Modules\Actions\Services;

use App\Core\Contracts\AuditLoggerInterface;
use App\Core\Enums\AuditSource;
use App\Core\Support\CorrelationId;
use App\Core\Support\SecretMasker;
use App\Modules\Actions\DTO\StepResult;
use App\Modules\Actions\Enums\ActionStatus;
use App\Modules\Actions\Enums\TargetSystem;
use App\Modules\Actions\Enums\VerificationStatus;
use App\Modules\Actions\Events\ExecutionVerified;
use App\Modules\Actions\Exceptions\ActionConflictException;
use App\Modules\Actions\Exceptions\ActionNotEditableException;
use App\Modules\Actions\Exceptions\ActionPolicyException;
use App\Modules\Actions\Exceptions\ActionTimeoutException;
use App\Modules\Actions\Jobs\ExecuteActionJob;
use App\Modules\Actions\Jobs\VerifyActionJob;
use App\Modules\Actions\Models\ActionPlan;
use App\Modules\Actions\Models\ActionPlanVersion;
use App\Modules\Actions\Models\ActionTarget;
use App\Modules\Actions\Models\Execution;
use App\Modules\Actions\Models\Verification;
use App\Modules\Mail\Services\MailFeatureFlags;
use App\Modules\Security\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Pipeline recheck preconditions, execute, verify, Beleg. Der externe Aufruf läuft außerhalb der lokalen
 * Transaktion (Outbox-Muster). Idempotenz je Schritt über idempotency_key = sha256(version:schritt); ein Schritt mit
 * externem Ergebnis (executed, result_unclear, manual_task) wird nie erneut aufgerufen, sondern nachgelesen.
 * Mehrsystempläne: Zustand je Zielsystem (mail_action_targets), Teilfehler bleiben offen, nur fehlende Schritte
 * werden wiederholt. Kompensation (Rückänderung) erfolgt nie automatisch, bei sensiblen Daten grundsätzlich nicht.
 */
final class ExecutionService
{
    public const string STATUS_PENDING = 'pending';

    public const string STATUS_RUNNING = 'running';

    public const string STATUS_HTTP_OK_UNVERIFIED = 'http_ok_unverified';

    public const string STATUS_VERIFIED = 'verified';

    public const string STATUS_FAILED = 'failed';

    public const string STATUS_RESULT_UNCLEAR = 'result_unclear';

    public const string STATUS_MANUAL_TASK = 'manual_task';

    public const string STATUS_BLOCKED_FLAG = 'blocked_flag';

    public const string STATUS_BLOCKED_CAPABILITY = 'blocked_capability';

    public const string STATUS_BLOCKED_PRECONDITION = 'blocked_precondition';

    public function __construct(
        private readonly PreconditionChecker $preconditions,
        private readonly ActionPolicy $policy,
        private readonly ActionAllowlist $allowlist,
        private readonly AdapterRegistry $adapters,
        private readonly MailFeatureFlags $flags,
        private readonly ConnectionInterface $db,
        private readonly Dispatcher $bus,
        private readonly AuditLoggerInterface $audit,
        private readonly CorrelationId $correlation,
        private readonly ActionOutboxWriter $outbox,
        private readonly SecretMasker $masker,
        private readonly Repository $config,
        private readonly EventDispatcher $events,
    ) {}

    public static function idempotencyKey(int $versionId, int $stepIndex): string
    {
        return hash('sha256', sprintf('mail_action_plan_version:%d:step:%d', $versionId, $stepIndex));
    }

    /**
     * Führt genau einen Planschritt aus. Rückgabe null, wenn nichts zu tun war (bereits verifiziert oder gesperrt).
     */
    public function executeStep(ActionPlanVersion $version, int $stepIndex, ?int $executedBy, string $lockOwner): ?Execution
    {
        $version->loadMissing('plan');
        $plan = $version->plan;
        $step = $version->steps()[$stepIndex] ?? null;

        if ($plan === null || $step === null) {
            throw new ActionPolicyException('Planschritt existiert nicht.', 'invalid_step');
        }

        $target = $this->target($version, $stepIndex, $step);
        $existing = Execution::query()->where('idempotency_key', self::idempotencyKey((int) $version->getKey(), $stepIndex))->first();

        // Schritt läuft gerade (anderer Worker): nicht parallel ausführen. Ein seit Ablauf der Sperrfrist hängender
        // Beleg gilt als abgebrochen ohne Antwort: Ergebnis unklar, erst nachlesen.
        if ($existing instanceof Execution && $existing->getAttribute('status') === self::STATUS_RUNNING) {
            if (! $this->isStale($existing)) {
                return null;
            }

            $this->markUnclear($version, $existing, $target, 'Ausführung ohne Abschluss (Worker abgebrochen), Nachlesen erforderlich.');

            return $existing->refresh();
        }

        // Schritt hat bereits ein externes Ergebnis: niemals erneut aufrufen, sondern nachlesen.
        if ($existing instanceof Execution && ! $this->isRetryable($existing)) {
            if ($existing->getAttribute('status') === self::STATUS_VERIFIED) {
                return $existing;
            }

            $this->verify($existing);

            return $existing->refresh();
        }

        if (in_array($plan->status, [ActionStatus::Verified, ActionStatus::Failed], true)) {
            return null;
        }

        $reasons = $this->preconditions->check($version, $stepIndex);

        if ($reasons !== []) {
            $this->block($version, $target, $existing, self::STATUS_BLOCKED_PRECONDITION, implode(' ', $reasons));

            return null;
        }

        $system = TargetSystem::from((string) $step['target_system']);
        $definition = $this->allowlist->require($system, (string) $step['action_type']);

        if ($definition['mode'] === 'api' && $definition['flag'] !== null && ! $this->flags->enabled($definition['flag'])) {
            $this->block($version, $target, $existing, self::STATUS_BLOCKED_FLAG, sprintf('Feature-Flag %s ist nicht aktiv.', $definition['flag']));

            return null;
        }

        $adapter = $this->adapters->for($system);
        $approvals = array_map(static fn ($a): int => (int) $a->getAttribute('approver_user_id'), $this->policy->validApprovals($version));

        // Lokale Transaktion: Beleg (running), Zustand je Zielsystem, Outbox. Externer Aufruf erst danach.
        $execution = $this->db->transaction(function () use ($version, $plan, $stepIndex, $step, $system, $target, $existing, $executedBy, $lockOwner, $approvals): Execution {
            $execution = $existing ?? new Execution;
            $execution->forceFill([
                'action_plan_version_id' => $version->getKey(),
                'step_index' => $stepIndex,
                'execution_uuid' => $execution->getAttribute('execution_uuid') ?? (string) Str::uuid(),
                'idempotency_key' => self::idempotencyKey((int) $version->getKey(), $stepIndex),
                'action_key' => (string) $step['action_type'],
                'target_system' => $system->value,
                'status' => self::STATUS_RUNNING,
                'started_at' => CarbonImmutable::now(),
                'finished_at' => null,
                'attempts' => ((int) $execution->getAttribute('attempts')) + 1,
                'executed_by' => $executedBy,
                'approved_by_json' => $approvals,
                'verification_status' => VerificationStatus::Unverified,
                'lock_owner' => $lockOwner,
                'error_class' => null,
                'error_message' => null,
            ]);
            $execution->save();

            $target->forceFill(['status' => ActionTarget::RUNNING, 'execution_id' => $execution->getKey(), 'last_error' => null]);
            $target->save();

            $this->transition($plan, ActionStatus::Executing);
            $this->outbox->write((int) $plan->getAttribute('organization_id'), 'execution.started', 'execution', (int) $execution->getKey(), ['step_index' => $stepIndex, 'target_system' => $system->value]);

            return $execution;
        });

        try {
            $result = $adapter->executeStep($version, $stepIndex, $execution);
        } catch (ActionTimeoutException $e) {
            $this->markUnclear($version, $execution, $target, $e->getMessage());

            return $execution;
        } catch (ActionConflictException|ActionNotEditableException $e) {
            $this->markFailed($version, $execution, $target, $e, false);

            return $execution;
        } catch (Throwable $e) {
            $this->markFailed($version, $execution, $target, $e, true);

            throw $e;
        }

        return $this->applyResult($version, $execution, $target, $result);
    }

    /**
     * Nachlesen des Zielzustands. Wird nach jeder Ausführung und nach result_unclear aufgerufen, immer bevor
     * irgendetwas wiederholt wird.
     */
    public function verify(Execution $execution, ?User $verifiedBy = null): Verification
    {
        $version = $execution->version()->with('plan')->firstOrFail();
        $stepIndex = (int) $execution->getAttribute('step_index');
        $step = $version->steps()[$stepIndex] ?? [];
        $target = $this->target($version, $stepIndex, $step);
        $system = TargetSystem::from((string) $execution->getAttribute('target_system'));

        try {
            $outcome = $this->adapters->for($system)->verifyStep($version, $stepIndex, $execution);
        } catch (Throwable $e) {
            $outcome = ['status' => VerificationStatus::Unverified->value, 'expected' => [], 'observed' => ['error' => class_basename($e)], 'method' => 'reread_get'];
        }

        $status = VerificationStatus::tryFrom((string) $outcome['status']) ?? VerificationStatus::Unverified;

        $verification = new Verification;
        $verification->forceFill([
            'execution_id' => $execution->getKey(),
            'method' => (string) ($outcome['method'] ?? 'reread_get'),
            'expected_json' => $this->masker->maskArray($this->maskValues((array) $outcome['expected'])),
            'observed_json' => $this->masker->maskArray($this->maskValues((array) $outcome['observed'])),
            'result' => $status,
            'verified_by' => $verifiedBy?->getKey(),
            'verified_at' => $status->countsAsVerified() ? CarbonImmutable::now() : null,
        ]);
        $verification->save();

        $plan = $version->plan;
        $wasUnclear = $execution->getAttribute('status') === self::STATUS_RESULT_UNCLEAR;

        if ($status === VerificationStatus::ApiVerified || $status === VerificationStatus::ManuallyConfirmed) {
            $execution->forceFill(['status' => self::STATUS_VERIFIED, 'verification_status' => $status, 'finished_at' => $execution->getAttribute('finished_at') ?? CarbonImmutable::now()]);
            $execution->save();
            $target->forceFill(['status' => ActionTarget::VERIFIED, 'last_error' => null]);
            $target->save();

            if ($plan !== null) {
                $this->outbox->write((int) $plan->getAttribute('organization_id'), 'execution.verified', 'execution', (int) $execution->getKey(), ['step_index' => $stepIndex, 'verification' => $status->value]);
            }

            $this->events->dispatch(new ExecutionVerified((int) $execution->getKey(), (int) $version->getKey(), $stepIndex, $status, $verifiedBy?->getKey()));
        } elseif ($wasUnclear && (bool) ($outcome['observed']['unchanged'] ?? false)) {
            // Nachlesen zeigt: Zielsystem unverändert. Erst jetzt darf der Schritt erneut versucht werden.
            $execution->forceFill(['status' => self::STATUS_FAILED, 'error_class' => ActionTimeoutException::class, 'error_message' => 'Ergebnis unklar, Nachlesen zeigt unveränderten Zielzustand. Wiederholung zulässig.', 'finished_at' => CarbonImmutable::now()]);
            $execution->save();
            $target->forceFill(['status' => ActionTarget::FAILED, 'last_error' => (string) $execution->getAttribute('error_message')]);
            $target->save();

            if ((int) $execution->getAttribute('attempts') < $this->maxAttempts()) {
                $this->bus->dispatch(ExecuteActionJob::forStep((int) $version->getKey(), $stepIndex, $execution->getAttribute('executed_by'), $this->correlation->current()));
            }
        } elseif ($status === VerificationStatus::Failed) {
            $execution->forceFill(['status' => self::STATUS_FAILED, 'verification_status' => $status, 'error_message' => 'Verifikation fehlgeschlagen: Zielzustand entspricht nicht dem Sollwert.', 'finished_at' => CarbonImmutable::now()]);
            $execution->save();
            $target->forceFill(['status' => ActionTarget::FAILED, 'last_error' => (string) $execution->getAttribute('error_message')]);
            $target->save();
        }

        if ($plan !== null) {
            $this->refreshPlanStatus($version);
            $this->audit->log('mail.execution.verified', $execution, [], ['result' => $status->value, 'method' => $verification->getAttribute('method')], AuditSource::Mail->value, $this->correlation->current());
        }

        return $verification;
    }

    /**
     * Wiederholt ausschließlich offene Schritte (pending, failed, blocked). Verifizierte Schritte bleiben unberührt.
     *
     * @return array<int, int> Schrittindizes, die eingeplant wurden
     */
    public function retryOpenSteps(ActionPlanVersion $version, ?User $actor = null): array
    {
        $dispatched = [];

        foreach ($version->targets()->orderBy('step_index')->get() as $target) {
            if (! $target instanceof ActionTarget || ! $target->isRetryable()) {
                continue;
            }

            $index = (int) $target->getAttribute('step_index');
            $this->bus->dispatch(ExecuteActionJob::forStep((int) $version->getKey(), $index, $actor?->getKey(), $this->correlation->current()));
            $dispatched[] = $index;
        }

        return $dispatched;
    }

    /**
     * Gesamtstatus des Plans aus den Zielzuständen. Nur wenn alle Schritte verifiziert sind, ist der Plan verified.
     */
    public function refreshPlanStatus(ActionPlanVersion $version): ActionStatus
    {
        $plan = $version->plan;

        if ($plan === null) {
            return ActionStatus::Failed;
        }

        $statuses = $version->targets()->pluck('status')->map(static fn (mixed $s): string => (string) $s)->all();

        if ($statuses === []) {
            return $plan->status instanceof ActionStatus ? $plan->status : ActionStatus::Proposed;
        }

        $all = static fn (string $status): bool => count(array_filter($statuses, static fn (string $s): bool => $s === $status)) === count($statuses);
        $any = static fn (string ...$wanted): bool => array_intersect($statuses, $wanted) !== [];

        $status = match (true) {
            $all(ActionTarget::VERIFIED) => ActionStatus::Verified,
            $any(ActionTarget::RUNNING) => ActionStatus::Executing,
            $any(ActionTarget::RESULT_UNCLEAR) => ActionStatus::ResultUnclear,
            $any(ActionTarget::FAILED, ActionTarget::BLOCKED) => ActionStatus::ManualReview,
            $any(ActionTarget::EXECUTED, ActionTarget::MANUAL_TASK) => ActionStatus::Executed,
            default => $plan->status instanceof ActionStatus ? $plan->status : ActionStatus::Approved,
        };

        $plan->forceFill(['status' => $status]);
        $plan->save();

        return $status;
    }

    private function applyResult(ActionPlanVersion $version, Execution $execution, ActionTarget $target, StepResult $result): Execution
    {
        $plan = $version->plan;
        $organizationId = (int) $plan?->getAttribute('organization_id');
        $excerptMax = (int) $this->config->get('hub.actions.response_excerpt_max', 4000);

        switch ($result->status) {
            case StepResult::EXECUTED:
                $execution->forceFill([
                    'status' => self::STATUS_HTTP_OK_UNVERIFIED,
                    'response_status' => $result->responseStatus,
                    'request_summary_json' => $this->masker->maskArray($result->requestSummary),
                    'result_masked_json' => $this->masker->maskArray($result->resultMasked),
                    'response_excerpt' => mb_substr($this->masker->maskString(json_encode($result->resultMasked, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)), 0, $excerptMax),
                    'finished_at' => CarbonImmutable::now(),
                ]);
                $execution->save();
                $target->forceFill(['status' => ActionTarget::EXECUTED]);
                $target->save();
                $this->audit->log('mail.execution.executed', $execution, [], ['step_index' => $execution->getAttribute('step_index'), 'response_status' => $result->responseStatus], AuditSource::Mail->value, $this->correlation->current());
                // HTTP-Erfolg ist kein Geschäftsergebnis: sofort nachlesen.
                $this->verify($execution);
                break;

            case StepResult::MANUAL_TASK:
                $execution->forceFill([
                    'status' => self::STATUS_MANUAL_TASK,
                    'task_id' => $result->taskId,
                    'proposed_change_id' => $result->proposedChangeId,
                    'result_masked_json' => $this->masker->maskArray($result->resultMasked),
                    'finished_at' => CarbonImmutable::now(),
                ]);
                $execution->save();
                $target->forceFill(['status' => ActionTarget::MANUAL_TASK, 'task_id' => $result->taskId]);
                $target->save();
                $this->outbox->write($organizationId, 'execution.manual_task', 'execution', (int) $execution->getKey(), ['task_id' => $result->taskId]);
                $this->audit->log('mail.execution.manual_task', $execution, [], ['task_id' => $result->taskId, 'proposed_change_id' => $result->proposedChangeId], AuditSource::Mail->value, $this->correlation->current());
                $this->refreshPlanStatus($version);
                break;

            case StepResult::RESULT_UNCLEAR:
                $this->markUnclear($version, $execution, $target, (string) $result->error);
                break;

            default:
                // Technische Fehler (5xx, 429, keine Statusangabe) sind wiederholbar; fachliche Ablehnungen (4xx) nicht.
                $retryable = $result->responseStatus === null || $result->responseStatus >= 500 || $result->responseStatus === 429;
                $this->markFailed($version, $execution, $target, new \RuntimeException((string) ($result->error ?? 'Adapter meldet Fehler.')), $retryable, $result->responseStatus);
        }

        return $execution->refresh();
    }

    private function markUnclear(ActionPlanVersion $version, Execution $execution, ActionTarget $target, string $message): void
    {
        $execution->forceFill(['status' => self::STATUS_RESULT_UNCLEAR, 'error_class' => ActionTimeoutException::class, 'error_message' => $this->masker->maskString(mb_substr($message, 0, 2000)), 'finished_at' => CarbonImmutable::now()]);
        $execution->save();
        $target->forceFill(['status' => ActionTarget::RESULT_UNCLEAR, 'last_error' => $execution->getAttribute('error_message')]);
        $target->save();

        $plan = $version->plan;

        if ($plan !== null) {
            $this->outbox->write((int) $plan->getAttribute('organization_id'), 'execution.result_unclear', 'execution', (int) $execution->getKey(), ['step_index' => $execution->getAttribute('step_index')]);
        }

        $this->refreshPlanStatus($version);
        Log::warning('Action-Ausführung ohne verwertbare Antwort, Nachlesen eingeplant.', ['execution_id' => $execution->getKey()]);

        $delay = max(0, (int) $this->config->get('hub.actions.jobs.verify_delay_seconds', 60));
        $this->bus->dispatch((new VerifyActionJob((int) $execution->getKey(), $this->correlation->current()))->delay($delay));
    }

    private function markFailed(ActionPlanVersion $version, Execution $execution, ActionTarget $target, Throwable $exception, bool $retryable, ?int $responseStatus = null): void
    {
        $message = $this->masker->maskString(mb_substr($exception->getMessage(), 0, 2000));
        $execution->forceFill([
            'status' => self::STATUS_FAILED,
            'response_status' => $responseStatus,
            'error_class' => $exception::class,
            'error_message' => $message,
            'finished_at' => CarbonImmutable::now(),
        ]);
        $execution->save();
        // Konflikte und nicht änderbare Datensätze sind nicht wiederholbar (blocked), technische Fehler bleiben failed.
        $target->forceFill(['status' => $retryable ? ActionTarget::FAILED : ActionTarget::BLOCKED, 'last_error' => $message]);
        $target->save();

        $plan = $version->plan;

        if ($plan !== null) {
            $this->outbox->write((int) $plan->getAttribute('organization_id'), 'execution.failed', 'execution', (int) $execution->getKey(), ['step_index' => $execution->getAttribute('step_index'), 'error_class' => $exception::class]);
        }

        $this->audit->log('mail.execution.failed', $execution, [], ['error_class' => $exception::class], AuditSource::Mail->value, $this->correlation->current());
        $this->refreshPlanStatus($version);
    }

    private function block(ActionPlanVersion $version, ActionTarget $target, ?Execution $existing, string $status, string $reason): void
    {
        $target->forceFill(['status' => ActionTarget::BLOCKED, 'last_error' => $reason]);
        $target->save();

        if ($existing instanceof Execution) {
            $existing->forceFill(['status' => $status, 'error_message' => $reason]);
            $existing->save();
        }

        $plan = $version->plan;

        if ($plan !== null) {
            $plan->forceFill(['status' => ActionStatus::ManualReview]);
            $plan->save();
            $this->outbox->write((int) $plan->getAttribute('organization_id'), 'execution.failed', 'action_plan', (int) $plan->getKey(), ['reason' => $reason, 'status' => $status]);
            $this->audit->log('mail.action_plan.blocked', $plan, [], ['status' => $status, 'reason' => $reason], AuditSource::Mail->value, $this->correlation->current());
        }
    }

    public function isRunningElsewhere(int $versionId, int $stepIndex): bool
    {
        $execution = Execution::query()->where('idempotency_key', self::idempotencyKey($versionId, $stepIndex))->first();

        return $execution instanceof Execution && $execution->getAttribute('status') === self::STATUS_RUNNING && ! $this->isStale($execution);
    }

    private function isStale(Execution $execution): bool
    {
        $started = $execution->getAttribute('started_at');
        $ttl = max(30, (int) $this->config->get('hub.actions.jobs.lock_seconds', 300));

        return ! $started instanceof \DateTimeInterface || $started->getTimestamp() + $ttl < CarbonImmutable::now()->getTimestamp();
    }

    private function isRetryable(Execution $execution): bool
    {
        return in_array($execution->getAttribute('status'), [self::STATUS_PENDING, self::STATUS_FAILED, self::STATUS_BLOCKED_FLAG, self::STATUS_BLOCKED_CAPABILITY, self::STATUS_BLOCKED_PRECONDITION], true);
    }

    /**
     * @param  array<string, mixed>  $step
     */
    private function target(ActionPlanVersion $version, int $stepIndex, array $step): ActionTarget
    {
        /** @var ActionTarget $target */
        $target = ActionTarget::query()->firstOrCreate(
            ['action_plan_version_id' => $version->getKey(), 'step_index' => $stepIndex],
            ['target_system' => (string) ($step['target_system'] ?? 'manual'), 'action_type' => (string) ($step['action_type'] ?? 'manual_task'), 'status' => ActionTarget::PENDING],
        );

        return $target;
    }

    private function transition(ActionPlan $plan, ActionStatus $to): void
    {
        $from = $plan->status instanceof ActionStatus ? $plan->status : ActionStatus::Proposed;

        if ($from !== $to && ! $from->canTransitionTo($to) && ! in_array($from, [ActionStatus::ResultUnclear, ActionStatus::ManualReview, ActionStatus::Executed], true)) {
            Log::info('Statusübergang außerhalb der Matrix (Wiederholung offener Schritte).', ['plan_id' => $plan->getKey(), 'from' => $from->value, 'to' => $to->value]);
        }

        $plan->forceFill(['status' => $to]);
        $plan->save();
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function maskValues(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $values[$key] = $this->maskValues($value);
            } elseif (is_string($value) && str_contains(strtolower((string) $key), 'iban')) {
                $values[$key] = SecretMasker::MASK;
            }
        }

        return $values;
    }

    private function maxAttempts(): int
    {
        return max(1, (int) $this->config->get('hub.actions.jobs.tries', 3));
    }
}
