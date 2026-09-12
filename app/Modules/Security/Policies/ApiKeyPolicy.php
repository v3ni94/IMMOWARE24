<?php

declare(strict_types=1);

namespace App\Modules\Security\Policies;

use App\Modules\Security\Models\ApiKey;
use App\Modules\Security\Models\User;
use App\Modules\Security\Services\PermissionMap;

final class ApiKeyPolicy
{
    public function __construct(private readonly PermissionMap $permissions) {}

    public function viewAny(User $actor): bool
    {
        return $this->permissions->allows($actor->role, 'api_keys.manage');
    }

    public function view(User $actor, ApiKey $apiKey): bool
    {
        return $this->viewAny($actor) && $this->sameOrganization($actor, $apiKey);
    }

    public function create(User $actor): bool
    {
        return $this->permissions->allows($actor->role, 'api_keys.manage');
    }

    public function revoke(User $actor, ApiKey $apiKey): bool
    {
        return $this->view($actor, $apiKey);
    }

    private function sameOrganization(User $actor, ApiKey $apiKey): bool
    {
        return (int) $actor->getAttribute('organization_id') === (int) $apiKey->getAttribute('organization_id');
    }
}
