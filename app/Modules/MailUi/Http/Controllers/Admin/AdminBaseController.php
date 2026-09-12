<?php

declare(strict_types=1);

namespace App\Modules\MailUi\Http\Controllers\Admin;

use App\Modules\MailUi\Http\Controllers\MailUiController;
use App\Modules\Security\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Basis der Administrationsseiten (Recht mail.admin, Systemrolle Administrator oder Owner).
 */
abstract class AdminBaseController extends MailUiController
{
    protected function requireAdmin(Request $request): User
    {
        $user = $this->currentUser($request);
        $this->requirePermission($user, 'mail.admin');

        return $user;
    }

    protected function organizationId(User $user): int
    {
        return (int) $user->getAttribute('organization_id');
    }

    /**
     * @return Collection<int, User>
     */
    protected function organizationUsers(User $user): Collection
    {
        $query = User::query()->where('organization_id', $this->organizationId($user));
        $query->whereNull('disabled_at')->orderBy('name');

        return $query->get();
    }
}
