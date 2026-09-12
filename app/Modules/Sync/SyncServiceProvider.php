<?php

declare(strict_types=1);

namespace App\Modules\Sync;

use App\Core\Support\CorrelationId;
use App\Modules\Sync\Console\BootstrapSyncCommand;
use App\Modules\Sync\Console\DispatchSyncCommand;
use App\Modules\Sync\Console\HeartbeatCheckCommand;
use App\Modules\Sync\Console\PrunePayloadsCommand;
use App\Modules\Sync\Console\RefreshStalenessCommand;
use App\Modules\Sync\Console\RunSyncCommand;
use App\Modules\Sync\Console\WorkerHeartbeatCommand;
use App\Modules\Sync\Services\BootstrapService;
use App\Modules\Sync\Services\ConflictDetector;
use App\Modules\Sync\Services\ConflictService;
use App\Modules\Sync\Services\DataAgeService;
use App\Modules\Sync\Services\DlqService;
use App\Modules\Sync\Services\ExternalPayloadArchiver;
use App\Modules\Sync\Services\FieldMappingService;
use App\Modules\Sync\Services\ProposedChangeService;
use App\Modules\Sync\Services\SyncDispatcher;
use App\Modules\Sync\Services\SyncMetrics;
use App\Modules\Sync\Services\SyncRunService;
use App\Modules\Sync\Services\SyncStateService;
use App\Modules\Sync\Support\ConnectorResolver;
use App\Modules\Sync\Support\SyncLockManager;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

class SyncServiceProvider extends ServiceProvider
{
    public const string MODULE = 'sync';

    public function register(): void
    {
        $config = base_path('config/hub/'.self::MODULE.'.php');

        if (is_file($config)) {
            $this->mergeConfigFrom($config, 'hub.'.self::MODULE);
        }

        $this->app->singleton(SyncMetrics::class, static fn (Application $app): SyncMetrics => new SyncMetrics(
            Cache::store(),
            (string) $app->make('config')->get('hub.sync.metrics.cache_prefix', 'hub:sync:metrics'),
            (int) $app->make('config')->get('hub.sync.metrics.ttl_seconds', 604800),
        ));

        foreach ([
            SyncLockManager::class, ConnectorResolver::class, SyncRunService::class, SyncStateService::class,
            DataAgeService::class, ExternalPayloadArchiver::class, DlqService::class, ConflictDetector::class,
            ConflictService::class, ProposedChangeService::class, FieldMappingService::class, SyncDispatcher::class,
            BootstrapService::class,
        ] as $service) {
            $this->app->singleton($service);
        }
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
            $this->commands([
                RunSyncCommand::class,
                DispatchSyncCommand::class,
                BootstrapSyncCommand::class,
                PrunePayloadsCommand::class,
                RefreshStalenessCommand::class,
                WorkerHeartbeatCommand::class,
                HeartbeatCheckCommand::class,
            ]);
        }

        // Die Zeitpläne (SyncSchedule) registriert routes/console.php zentral, damit sie nur einmal angelegt werden.

        $this->registerCorrelationReset();
    }

    /**
     * Correlation-ID je Job zurücksetzen (Änderungsvermerk 12.09.2026): Der Worker ist ein langlebiger Prozess, der
     * CorrelationId-Singleton würde sonst die ID des ersten Jobs in alle folgenden Log-Zeilen, remote_requests und
     * Audit-Einträge tragen. Vor jedem Job eine frische ID (Jobs mit eigener correlationId setzen sie in handle()),
     * nach Abschluss oder Fehler den Kontext leeren.
     */
    private function registerCorrelationReset(): void
    {
        Queue::before(function (JobProcessing $event): void {
            $correlation = $this->app->make(CorrelationId::class);
            $correlation->clear();
            $correlation->set(CorrelationId::generate());
        });

        $clear = function (JobProcessed|JobFailed|JobExceptionOccurred $event): void {
            $this->app->make(CorrelationId::class)->clear();
        };

        Queue::after($clear);
        Queue::failing($clear);
        Queue::exceptionOccurred($clear);
    }
}
