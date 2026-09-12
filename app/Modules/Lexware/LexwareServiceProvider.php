<?php

declare(strict_types=1);

namespace App\Modules\Lexware;

use App\Core\Support\SecretMasker;
use App\Modules\Lexware\Services\LexwareClientFactory;
use App\Modules\Lexware\Services\LexwareConnectionResolver;
use App\Modules\Lexware\Services\LexwareCustomerLookup;
use App\Modules\Lexware\Services\LexwareRateLimiter;
use App\Modules\Lexware\Support\LexwareContactRules;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\ServiceProvider;

/**
 * Modul Lexware: Adapter Lexware Office Public API (Kontakt lesen, suchen, aktualisieren mit Versionsvergleich), Rate Limit 1 rps.
 * Routen aus routes/modules/lexware.php sind API- und Webhook-Routen ohne Domain-Bindung; Oberflächenrouten
 * gehören in routes/modules/mail.php (Domain mail.muellerhv.de, Modul MailUi).
 */
class LexwareServiceProvider extends ServiceProvider
{
    public const string MODULE = 'lexware';

    public function register(): void
    {
        $config = base_path('config/hub/'.self::MODULE.'.php');

        if (is_file($config)) {
            $this->mergeConfigFrom($config, 'hub.'.self::MODULE);
        }

        $this->app->singleton(LexwareRateLimiter::class, static fn ($app): LexwareRateLimiter => new LexwareRateLimiter(
            $app->make(CacheRepository::class),
            max(0.1, (float) config('hub.lexware.rate_limit_rps', 2)),
        ));
        $this->app->singleton(LexwareConnectionResolver::class);
        $this->app->singleton(LexwareContactRules::class);
        $this->app->singleton(LexwareClientFactory::class, static fn ($app): LexwareClientFactory => new LexwareClientFactory(
            $app->make(Factory::class),
            $app->make(LexwareRateLimiter::class),
            $app->make(SecretMasker::class),
            $app->make(LexwareConnectionResolver::class),
            $app->make('config'),
        ));
        $this->app->singleton(LexwareCustomerLookup::class);
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
    }
}
