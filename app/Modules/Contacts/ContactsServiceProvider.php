<?php

declare(strict_types=1);

namespace App\Modules\Contacts;

use App\Modules\Connector\Services\ConnectorManager;
use App\Modules\Connector\Support\ConnectorContext;
use App\Modules\Contacts\Mapping\VCardContactMapper;
use App\Modules\Contacts\Services\CardDavConnector;
use App\Modules\Contacts\Services\CollectionStateStore;
use App\Modules\Contacts\Services\DavClientFactory;
use App\Modules\Contacts\Services\DavPullRunner;
use App\Modules\Contacts\VCard\VCardParser;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

class ContactsServiceProvider extends ServiceProvider
{
    public const string MODULE = 'contacts';

    public function register(): void
    {
        $config = base_path('config/hub/'.self::MODULE.'.php');

        if (is_file($config)) {
            $this->mergeConfigFrom($config, 'hub.'.self::MODULE);
        }

        $this->app->singleton(VCardParser::class);
        $this->app->singleton(VCardContactMapper::class);
        $this->app->singleton(CollectionStateStore::class);
        $this->app->singleton(DavClientFactory::class);
        $this->app->singleton(DavPullRunner::class);
        $this->app->singleton(CardDavConnector::class);

        // CardDAV-Adapter im ConnectorManager des Connector-Moduls registrieren (Zugriff nur über dessen öffentlichen Service).
        $this->app->extend(ConnectorManager::class, static function (ConnectorManager $manager, Application $app): ConnectorManager {
            $manager->register(CardDavConnector::NAME, static fn (ConnectorContext $context): CardDavConnector => $app->make(CardDavConnector::class)->forConnection($context->connectionId));

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
