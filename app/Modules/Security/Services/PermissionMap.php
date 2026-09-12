<?php

declare(strict_types=1);

namespace App\Modules\Security\Services;

use App\Core\Enums\Role;

/**
 * Feingranulare Rechte je Rolle aus config/hub/security.php.
 */
final class PermissionMap
{
    /** @var array<string, array<int, string>> */
    private readonly array $map;

    /** @var array<int, string> */
    private readonly array $catalog;

    /**
     * @param  array<string, array<int, string>>|null  $map
     * @param  array<int, string>|null  $catalog
     */
    public function __construct(?array $map = null, ?array $catalog = null)
    {
        $this->map = $map ?? (array) config('hub.security.permissions', []);
        $this->catalog = $catalog ?? (array) config('hub.security.permission_catalog', []);
    }

    public function allows(Role $role, string $permission): bool
    {
        $granted = $this->map[$role->value] ?? [];

        return in_array('*', $granted, true) || in_array($permission, $granted, true);
    }

    /**
     * @return array<int, string>
     */
    public function permissionsFor(Role $role): array
    {
        $granted = $this->map[$role->value] ?? [];

        return in_array('*', $granted, true) ? $this->allPermissions() : array_values($granted);
    }

    /**
     * Alle bekannten Rechte aus Katalog und Rollenzuordnung, ohne Wildcard.
     *
     * @return array<int, string>
     */
    public function allPermissions(): array
    {
        $all = array_fill_keys($this->catalog, true);

        foreach ($this->map as $permissions) {
            foreach ($permissions as $permission) {
                if ($permission !== '*') {
                    $all[$permission] = true;
                }
            }
        }

        return array_keys($all);
    }
}
