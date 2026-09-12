<?php

declare(strict_types=1);

namespace App\Modules\Calendar;

use App\Modules\Calendar\ICal\ICalendarParser;
use App\Modules\Calendar\Mapping\ICalEventMapper;
use App\Modules\Calendar\Services\CalDavConnector;
use App\Modules\Connector\Services\ConnectorManager;
use App\Modules\Connector\Support\ConnectorContext;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

class CalendarServiceProvider extends ServiceProvider
{
    public const string MODULE = 'calendar';

    public function register(): void
    {
        $config = base_path('config/hub/'.self::MODULE.'.php');

        if (is_file($config)) {
            $this->mergeConfigFrom($config, 'hub.'.self::MODULE);
        }

        $this->app->singleton(ICalendarParser::class);
        $this->app->singleton(ICalEventMapper::class);
        $this->app->singleton(CalDavConnector::class);

        // CalDAV-Adapter im ConnectorManager des Connector-Moduls registrieren.
        $this->app->extend(ConnectorManager::class, static function (ConnectorManager $manager, Application $app): ConnectorManager {
            $manager->register(CalDavConnector::NAME, static fn (ConnectorContext $context): CalDavConnector => $app->make(CalDavConnector::class)->forConnection($context->connectionId));

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
    }
}
