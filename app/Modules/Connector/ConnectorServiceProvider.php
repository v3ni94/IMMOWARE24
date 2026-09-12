<?php

declare(strict_types=1);

namespace App\Modules\Connector;

use App\Core\Contracts\CapabilityRegistryInterface;
use App\Core\Contracts\RateLimiterInterface;
use App\Core\Support\CorrelationId;
use App\Core\Support\SecretMasker;
use App\Modules\Connector\Connectors\RestApiSlotConnector;
use App\Modules\Connector\Console\ProbeCommand;
use App\Modules\Connector\Console\PruneRemoteRequestsCommand;
use App\Modules\Connector\Services\CapabilityRegistry;
use App\Modules\Connector\Services\CircuitBreaker;
use App\Modules\Connector\Services\ConnectorManager;
use App\Modules\Connector\Services\RateLimitManager;
use App\Modules\Connector\Services\RemoteRequestLogger;
use App\Modules\Connector\Support\ConnectorContext;
use App\Modules\Connector\Support\ResponseSchemaFingerprint;
use App\Modules\Connector\Support\UrlSanitizer;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\ServiceProvider;

class ConnectorServiceProvider extends ServiceProvider
{
    public const string MODULE = 'connector';

    public function register(): void
    {
        $config = base_path('config/hub/'.self::MODULE.'.php');

        if (is_file($config)) {
            $this->mergeConfigFrom($config, 'hub.'.self::MODULE);
        }

        $this->app->singleton(RateLimitManager::class, static fn (Application $app): RateLimitManager => new RateLimitManager(
            Cache::store(),
            (array) $app->make('config')->get('hub.connector.rate_limit', []),
        ));
        $this->app->alias(RateLimitManager::class, RateLimiterInterface::class);

        $this->app->singleton(CircuitBreaker::class, static fn (Application $app): CircuitBreaker => new CircuitBreaker(
            Cache::store(),
            (array) $app->make('config')->get('hub.connector.circuit_breaker', []),
        ));

        $this->app->singleton(RemoteRequestLogger::class, static fn (Application $app): RemoteRequestLogger => new RemoteRequestLogger(
            $app->make(SecretMasker::class),
            $app->make(UrlSanitizer::class),
            $app->make(ResponseSchemaFingerprint::class),
            $app->make(CorrelationId::class),
            (array) $app->make('config')->get('hub.connector.remote_requests', []),
        ));

        $this->app->singleton(CapabilityRegistry::class);
        $this->app->alias(CapabilityRegistry::class, CapabilityRegistryInterface::class);

        $this->app->singleton(ConnectorManager::class, static function (): ConnectorManager {
            $manager = new ConnectorManager;
            $manager->register('rest_api_slot', static fn (ConnectorContext $context): RestApiSlotConnector => new RestApiSlotConnector($context));

            return $manager;
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

        if ($this->app->runningInConsole()) {
            $this->commands([ProbeCommand::class, PruneRemoteRequestsCommand::class]);
        }
    }
}
