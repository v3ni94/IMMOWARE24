<?php

declare(strict_types=1);

namespace App\Modules\Security\Policies;

use App\Core\Enums\Role;
use App\Modules\Security\Models\User;
use App\Modules\Security\Services\PermissionMap;

/**
 * Nutzerverwaltung: admin verwaltet nur operator und read_only, die Rollen administrator und owner
 * vergibt ausschließlich der Owner. Niemand ändert die eigene Rolle (08-security.md Abschnitt 4).
 */
final class UserPolicy
{
    public function __construct(private readonly PermissionMap $permissions) {}

    public function viewAny(User $actor): bool
    {
        return $this->permissions->allows($actor->role, 'users.manage');
    }

    public function view(User $actor, User $target): bool
    {
        return $actor->is($target) || ($this->sameOrganization($actor, $target) && $this->viewAny($actor));
    }

    public function create(User $actor): bool
    {
        return $this->permissions->allows($actor->role, 'users.manage');
    }

    public function update(User $actor, User $target): bool
    {
        return $this->sameOrganization($actor, $target)
            && $this->permissions->allows($actor->role, 'users.manage')
            && $this->canManageRole($actor, $target->role);
    }

    public function assignRole(User $actor, User $target, Role $newRole): bool
    {
        if ($actor->is($target)) {
            return false;
        }

        return $this->update($actor, $target) && $this->canManageRole($actor, $newRole);
    }

    public function disable(User $actor, User $target): bool
    {
        return ! $actor->is($target) && $this->update($actor, $target);
    }

    public function canManageRole(User $actor, Role $role): bool
    {
        if ($actor->role === Role::Owner) {
            return true;
        }

        if ($actor->role === Role::Administrator) {
            return in_array($role, [Role::Operator, Role::ReadOnly, Role::Developer], true);
        }

        return false;
    }

    private function sameOrganization(User $actor, User $target): bool
    {
        return $actor->getAttribute('organization_id') !== null
            && (int) $actor->getAttribute('organization_id') === (int) $target->getAttribute('organization_id');
    }
}
