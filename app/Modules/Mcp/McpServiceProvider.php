<?php

declare(strict_types=1);

namespace App\Modules\Mcp;

use App\Modules\Mcp\Console\ExportMcpToolsCommand;
use App\Modules\Mcp\Services\ArgumentValidator;
use App\Modules\Mcp\Services\HubApiGateway;
use App\Modules\Mcp\Services\ToolExecutor;
use App\Modules\Mcp\Support\ToolCatalog;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\ServiceProvider;

/**
 * Modul Mcp: Tool-Katalog und Aufruf-Endpunkt des KI/MCP-Layers. Nutzt ausschließlich die Hub-API v1
 * (Middleware-Aliase api.auth, api.throttle aus dem ApiServiceProvider).
 */
class McpServiceProvider extends ServiceProvider
{
    public const string MODULE = 'mcp';

    public function register(): void
    {
        $config = base_path('config/hub/'.self::MODULE.'.php');

        if (is_file($config)) {
            $this->mergeConfigFrom($config, 'hub.'.self::MODULE);
        }

        $this->app->singleton(ToolCatalog::class);
        $this->app->singleton(ArgumentValidator::class);
        $this->app->singleton(HubApiGateway::class);
        $this->app->singleton(ToolExecutor::class);
    }

    public function boot(): void
    {
        $this->registerScopes();

        $routes = base_path('routes/modules/'.self::MODULE.'.php');

        if (is_file($routes) && (bool) config('hub.mcp.enabled', true)) {
            $this->loadRoutesFrom($routes);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([ExportMcpToolsCommand::class]);
        }
    }

    /**
     * Macht mcp:write dem Security-Modul als Scope bekannt, damit hub:api-key:create ihn akzeptiert.
     */
    private function registerScopes(): void
    {
        $config = $this->app->make(ConfigRepository::class);
        $known = (array) $config->get('hub.security.api_keys.scopes', []);
        $additional = (array) $config->get('hub.mcp.additional_scopes', []);

        $config->set('hub.security.api_keys.scopes', array_values(array_unique(array_merge($known, $additional))));
    }
}
