<?php

declare(strict_types=1);

namespace App\Modules\Mail\Support;

use App\Modules\Mail\Services\MailAccess;
use App\Modules\Security\Models\User;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Routing\Router;

/**
 * Navigation der Mail-Oberfläche aus config hub.mailui.navigation. Fehlende Routen erscheinen als "in Aufbau".
 */
final class MailNavigation
{
    public function __construct(
        private readonly Repository $config,
        private readonly Router $router,
        private readonly UrlGenerator $url,
        private readonly MailAccess $access,
    ) {}

    /**
     * @return array<int, array{key: string, label: string, url: ?string, available: bool, active: bool}>
     */
    public function items(?User $user, ?string $currentRoute): array
    {
        $items = [];

        foreach ((array) $this->config->get('hub.mailui.navigation', []) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $permission = $item['permission'] ?? null;

            if (is_string($permission) && ($user === null || ! $this->access->hasGlobalPermission($user, $permission))) {
                continue;
            }

            $route = (string) ($item['route'] ?? '');
            $available = $route !== '' && $this->router->has($route);
            $prefix = substr($route, 0, (int) strrpos($route, '.'));

            $items[] = [
                'key' => (string) ($item['key'] ?? $route),
                'label' => (string) ($item['label'] ?? $route),
                'url' => $available ? $this->url->route($route) : null,
                'available' => $available,
                'active' => $currentRoute !== null && ($currentRoute === $route || ($prefix !== '' && $prefix !== 'mail' && str_starts_with($currentRoute, $prefix.'.'))),
            ];
        }

        return $items;
    }
}
