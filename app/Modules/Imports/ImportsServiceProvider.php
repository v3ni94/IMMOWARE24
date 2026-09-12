<?php

declare(strict_types=1);

namespace App\Modules\Imports;

use App\Modules\Connector\Services\ConnectorManager;
use App\Modules\Connector\Support\ConnectorContext;
use App\Modules\Imports\Connectors\FileImportConnector;
use App\Modules\Imports\Console\RemindExportsCommand;
use App\Modules\Imports\Console\ScanImportsCommand;
use App\Modules\Imports\Services\ImporterRegistry;
use App\Modules\Imports\Services\Importers\ContactsCsvImporter;
use App\Modules\Imports\Services\Importers\OpenItemsImporter;
use App\Modules\Imports\Services\Importers\OwnersOwnershipsImporter;
use App\Modules\Imports\Services\Importers\PropertiesImporter;
use App\Modules\Imports\Services\Importers\TenantsContractsImporter;
use App\Modules\Imports\Services\Importers\UnitsImporter;
use App\Modules\Imports\Services\ImportStorage;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

class ImportsServiceProvider extends ServiceProvider
{
    public const string MODULE = 'imports';

    public function register(): void
    {
        $config = base_path('config/hub/'.self::MODULE.'.php');

        if (is_file($config)) {
            $this->mergeConfigFrom($config, 'hub.'.self::MODULE);
        }

        $this->app->singleton(ImportStorage::class);

        $this->app->singleton(ImporterRegistry::class, static function ($app): ImporterRegistry {
            $registry = new ImporterRegistry;

            foreach ([
                PropertiesImporter::class,
                UnitsImporter::class,
                TenantsContractsImporter::class,
                OwnersOwnershipsImporter::class,
                OpenItemsImporter::class,
                ContactsCsvImporter::class,
            ] as $importer) {
                $registry->register($app->make($importer));
            }

            return $registry;
        });

        $this->app->singleton(FileImportConnector::class);

        // Dateiimport-Adapter im ConnectorManager des Connector-Moduls registrieren.
        $this->app->extend(ConnectorManager::class, static function (ConnectorManager $manager, Application $app): ConnectorManager {
            $manager->register(FileImportConnector::NAME, static fn (ConnectorContext $context): FileImportConnector => $app->make(FileImportConnector::class));

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
            $this->commands([ScanImportsCommand::class, RemindExportsCommand::class]);
        }
    }
}
