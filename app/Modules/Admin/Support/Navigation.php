<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support;

use App\Modules\Security\Models\User;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Routing\Router;

/**
 * Baut die linke Navigation aus config/hub/admin.php. Bereiche ohne registrierte Route erscheinen
 * als "in Aufbau" ohne Link; Bereiche ohne Recht oder ohne passende Rolle (Schlüssel roles) werden ausgeblendet.
 */
final class Navigation
{
    public function __construct(
        private readonly Router $router,
        private readonly UrlGenerator $url,
        private readonly Gate $gate,
    ) {}

    /**
     * @return array<int, array{key: string, label: string, url: string|null, active: bool, available: bool}>
     */
    public function items(?User $user, ?string $currentRouteName): array
    {
        $items = [];

        foreach ((array) config('hub.admin.navigation', []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $permission = $entry['permission'] ?? null;

            if (is_string($permission) && ($user === null || ! $this->gate->forUser($user)->allows($permission))) {
                continue;
            }

            $roles = $entry['roles'] ?? null;

            if (is_array($roles) && $roles !== [] && ($user === null || ! in_array($user->role->value, $roles, true))) {
                continue;
            }

            $route = (string) ($entry['route'] ?? '');
            $available = $route !== '' && $this->router->has($route);
            $prefix = $this->prefix($route);

            $items[] = [
                'key' => (string) ($entry['key'] ?? $route),
                'label' => (string) ($entry['label'] ?? $route),
                'url' => $available ? $this->url->route($route) : null,
                'active' => $currentRouteName !== null && ($currentRouteName === $route || ($prefix !== null && str_starts_with($currentRouteName, $prefix))),
                'available' => $available,
            ];
        }

        return $items;
    }

    /**
     * admin.connections.index wird zu admin.connections., damit Unterseiten den Bereich aktiv halten.
     */
    private function prefix(string $route): ?string
    {
        $parts = explode('.', $route);

        return count($parts) >= 3 ? $parts[0].'.'.$parts[1].'.' : null;
    }
}
