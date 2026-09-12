<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Core\Enums\Role;
use App\Modules\Security\Models\User;
use App\Modules\Security\Services\PermissionMap;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Rollenübersicht als Matrix Rolle × Recht aus config/hub/security.php, nur lesend.
 */
final class RolesController extends AdminController
{
    public function __construct(private readonly PermissionMap $permissions) {}

    public function index(Request $request): View
    {
        $this->requirePermission('users.manage');

        $roles = Role::cases();
        $catalog = $this->permissions->allPermissions();
        $matrix = [];

        foreach ($roles as $role) {
            foreach ($catalog as $permission) {
                $matrix[$role->value][$permission] = $this->permissions->allows($role, $permission);
            }
        }

        $counts = User::query()->selectRaw('role, count(*) as aggregate')->groupBy('role')->pluck('aggregate', 'role')->all();

        return view('admin::roles.index', [
            'roles' => $roles,
            'catalog' => $catalog,
            'matrix' => $matrix,
            'counts' => $counts,
            'exemptRoles' => (array) config('hub.security.totp.exempt_roles', []),
        ]);
    }
}
