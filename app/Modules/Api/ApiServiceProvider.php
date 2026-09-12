<?php

declare(strict_types=1);

namespace App\Modules\Api;

use App\Modules\Api\Console\ExportOpenApiCommand;
use App\Modules\Api\Directory\DirectoryService;
use App\Modules\Api\Directory\PhonebookXmlWriter;
use App\Modules\Api\Directory\VCardWriter;
use App\Modules\Api\Exceptions\ApiExceptionRenderer;
use App\Modules\Api\Health\HealthService;
use App\Modules\Api\Http\Middleware\IdempotencyMiddleware;
use App\Modules\Api\Http\Query\ListQuery;
use App\Modules\Api\Http\Resources\ApiResponse;
use App\Modules\Api\OpenApi\OpenApiGenerator;
use App\Modules\Api\OpenApi\SchemaRegistry;
use App\Modules\Api\Services\WriteGuard;
use App\Modules\Api\Support\ApiCaller;
use App\Modules\Api\Support\Provenance;
use App\Modules\Api\Support\ResourceRegistry;
use App\Modules\Security\Http\Middleware\AuthenticateApiKey;
use App\Modules\Security\Http\Middleware\RequireScope;
use App\Modules\Security\Http\Middleware\ThrottleApiKey;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Throwable;

class ApiServiceProvider extends ServiceProvider
{
    public const string MODULE = 'api';

    public function register(): void
    {
        $config = base_path('config/hub/'.self::MODULE.'.php');

        if (is_file($config)) {
            $this->mergeConfigFrom($config, 'hub.'.self::MODULE);
        }

        $this->app->singleton(ResourceRegistry::class);
        $this->app->singleton(SchemaRegistry::class);
        $this->app->singleton(OpenApiGenerator::class);
        $this->app->singleton(ApiExceptionRenderer::class);
        $this->app->singleton(VCardWriter::class);
        $this->app->singleton(PhonebookXmlWriter::class);
        $this->app->singleton(DirectoryService::class);
        $this->app->singleton(HealthService::class);
        $this->app->singleton(ListQuery::class);
        $this->app->singleton(ApiCaller::class);
        $this->app->singleton(WriteGuard::class);
        // Provenance cacht Connector- und Mapping-Auflösung je Request.
        $this->app->scoped(Provenance::class);
        $this->app->scoped(ApiResponse::class);
    }

    public function boot(): void
    {
        $router = $this->app->make(Router::class);
        // API-Key-Authentifizierung, Scope-Prüfung und Rate Limit kommen real aus dem Security-Modul.
        $router->aliasMiddleware('api.auth', AuthenticateApiKey::class);
        $router->aliasMiddleware('api.scope', RequireScope::class);
        $router->aliasMiddleware('api.throttle', ThrottleApiKey::class);
        $router->aliasMiddleware('api.idempotency', IdempotencyMiddleware::class);

        $routes = base_path('routes/modules/'.self::MODULE.'.php');

        if (is_file($routes)) {
            $this->loadRoutesFrom($routes);
        }

        $views = resource_path('views/'.self::MODULE);

        if (is_dir($views)) {
            $this->loadViewsFrom($views, self::MODULE);
        }

        $this->registerExceptionRenderer();

        if ($this->app->runningInConsole()) {
            $this->commands([ExportOpenApiCommand::class]);
        }
    }

    /**
     * RFC-7807-Rendering nur für API- und Health-Routen, registriert als renderable am Exception-Handler.
     */
    private function registerExceptionRenderer(): void
    {
        $this->callAfterResolving(ExceptionHandler::class, function (ExceptionHandler $handler): void {
            if (! $handler instanceof Handler) {
                return;
            }

            $handler->renderable(function (Throwable $e, Request $request) {
                return $this->app->make(ApiExceptionRenderer::class)($e, $request);
            });
        });
    }
}
