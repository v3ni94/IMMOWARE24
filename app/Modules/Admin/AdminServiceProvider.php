<?php

declare(strict_types=1);

namespace App\Modules\Admin;

use App\Modules\Admin\Http\Middleware\EnsureAdminAccess;
use App\Modules\Admin\Support\Navigation;
use Illuminate\Contracts\View\View;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Modul Admin: Blade-Oberfläche unter /admin (Routennamen admin.*), View-Namespace admin::,
 * anonyme Komponenten x-admin.* aus resources/views/components/admin (Standardpfad), Middleware-Gruppe admin.
 */
class AdminServiceProvider extends ServiceProvider
{
    public const string MODULE = 'admin';

    public const string ROUTE_PREFIX = 'admin';

    public const string MIDDLEWARE_GROUP = 'admin';

    public function register(): void
    {
        $config = base_path('config/hub/'.self::MODULE.'.php');

        if (is_file($config)) {
            $this->mergeConfigFrom($config, 'hub.'.self::MODULE);
        }

        $this->app->singleton(Navigation::class);
    }

    public function boot(): void
    {
        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('admin.access', EnsureAdminAccess::class);
        // Reihenfolge: Session (web), Anmeldung, Rollen- und Kontoprüfung mit Mandantenkontext (api_client erhält 403), danach 2FA.
        $router->middlewareGroup(self::MIDDLEWARE_GROUP, ['web', 'auth', 'admin.access', '2fa']);

        $routes = base_path('routes/modules/'.self::MODULE.'.php');

        if (is_file($routes)) {
            Route::middleware(self::MIDDLEWARE_GROUP)
                ->prefix(self::ROUTE_PREFIX)
                ->name(self::ROUTE_PREFIX.'.')
                ->group($routes);
        }

        $views = resource_path('views/'.self::MODULE);

        if (is_dir($views)) {
            $this->loadViewsFrom($views, self::MODULE);
        }

        $this->registerViewComposers();
    }

    private function registerViewComposers(): void
    {
        $this->app->make('view')->composer('layouts.admin', function (View $view): void {
            $request = $this->app->make('request');
            $user = $request->user();
            $routeName = $request->route()?->getName();

            $view->with('navigation', $this->app->make(Navigation::class)->items($user, is_string($routeName) ? $routeName : null));
            $view->with('environment', (string) config('app.env', 'production'));
            $view->with('productName', (string) config('hub.admin.product_name', 'Immoware Hub'));
            $view->with('operatorName', (string) config('hub.admin.operator', ''));
        });
    }
}
