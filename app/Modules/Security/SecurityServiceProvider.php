<?php

declare(strict_types=1);

namespace App\Modules\Security;

use App\Core\Contracts\AuditLoggerInterface;
use App\Core\Enums\Role;
use App\Modules\Security\Console\AuditAnchorCommand;
use App\Modules\Security\Console\AuditVerifyCommand;
use App\Modules\Security\Console\CreateApiKeyCommand;
use App\Modules\Security\Console\CreateUserCommand;
use App\Modules\Security\Http\Middleware\AuthenticateApiKey;
use App\Modules\Security\Http\Middleware\EnforceAbsoluteSessionLifetime;
use App\Modules\Security\Http\Middleware\RequireFreshTwoFactor;
use App\Modules\Security\Http\Middleware\RequireScope;
use App\Modules\Security\Http\Middleware\RequireTwoFactor;
use App\Modules\Security\Http\Middleware\ThrottleApiKey;
use App\Modules\Security\Models\ApiKey;
use App\Modules\Security\Models\AuditLog;
use App\Modules\Security\Models\User;
use App\Modules\Security\Policies\ApiKeyPolicy;
use App\Modules\Security\Policies\AuditLogPolicy;
use App\Modules\Security\Policies\UserPolicy;
use App\Modules\Security\Services\AuditLogger;
use App\Modules\Security\Services\LoginService;
use App\Modules\Security\Services\PepperedHasher;
use App\Modules\Security\Services\PermissionMap;
use App\Modules\Security\Services\Totp;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class SecurityServiceProvider extends ServiceProvider
{
    public const string MODULE = 'security';

    public function register(): void
    {
        $config = base_path('config/hub/'.self::MODULE.'.php');

        if (is_file($config)) {
            $this->mergeConfigFrom($config, 'hub.'.self::MODULE);
        }

        $this->app->singleton(PepperedHasher::class);
        $this->app->singleton(PermissionMap::class);
        $this->app->singleton(AuditLogger::class);
        $this->app->bind(AuditLoggerInterface::class, AuditLogger::class);
        $this->app->bind(Totp::class, static fn (): Totp => Totp::fromConfig());
        $this->app->when([LoginService::class, EnforceAbsoluteSessionLifetime::class])
            ->needs(StatefulGuard::class)
            ->give(static function (Application $app): StatefulGuard {
                $guard = $app->make(AuthFactory::class)->guard('web');

                if (! $guard instanceof StatefulGuard) {
                    throw new RuntimeException('Der Guard web muss zustandsbehaftet sein (SessionGuard).');
                }

                return $guard;
            });
    }

    public function boot(): void
    {
        $routes = base_path('routes/modules/'.self::MODULE.'.php');

        if (is_file($routes)) {
            $this->loadRoutesFrom($routes);
        }

        $views = resource_path('views/'.self::MODULE);

        if (is_dir($views)) {
            $this->loadViewsFrom($views, self::MODULE);
        }

        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('auth.apikey', AuthenticateApiKey::class);
        $router->aliasMiddleware('scope', RequireScope::class);
        $router->aliasMiddleware('throttle.apikey', ThrottleApiKey::class);
        $router->aliasMiddleware('2fa', RequireTwoFactor::class);
        $router->aliasMiddleware('2fa.fresh', RequireFreshTwoFactor::class);
        // Absolute Sitzungsdauer für alle Web-Routen (08-security.md Abschnitt 3.1).
        $router->pushMiddlewareToGroup('web', EnforceAbsoluteSessionLifetime::class);

        $this->registerGates();

        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(ApiKey::class, ApiKeyPolicy::class);
        Gate::policy(AuditLog::class, AuditLogPolicy::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                CreateUserCommand::class,
                CreateApiKeyCommand::class,
                AuditVerifyCommand::class,
                AuditAnchorCommand::class,
            ]);

            $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
                // withoutOverlapping und onOneServer wie in SyncSchedule: keine doppelte Verankerung bei zwei
                // Scheduler-Instanzen, kein audit:anchor parallel zu einem noch laufenden audit:verify.
                $schedule->command('audit:verify')->dailyAt('02:00')->withoutOverlapping(120)->onOneServer();
                $schedule->command('audit:anchor')->dailyAt('02:30')->withoutOverlapping(60)->onOneServer();
            });
        }
    }

    /**
     * Ein Gate je Recht der Permission-Map. Gesperrte oder deaktivierte Nutzer erhalten nichts.
     */
    private function registerGates(): void
    {
        Gate::before(static function (mixed $user): ?bool {
            if ($user instanceof User && ($user->isDisabled() || $user->isLocked())) {
                return false;
            }

            return null;
        });

        $map = $this->app->make(PermissionMap::class);

        foreach ($map->allPermissions() as $permission) {
            Gate::define($permission, static function (User $user) use ($map, $permission): bool {
                return $map->allows($user->role, $permission);
            });
        }

        Gate::define('role', static fn (User $user, Role ...$roles): bool => $user->hasRole(...$roles));
    }
}
