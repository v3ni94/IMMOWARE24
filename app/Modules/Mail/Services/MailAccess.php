<?php

declare(strict_types=1);

namespace App\Modules\Mail\Services;

use App\Core\Enums\Role;
use App\Modules\Actions\Enums\RiskClass;
use App\Modules\Actions\Models\ActionPlan;
use App\Modules\Actions\Models\Approval;
use App\Modules\Mail\Models\Mailbox;
use App\Modules\Mail\Models\MailboxPermission;
use App\Modules\Mail\Models\TeamMember;
use App\Modules\Security\Models\User;
use App\Modules\Security\Services\PermissionMap;
use Illuminate\Contracts\Config\Repository;

/**
 * Zugriffsentscheidungen des Mail-Moduls (docs/mail/05-rollen-und-rechte.md). Zwei Ebenen: globales Recht der
 * Systemrolle (PermissionMap, hub.security) und Team-Rolle (hub.mail.team_roles). Postfachinhalte zusätzlich nur
 * mit Zeile in mail_mailbox_permissions. Vier-Augen: Freigebende Person ist nie Autor der Version, Freigabe bindet
 * an steps_hash.
 */
final class MailAccess
{
    public const array TEAM_ROLES = ['admin', 'lead', 'agent', 'approver', 'auditor'];

    public function __construct(
        private readonly PermissionMap $permissions,
        private readonly Repository $config,
    ) {}

    /**
     * Globales Recht der Systemrolle (Obergrenze). Owner erhält alles.
     */
    public function hasGlobalPermission(User $user, string $permission): bool
    {
        if ($user->isDisabled() || $user->isLocked()) {
            return false;
        }

        return $this->permissions->allows($user->role, $permission);
    }

    /**
     * @return array<int, string>
     */
    public function permissionsForTeamRole(string $teamRole): array
    {
        $bundle = $this->config->get('hub.mail.team_roles.'.$teamRole, []);

        return is_array($bundle) ? array_values(array_map('strval', $bundle)) : [];
    }

    /**
     * Team-Rollen des Nutzers, optional eingeschränkt auf ein Team. Abgelaufene Mitgliedschaften zählen nicht.
     *
     * @return array<int, string>
     */
    public function teamRoles(User $user, ?int $teamId = null): array
    {
        $today = now()->toDateString();

        return TeamMember::query()
            ->where('user_id', $user->getKey())
            ->when($teamId !== null, static fn ($q) => $q->where('team_id', $teamId))
            ->where(static fn ($q) => $q->whereNull('active_from')->orWhere('active_from', '<=', $today))
            ->where(static fn ($q) => $q->whereNull('active_until')->orWhere('active_until', '>=', $today))
            ->pluck('team_role')
            ->map(static fn (mixed $role): string => (string) $role)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Recht über Team-Rolle. Owner und Administrator brauchen keine Teammitgliedschaft für Verwaltungsrechte,
     * für Postfachinhalte aber weiterhin eine Postfachfreigabe (canViewMailbox).
     */
    public function hasTeamPermission(User $user, string $permission, ?int $teamId = null): bool
    {
        if ($user->hasRole(Role::Owner, Role::Administrator)) {
            return true;
        }

        foreach ($this->teamRoles($user, $teamId) as $role) {
            if (in_array($permission, $this->permissionsForTeamRole($role), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Beide Ebenen müssen zustimmen.
     */
    public function can(User $user, string $permission, ?int $teamId = null): bool
    {
        return $this->hasGlobalPermission($user, $permission) && $this->hasTeamPermission($user, $permission, $teamId);
    }

    public function mailboxPermission(User $user, Mailbox $mailbox): ?MailboxPermission
    {
        return MailboxPermission::query()
            ->where('mailbox_id', $mailbox->getKey())
            ->where('user_id', $user->getKey())
            ->first();
    }

    /**
     * Postfachinhalte: globales Recht mail.inbox.view plus Zeile mit can_read. Ohne Zeile kein Zugriff, auch nicht
     * für Administratoren (Verwaltung ohne Inhalte).
     */
    public function canViewMailbox(User $user, Mailbox $mailbox): bool
    {
        if (! $this->hasGlobalPermission($user, 'mail.inbox.view')) {
            return false;
        }

        if ((int) $user->getAttribute('organization_id') !== (int) $mailbox->getAttribute('organization_id')) {
            return false;
        }

        $permission = $this->mailboxPermission($user, $mailbox);

        return $permission !== null && (bool) $permission->can_read;
    }

    public function canViewBankData(User $user, Mailbox $mailbox): bool
    {
        if (! $this->can($user, 'mail.bank_data.view', $mailbox->team_id === null ? null : (int) $mailbox->team_id)) {
            return false;
        }

        $permission = $this->mailboxPermission($user, $mailbox);

        return $permission !== null && (bool) $permission->can_view_bank_data;
    }

    public function canSendFromMailbox(User $user, Mailbox $mailbox): bool
    {
        if (! $this->can($user, 'mail.send', $mailbox->team_id === null ? null : (int) $mailbox->team_id)) {
            return false;
        }

        $permission = $this->mailboxPermission($user, $mailbox);

        return $permission !== null && (bool) $permission->can_send;
    }

    /**
     * Freigabe eines Aktionsplans: Recht je Risikoklasse (mail.approve.standard oder mail.approve.bank) auf beiden
     * Ebenen, gleiche Organisation, und nie Autor der aktuellen Version.
     */
    public function canApprove(User $user, ActionPlan $plan): bool
    {
        $risk = $plan->risk_class instanceof RiskClass ? $plan->risk_class : RiskClass::tryFrom((string) $plan->getAttribute('risk_class'));

        if ($risk === null) {
            return false;
        }

        if ((int) $user->getAttribute('organization_id') !== (int) $plan->getAttribute('organization_id')) {
            return false;
        }

        $teamId = $plan->case?->team_id;

        if (! $this->can($user, $risk->approvalPermission(), $teamId === null ? null : (int) $teamId)) {
            return false;
        }

        $version = $plan->currentVersion;

        if ($version === null) {
            return false;
        }

        return (int) $version->getAttribute('author_user_id') !== (int) $user->getKey();
    }

    /**
     * Vier-Augen erfüllt: aktuelle Version hat eine Freigabe (decision approved) einer anderen Person als dem Autor,
     * der steps_hash der Freigabe entspricht dem der Version und die Re-Authentifizierung ist dokumentiert.
     */
    public function fourEyesSatisfied(ActionPlan $plan): bool
    {
        $version = $plan->currentVersion;

        if ($version === null) {
            return false;
        }

        $authorId = $version->getAttribute('author_user_id');

        return Approval::query()
            ->where('action_plan_version_id', $version->getKey())
            ->where('decision', 'approved')
            ->where('steps_hash', (string) $version->getAttribute('steps_hash'))
            ->whereNotNull('reauth_confirmed_at')
            ->when($authorId !== null, static fn ($q) => $q->where('approver_user_id', '!=', (int) $authorId))
            ->exists();
    }
}
