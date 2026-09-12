<?php

declare(strict_types=1);

namespace App\Modules\Actions\Services;

use App\Modules\Actions\Enums\RiskClass;
use App\Modules\Actions\Exceptions\ActionPolicyException;
use App\Modules\Actions\Models\ActionPlanVersion;
use App\Modules\Actions\Models\Approval;
use App\Modules\Mail\Services\MailAccess;
use App\Modules\Security\Models\User;
use Illuminate\Contracts\Config\Repository;

/**
 * Freigabe-Policy im Service (nicht nur im Controller): Recht je Risikoklasse, keine Selbstfreigabe bei
 * required_approvals >= 2, Bankänderungen nur mit dokumentierter Identitätsprüfung und zwei verschiedenen
 * Freigebenden, Freigabe bindet an Version und diff_hash.
 */
final class ActionPolicy
{
    public function __construct(
        private readonly MailAccess $access,
        private readonly Repository $config,
    ) {}

    public function requiredApprovals(RiskClass $risk): int
    {
        $required = (array) $this->config->get('hub.actions.approvals.required', []);
        $count = (int) ($required[$risk->value] ?? 1);

        // Bankdaten verlangen immer zwei verschiedene Personen, unabhängig von der Konfiguration.
        return $risk === RiskClass::Bank ? max(2, $count) : $count;
    }

    public function requiresIdentityCheck(RiskClass $risk): bool
    {
        return $risk === RiskClass::Bank;
    }

    public function approvalTtlHours(): int
    {
        return max(1, (int) $this->config->get('hub.actions.approvals.ttl_hours', 72));
    }

    /**
     * @throws ActionPolicyException
     */
    public function assertCanApprove(ActionPlanVersion $version, User $user): void
    {
        $plan = $version->plan;

        if ($plan === null) {
            throw new ActionPolicyException('Planversion ohne Plan.', 'invalid_version');
        }

        if ($version->getAttribute('superseded_at') !== null || (int) $plan->getAttribute('current_version_id') !== (int) $version->getKey()) {
            throw new ActionPolicyException('Version ist nicht mehr aktuell, Freigabe nicht möglich.', 'stale_version');
        }

        $risk = $this->riskOf($version);
        $plan->setAttribute('risk_class', $risk);

        if (! $plan->relationLoaded('currentVersion')) {
            $plan->setRelation('currentVersion', $version);
        }

        if (! $this->access->canApprove($user, $plan)) {
            throw new ActionPolicyException(
                sprintf('Keine Freigabeberechtigung (%s) oder Freigabe der eigenen Version.', $risk->approvalPermission()),
                'approval_forbidden',
            );
        }

        $authorId = $version->getAttribute('author_user_id');

        if ($authorId !== null && (int) $authorId === (int) $user->getKey()) {
            throw new ActionPolicyException('Selbstfreigabe ist nicht zulässig.', 'self_approval');
        }

        if ((int) $version->getAttribute('required_approvals') >= 2 || $risk === RiskClass::Bank) {
            $creatorId = $plan->getAttribute('created_by');

            if ($creatorId !== null && (int) $creatorId === (int) $user->getKey()) {
                throw new ActionPolicyException('Ersteller des Plans darf bei mehrstufiger Freigabe nicht freigeben.', 'self_approval');
            }
        }

        $already = Approval::query()
            ->where('action_plan_version_id', $version->getKey())
            ->where('approver_user_id', $user->getKey())
            ->exists();

        if ($already) {
            throw new ActionPolicyException('Diese Person hat die Version bereits entschieden.', 'duplicate_approval');
        }

        if ($this->requiresIdentityCheck($risk) && ! $version->identityChecks()->exists()) {
            throw new ActionPolicyException(
                'Bankänderung ohne dokumentierte Identitätsprüfung (Kanal, prüfende Person, Zeitpunkt) kann nicht freigegeben werden.',
                'identity_check_missing',
            );
        }
    }

    /**
     * Gültige Freigaben der Version: entscheidung approved, Hash-Bindung, nicht abgelaufen, Re-Auth dokumentiert,
     * verschiedene Personen, keine davon Autor.
     *
     * @return array<int, Approval>
     */
    public function validApprovals(ActionPlanVersion $version): array
    {
        $authorId = $version->getAttribute('author_user_id');
        $creatorId = $version->plan?->getAttribute('created_by');
        $seen = [];
        $valid = [];

        /** @var Approval $approval */
        foreach ($version->approvals()->where('decision', 'approved')->orderBy('id')->get() as $approval) {
            $approverId = (int) $approval->getAttribute('approver_user_id');

            if ((string) $approval->getAttribute('steps_hash') !== (string) $version->getAttribute('steps_hash')) {
                continue;
            }

            if ((string) $approval->getAttribute('diff_hash') !== (string) $version->getAttribute('diff_hash')) {
                continue;
            }

            if ($approval->getAttribute('reauth_confirmed_at') === null || $approval->isExpired()) {
                continue;
            }

            if (($authorId !== null && $approverId === (int) $authorId) || isset($seen[$approverId])) {
                continue;
            }

            if ((int) $version->getAttribute('required_approvals') >= 2 && $creatorId !== null && $approverId === (int) $creatorId) {
                continue;
            }

            $seen[$approverId] = true;
            $valid[] = $approval;
        }

        return $valid;
    }

    public function isFullyApproved(ActionPlanVersion $version): bool
    {
        $required = (int) $version->getAttribute('required_approvals');

        if ($required <= 0) {
            return true;
        }

        if ($this->requiresIdentityCheck($this->riskOf($version)) && ! $version->identityChecks()->exists()) {
            return false;
        }

        return count($this->validApprovals($version)) >= $required;
    }

    public function riskOf(ActionPlanVersion $version): RiskClass
    {
        $risk = $version->getAttribute('risk_class');

        if ($risk instanceof RiskClass) {
            return $risk;
        }

        return RiskClass::tryFrom((string) $risk) ?? RiskClass::tryFrom((string) $version->plan?->getAttribute('risk_class')?->value) ?? RiskClass::Medium;
    }
}
