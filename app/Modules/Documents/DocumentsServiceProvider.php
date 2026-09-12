<?php

declare(strict_types=1);

namespace App\Modules\Documents;

use App\Modules\Connector\Services\ConnectorManager;
use App\Modules\Connector\Support\ConnectorContext;
use App\Modules\Documents\Connectors\WebDavConnector;
use App\Modules\Documents\Console\ScanDocumentsCommand;
use App\Modules\Documents\Http\WebDavClientFactory;
use App\Modules\Documents\Services\DocumentMirrorService;
use App\Modules\Documents\Services\PosteingangUploadService;
use App\Modules\Documents\Support\DocumentAssignmentResolver;
use App\Modules\Documents\Support\DocumentTypeClassifier;
use App\Modules\Documents\Support\MultistatusParser;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

class DocumentsServiceProvider extends ServiceProvider
{
    public const string MODULE = 'documents';

    public function register(): void
    {
        $config = base_path('config/hub/'.self::MODULE.'.php');

        if (is_file($config)) {
            $this->mergeConfigFrom($config, 'hub.'.self::MODULE);
        }

        $this->app->singleton(MultistatusParser::class);
        $this->app->singleton(WebDavClientFactory::class);

        $this->app->singleton(DocumentTypeClassifier::class, static fn (Application $app): DocumentTypeClassifier => new DocumentTypeClassifier(
            (array) $app->make('config')->get('hub.documents.type_rules', []),
        ));

        $this->app->singleton(DocumentAssignmentResolver::class, static fn (Application $app): DocumentAssignmentResolver => new DocumentAssignmentResolver(
            (array) $app->make('config')->get('hub.documents.assignment_rules', []),
        ));

        $this->app->singleton(DocumentMirrorService::class);
        $this->app->singleton(PosteingangUploadService::class);

        // WebDAV-Adapter im ConnectorManager des Connector-Moduls registrieren (Zugriff nur über dessen öffentlichen Service).
        $this->app->extend(ConnectorManager::class, function (ConnectorManager $manager, Application $app): ConnectorManager {
            $manager->register(WebDavConnector::NAME, static function (ConnectorContext $context) use ($app): WebDavConnector {
                return new WebDavConnector(
                    $context,
                    $app->make(WebDavClientFactory::class)->make($context),
                    $app->make(DocumentMirrorService::class),
                    $app->make('config'),
                );
            });

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
            $this->commands([ScanDocumentsCommand::class]);
        }
    }
}
