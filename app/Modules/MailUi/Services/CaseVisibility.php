<?php

declare(strict_types=1);

namespace App\Modules\MailUi\Services;

use App\Modules\Cases\Models\MailCase;
use App\Modules\Mail\Models\Mailbox;
use App\Modules\Mail\Models\MailboxPermission;
use App\Modules\Mail\Models\TeamMember;
use App\Modules\Mail\Services\MailAccess;
use App\Modules\Security\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Sichtbarkeit von Vorgängen in der Oberfläche: Inhalte eines Postfachs nur mit Postfachrecht can_read
 * (MailAccess::canViewMailbox), Vorgänge ohne Postfach nur für Mitglieder des Teams. Kein Zugriff über Namen.
 */
final class CaseVisibility
{
    public function __construct(private readonly MailAccess $access) {}

    /**
     * @return array<int, int>
     */
    public function readableMailboxIds(User $user): array
    {
        if (! $this->access->hasGlobalPermission($user, 'mail.inbox.view')) {
            return [];
        }

        return MailboxPermission::query()
            ->where('user_id', $user->getKey())
            ->where('can_read', true)
            ->whereIn('mailbox_id', Mailbox::query()->where('organization_id', $user->getAttribute('organization_id'))->select('id'))
            ->pluck('mailbox_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * @return array<int, int>
     */
    public function teamIds(User $user): array
    {
        $today = now()->toDateString();

        return TeamMember::query()
            ->where('user_id', $user->getKey())
            ->where(static fn (Builder $q) => $q->whereNull('active_from')->orWhere('active_from', '<=', $today))
            ->where(static fn (Builder $q) => $q->whereNull('active_until')->orWhere('active_until', '>=', $today))
            ->pluck('team_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * @param  Builder<MailCase>  $query
     * @return Builder<MailCase>
     */
    public function scopeVisible(Builder $query, User $user): Builder
    {
        $mailboxIds = $this->readableMailboxIds($user);
        $teamIds = $this->teamIds($user);

        return $query->where(static function (Builder $q) use ($mailboxIds, $teamIds): void {
            $q->whereIn('mailbox_id', $mailboxIds === [] ? [0] : $mailboxIds)
                ->orWhere(static fn (Builder $inner) => $inner->whereNull('mailbox_id')->whereIn('team_id', $teamIds === [] ? [0] : $teamIds));
        });
    }

    public function canView(User $user, MailCase $case): bool
    {
        if ((int) $case->getAttribute('organization_id') !== (int) $user->getAttribute('organization_id')) {
            return false;
        }

        $mailbox = $case->loadMissing('mailbox')->mailbox;

        if ($mailbox instanceof Mailbox) {
            return $this->access->canViewMailbox($user, $mailbox);
        }

        return $case->team_id !== null && in_array((int) $case->team_id, $this->teamIds($user), true);
    }

    public function mailboxPermission(User $user, MailCase $case): ?MailboxPermission
    {
        $mailbox = $case->loadMissing('mailbox')->mailbox;

        return $mailbox instanceof Mailbox ? $this->access->mailboxPermission($user, $mailbox) : null;
    }

    public function canAssign(User $user, MailCase $case): bool
    {
        if (! $this->access->can($user, 'mail.case.assign', $case->team_id === null ? null : (int) $case->team_id)) {
            return false;
        }

        $permission = $this->mailboxPermission($user, $case);

        return $case->loadMissing('mailbox')->mailbox === null || ($permission !== null && (bool) $permission->can_assign);
    }

    public function canDraft(User $user, MailCase $case): bool
    {
        $permission = $this->mailboxPermission($user, $case);

        return $permission !== null && (bool) $permission->can_draft && $this->access->hasGlobalPermission($user, 'mail.inbox.view');
    }

    public function canSend(User $user, MailCase $case): bool
    {
        $mailbox = $case->loadMissing('mailbox')->mailbox;

        return $mailbox instanceof Mailbox && $this->access->canSendFromMailbox($user, $mailbox);
    }

    public function canViewBankData(User $user, MailCase $case): bool
    {
        $mailbox = $case->loadMissing('mailbox')->mailbox;

        return $mailbox instanceof Mailbox && $this->access->canViewBankData($user, $mailbox);
    }
}
