<?php

declare(strict_types=1);

namespace App\Modules\Security\Policies;

use App\Core\Enums\Role;
use App\Modules\Security\Models\User;
use App\Modules\Security\Services\PermissionMap;

/**
 * Audit lesen dürfen alle Rollen mit audit.view; before_json und after_json sieht read_only nicht.
 */
final class AuditLogPolicy
{
    public function __construct(private readonly PermissionMap $permissions) {}

    public function viewAny(User $actor): bool
    {
        return $this->permissions->allows($actor->role, 'audit.view');
    }

    public function viewPayload(User $actor): bool
    {
        return $this->viewAny($actor) && $actor->role !== Role::ReadOnly;
    }
}
