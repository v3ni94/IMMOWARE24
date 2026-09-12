<?php

declare(strict_types=1);

namespace App\Modules\Drive\Services;

use App\Modules\Cases\Models\MailCase;
use App\Modules\Mail\Models\Mailbox;
use App\Modules\Mail\Services\MailAccess;
use App\Modules\Security\Models\User;

/**
 * Doppelte Rechteprüfung für Dokumentauszüge. (1) Anwendungsrecht: die Person darf den Vorgang sehen (gleiche
 * Organisation, mail.inbox.view, Postfachfreigabe oder Teamzuständigkeit oder Zuweisung). (2) Quellrecht: die
 * Person ist in Drive für die Datei berechtigt (permissions.list enthält ihre E-Mail, ihre Domain oder anyone).
 * Beide Prüfungen müssen zustimmen; sonst gibt es keinen Auszug, weder über Suche noch im KI-Kontext.
 */
final class DriveAccessGuard
{
    public function __construct(private readonly MailAccess $access) {}

    public function canAccessCase(User $user, MailCase $case): bool
    {
        if ((int) $user->getAttribute('organization_id') !== (int) $case->getAttribute('organization_id')) {
            return false;
        }

        if (! $this->access->hasGlobalPermission($user, 'mail.inbox.view')) {
            return false;
        }

        if ((int) $case->getAttribute('assignee_user_id') === (int) $user->getKey() && $case->getAttribute('assignee_user_id') !== null) {
            return true;
        }

        $mailboxId = $case->getAttribute('mailbox_id');

        if ($mailboxId !== null) {
            $mailbox = Mailbox::query()->withoutGlobalScopes()->find((int) $mailboxId);

            if ($mailbox instanceof Mailbox && $this->access->canViewMailbox($user, $mailbox)) {
                return true;
            }
        }

        $teamId = $case->getAttribute('team_id');

        if ($teamId !== null) {
            return $this->access->teamRoles($user, (int) $teamId) !== [];
        }

        return false;
    }

    /**
     * @param  array<int, array{type: string, role: string, email: ?string, domain?: ?string}>  $permissions
     */
    public function hasDrivePermission(User $user, array $permissions): bool
    {
        $email = strtolower(trim((string) $user->getAttribute('email')));
        $domain = $email !== '' && str_contains($email, '@') ? substr($email, strrpos($email, '@') + 1) : '';

        foreach ($permissions as $permission) {
            $type = $permission['type'] ?? '';

            if ($type === 'anyone') {
                return true;
            }

            if ($type === 'user' && $email !== '' && ($permission['email'] ?? null) === $email) {
                return true;
            }

            if ($type === 'domain' && $domain !== '' && ($permission['domain'] ?? null) === $domain) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, array{type: string, role: string, email: ?string, domain?: ?string}>  $permissions
     */
    public function canReadExcerpt(User $user, MailCase $case, array $permissions): bool
    {
        return $this->canAccessCase($user, $case) && $this->hasDrivePermission($user, $permissions);
    }
}
