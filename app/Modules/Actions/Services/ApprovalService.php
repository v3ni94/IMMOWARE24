<?php

declare(strict_types=1);

namespace App\Modules\Actions\Services;

use App\Core\Contracts\AuditLoggerInterface;
use App\Core\Enums\AuditSource;
use App\Core\Support\CorrelationId;
use App\Modules\Actions\Enums\ActionStatus;
use App\Modules\Actions\Exceptions\ActionPolicyException;
use App\Modules\Actions\Jobs\ExecuteActionJob;
use App\Modules\Actions\Models\ActionPlanVersion;
use App\Modules\Actions\Models\Approval;
use App\Modules\Actions\Models\IdentityCheck;
use App\Modules\Security\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;

/**
 * Freigaben und Identitätsprüfungen. Freigabe bindet an plan_version_id, steps_hash und diff_hash und läuft ab.
 * Vollständige Freigabe setzt den Plan auf approved (sofort ausführbar) oder scheduled (effective_date in Zukunft);
 * die Ausführung erfolgt ausschließlich über ExecuteActionJob.
 */
final class ApprovalService
{
    public function __construct(
        private readonly ActionPolicy $policy,
        private readonly ConnectionInterface $db,
        private readonly Dispatcher $bus,
        private readonly AuditLoggerInterface $audit,
        private readonly CorrelationId $correlation,
        private readonly ActionOutboxWriter $outbox,
    ) {}

    public function recordIdentityCheck(ActionPlanVersion $version, User $checkedBy, string $documentedChannel, ?CarbonImmutable $checkedAt = null, ?string $note = null): IdentityCheck
    {
        if (! in_array($documentedChannel, IdentityCheck::CHANNELS, true)) {
            throw new InvalidArgumentException(sprintf('Unbekannter Prüfkanal "%s".', $documentedChannel));
        }

        $check = new IdentityCheck;
        $check->forceFill([
            'action_plan_version_id' => $version->getKey(),
            'documented_channel' => $documentedChannel,
            'checked_by' => $checkedBy->getKey(),
            'checked_at' => $checkedAt ?? CarbonImmutable::now(),
            'note' => $note !== null ? mb_substr($note, 0, 500) : null,
        ]);
        $check->save();

        $this->audit->log('mail.action_plan.identity_checked', $version, [], ['channel' => $documentedChannel, 'checked_by' => $checkedBy->getKey()], AuditSource::Mail->value, $this->correlation->current());

        return $check;
    }

    /**
     * @throws ActionPolicyException
     */
    public function approve(ActionPlanVersion $version, User $approver, ?string $comment = null, ?CarbonImmutable $reauthConfirmedAt = null): Approval
    {
        $version->loadMissing('plan');
        $this->policy->assertCanApprove($version, $approver);

        if ($reauthConfirmedAt === null) {
            throw new ActionPolicyException('Freigabe ohne dokumentierte Re-Authentifizierung ist nicht zulässig.', 'reauth_missing');
        }

        $dispatch = false;

        $approval = $this->db->transaction(function () use ($version, $approver, $comment, $reauthConfirmedAt, &$dispatch): Approval {
            $approval = new Approval;
            $approval->forceFill([
                'action_plan_version_id' => $version->getKey(),
                'approver_user_id' => $approver->getKey(),
                'decision' => 'approved',
                'steps_hash' => $version->getAttribute('steps_hash'),
                'diff_hash' => $version->getAttribute('diff_hash'),
                'comment' => $comment !== null ? mb_substr($comment, 0, 500) : null,
                'reauth_confirmed_at' => $reauthConfirmedAt,
                'expires_at' => CarbonImmutable::now()->addHours($this->policy->approvalTtlHours()),
            ]);
            $approval->save();

            $plan = $version->plan;

            if ($plan === null) {
                throw new ActionPolicyException('Planversion ohne Plan.', 'invalid_version');
            }

            $this->audit->log('mail.action_plan.approved', $plan, [], ['version_id' => $version->getKey(), 'approver' => $approver->getKey()], AuditSource::Mail->value, $this->correlation->current());

            if ($this->policy->isFullyApproved($version)) {
                $effective = $version->getAttribute('effective_date');
                $future = $effective instanceof CarbonImmutable && $effective->startOfDay()->greaterThan(CarbonImmutable::now()->startOfDay());
                $plan->forceFill(['status' => $future ? ActionStatus::Scheduled : ActionStatus::Approved]);
                $plan->save();

                $this->outbox->write((int) $plan->getAttribute('organization_id'), $future ? 'plan.scheduled' : 'plan.approved', 'action_plan', (int) $plan->getKey(), ['version_id' => $version->getKey(), 'effective_date' => $effective instanceof CarbonImmutable ? $effective->toDateString() : null]);
                $dispatch = ! $future;
            } else {
                $plan->forceFill(['status' => ActionStatus::ApprovalRequired]);
                $plan->save();
            }

            return $approval;
        });

        // Ausführung erst nach Commit der Freigabe einplanen (Outbox-Muster, kein externer Aufruf in der Transaktion).
        if ($dispatch) {
            $this->dispatchExecution($version, $approver);
        }

        return $approval;
    }

    public function reject(ActionPlanVersion $version, User $approver, ?string $comment = null): Approval
    {
        $approval = new Approval;
        $approval->forceFill([
            'action_plan_version_id' => $version->getKey(),
            'approver_user_id' => $approver->getKey(),
            'decision' => 'rejected',
            'steps_hash' => $version->getAttribute('steps_hash'),
            'diff_hash' => $version->getAttribute('diff_hash'),
            'comment' => $comment !== null ? mb_substr($comment, 0, 500) : null,
        ]);
        $approval->save();

        $plan = $version->plan;

        if ($plan !== null) {
            $plan->forceFill(['status' => ActionStatus::ManualReview]);
            $plan->save();
            $this->audit->log('mail.action_plan.rejected', $plan, [], ['version_id' => $version->getKey(), 'approver' => $approver->getKey()], AuditSource::Mail->value, $this->correlation->current());
        }

        return $approval;
    }

    /**
     * Setzt einen Plan von validated auf approval_required (oder bei required_approvals = 0 direkt auf approved).
     */
    public function requestApproval(ActionPlanVersion $version, ?User $requestedBy = null): void
    {
        $plan = $version->plan;

        if ($plan === null) {
            return;
        }

        if ($this->policy->isFullyApproved($version)) {
            $plan->forceFill(['status' => ActionStatus::Approved]);
            $plan->save();
            $this->dispatchExecution($version, $requestedBy);

            return;
        }

        $plan->forceFill(['status' => ActionStatus::ApprovalRequired]);
        $plan->save();
    }

    private function dispatchExecution(ActionPlanVersion $version, ?User $actor): void
    {
        foreach ($version->steps() as $index => $step) {
            $this->bus->dispatch(ExecuteActionJob::forStep((int) $version->getKey(), (int) $index, $actor?->getKey(), $this->correlation->current()));
        }
    }
}
