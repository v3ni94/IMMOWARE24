<?php

declare(strict_types=1);

namespace App\Modules\Webhooks;

use App\Core\Contracts\WebhookDispatcherInterface;
use App\Core\Support\UrlGuard;
use App\Modules\Webhooks\Console\EmitStaleSyncEventsCommand;
use App\Modules\Webhooks\Console\RedeliverWebhooksCommand;
use App\Modules\Webhooks\Events\HubEvent;
use App\Modules\Webhooks\Listeners\RecordHubEventListener;
use App\Modules\Webhooks\Services\WebhookDispatcher;
use App\Modules\Webhooks\Services\WebhookSigner;
use App\Modules\Webhooks\Services\WebhookUrlGuard;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

class WebhooksServiceProvider extends ServiceProvider
{
    public const string MODULE = 'webhooks';

    /** Generische Sync-Event-Klasse, falls das Sync-Modul sie bereitstellt. */
    public const string SYNC_EVENT = 'App\Modules\Sync\Events\EntitySynced';

    public function register(): void
    {
        $config = base_path('config/hub/'.self::MODULE.'.php');

        if (is_file($config)) {
            $this->mergeConfigFrom($config, 'hub.'.self::MODULE);
        }

        $this->app->singleton(WebhookSigner::class);
        $this->app->singleton(WebhookUrlGuard::class, static fn (Application $app): WebhookUrlGuard => new WebhookUrlGuard(null, $app->make(UrlGuard::class)));
        $this->app->singleton(WebhookDispatcher::class);
        $this->app->bind(WebhookDispatcherInterface::class, WebhookDispatcher::class);
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

        $events = $this->app->make(EventDispatcher::class);
        $events->listen(HubEvent::class, RecordHubEventListener::class);

        if (class_exists(self::SYNC_EVENT)) {
            $events->listen(self::SYNC_EVENT, RecordHubEventListener::class);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([RedeliverWebhooksCommand::class, EmitStaleSyncEventsCommand::class]);

            $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
                $schedule->command('hub:webhooks:redeliver')->everyFiveMinutes()->withoutOverlapping(10)->onOneServer();
                $schedule->command('hub:webhooks:emit-stale')->hourly()->withoutOverlapping(10)->onOneServer();
            });
        }
    }
}
