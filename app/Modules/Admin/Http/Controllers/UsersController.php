<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Core\Enums\Role;
use App\Modules\Admin\Http\Requests\StoreUserRequest;
use App\Modules\Admin\Http\Requests\UpdateUserRequest;
use App\Modules\Security\Models\User;
use App\Modules\Security\Services\TwoFactorService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Benutzer: Liste, Anlage und Bearbeitung (Rolle, aktiv/deaktiviert), 2FA-Status, 2FA zurücksetzen mit
 * Bestätigung, Sperre aufheben. Rollenvergabe nach docs/immoware/08-security.md Abschnitt 5: Administrator
 * vergibt nur Operator und Nur Lesen, Owner vergibt alle Rollen; niemand ändert die eigene Rolle.
 */
final class UsersController extends AdminController
{
    public function __construct(private readonly TwoFactorService $twoFactor) {}

    public function index(Request $request): View
    {
        $this->requirePermission('users.manage');

        $role = (string) $request->query('role', '');
        $state = (string) $request->query('state', '');
        $search = trim((string) $request->query('q', ''));

        $users = User::query()
            ->when($role !== '' && Role::tryFrom($role) !== null, static fn (Builder $q) => $q->where('role', $role))
            ->when($state === 'active', static fn (Builder $q) => $q->whereNull('disabled_at'))
            ->when($state === 'disabled', static fn (Builder $q) => $q->whereNotNull('disabled_at'))
            ->when($state === 'locked', static fn (Builder $q) => $q->where('locked_until', '>', now()))
            ->when($search !== '', static fn (Builder $q) => $q->where(static function (Builder $inner) use ($search): void {
                $inner->where('name', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%');
            }))
            ->orderBy('name')
            ->paginate($this->perPage())
            ->withQueryString();

        return view('admin::users.index', [
            'users' => $users,
            'roles' => Role::cases(),
            'filter' => ['role' => $role, 'state' => $state, 'q' => $search],
            'assignable' => $this->assignableRoles($this->currentUser($request)),
        ]);
    }

    public function create(Request $request): View
    {
        $this->requirePermission('users.manage');

        return view('admin::users.create', ['assignable' => $this->assignableRoles($this->currentUser($request))]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $this->requirePermission('users.manage');
        $actor = $this->currentUser($request);
        $data = $request->validated();
        $role = Role::from((string) $data['role']);
        $this->assertAssignable($actor, $role);

        $email = mb_strtolower(trim((string) $data['email']));

        if (User::query()->allOrganizations()->where('email', $email)->exists()) {
            return redirect()->route('admin.users.create')->withErrors(['email' => 'Diese E-Mail-Adresse ist bereits vergeben.'])->withInput();
        }

        $user = User::query()->create([
            'organization_id' => (int) $actor->getAttribute('organization_id'),
            'name' => (string) $data['name'],
            'email' => $email,
            'password' => (string) $data['password'],
            'role' => $role,
        ]);

        $this->audit('users.created', $user, [], ['name' => $user->name, 'email' => $user->email, 'role' => $role->value]);

        return $this->redirectWithStatus('admin.users.index', sprintf('Benutzer %s angelegt. Die Zwei-Faktor-Einrichtung erfolgt bei der ersten Anmeldung.', $user->name));
    }

    public function edit(Request $request, int $user): View
    {
        $this->requirePermission('users.manage');
        $user = $this->findUser($user);
        $actor = $this->currentUser($request);

        return view('admin::users.edit', [
            'user' => $user,
            'assignable' => $this->assignableRoles($actor),
            'isSelf' => (int) $actor->getKey() === (int) $user->getKey(),
            'canChangeRole' => (int) $actor->getKey() !== (int) $user->getKey() && $this->assignableRoles($actor)->contains($user->role),
            'remainingRecoveryCodes' => $this->twoFactor->remainingRecoveryCodes($user),
        ]);
    }

    public function update(UpdateUserRequest $request, int $user): RedirectResponse
    {
        $this->requirePermission('users.manage');
        $user = $this->findUser($user);
        $actor = $this->currentUser($request);
        $data = $request->validated();
        $isSelf = (int) $actor->getKey() === (int) $user->getKey();

        $before = ['name' => $user->name, 'role' => $user->role->value, 'disabled_at' => $user->disabled_at?->toIso8601String()];
        $changes = ['name' => (string) $data['name']];

        if (isset($data['role']) && $data['role'] !== '' && $data['role'] !== $user->role->value) {
            if ($isSelf) {
                abort(403, 'Die eigene Rolle kann nicht geändert werden.');
            }

            $newRole = Role::from((string) $data['role']);
            $this->assertAssignable($actor, $newRole);
            // Der Zielnutzer muss ebenfalls in der Vergabekompetenz des Akteurs liegen (kein Herabstufen eines Owners durch einen Administrator).
            $this->assertAssignable($actor, $user->role);
            $changes['role'] = $newRole;
        }

        $disable = (bool) ($data['disabled'] ?? false);

        if ($disable && $isSelf) {
            abort(403, 'Das eigene Konto kann nicht deaktiviert werden.');
        }

        if ($disable !== $user->isDisabled()) {
            if ($disable) {
                $this->assertAssignable($actor, $user->role);
            }

            $changes['disabled_at'] = $disable ? now()->toImmutable() : null;
        }

        if (isset($data['password']) && $data['password'] !== '') {
            $changes['password'] = (string) $data['password'];
        }

        $user->forceFill($changes)->save();
        $user->refresh();

        $after = ['name' => $user->name, 'role' => $user->role->value, 'disabled_at' => $user->disabled_at?->toIso8601String(), 'password_changed' => isset($changes['password'])];
        $this->audit('users.updated', $user, $before, $after);

        return $this->redirectWithStatus('admin.users.index', sprintf('Benutzer %s aktualisiert.', $user->name));
    }

    public function resetTwoFactor(Request $request, int $user): RedirectResponse
    {
        $this->requirePermission('users.manage');
        $this->requireConfirmation($request);
        $user = $this->findUser($user);
        $actor = $this->currentUser($request);
        $this->assertAssignable($actor, $user->role);

        $before = ['totp_confirmed_at' => $user->totp_confirmed_at?->toIso8601String(), 'recovery_codes' => $this->twoFactor->remainingRecoveryCodes($user)];
        $this->twoFactor->disable($user, $request);

        $this->audit('users.two_factor_reset', $user, $before, ['totp_confirmed_at' => null, 'reason' => (string) $request->input('reason', '')]);

        return $this->redirectWithStatus('admin.users.edit', 'Zwei-Faktor-Authentifizierung zurückgesetzt. Der Benutzer richtet sie bei der nächsten Anmeldung neu ein.', ['user' => $user->getKey()]);
    }

    public function unlock(Request $request, int $user): RedirectResponse
    {
        $this->requirePermission('users.manage');
        $user = $this->findUser($user);

        if (! $user->isLocked() && (int) $user->failed_login_count === 0) {
            return $this->redirectWithWarning('admin.users.edit', 'Das Konto ist nicht gesperrt.', ['user' => $user->getKey()]);
        }

        $before = ['locked_until' => $user->locked_until?->toIso8601String(), 'failed_login_count' => (int) $user->failed_login_count];
        $user->forceFill(['locked_until' => null, 'failed_login_count' => 0])->save();

        $this->audit('users.unlocked', $user, $before, ['locked_until' => null, 'failed_login_count' => 0]);

        return $this->redirectWithStatus('admin.users.edit', 'Kontosperre aufgehoben.', ['user' => $user->getKey()]);
    }

    /**
     * Explizite Suche im eigenen Mandanten statt Route-Model-Binding (Kontext wird erst in admin.access gesetzt).
     */
    private function findUser(int $id): User
    {
        /** @var User $user */
        $user = User::query()->whereKey($id)->firstOrFail();

        return $user;
    }

    /**
     * Rollen, die der Akteur vergeben darf (08-security.md Abschnitt 5).
     *
     * @return Collection<int, Role>
     */
    private function assignableRoles(User $actor): Collection
    {
        return collect(match ($actor->role) {
            Role::Owner => [Role::Owner, Role::Administrator, Role::Developer, Role::Operator, Role::ReadOnly],
            Role::Administrator => [Role::Operator, Role::ReadOnly],
            default => [],
        });
    }

    private function assertAssignable(User $actor, Role $role): void
    {
        if (! $this->assignableRoles($actor)->contains($role)) {
            abort(403, sprintf('Die Rolle %s darf von Ihrer Rolle nicht vergeben oder verwaltet werden.', $role->label()));
        }
    }
}
