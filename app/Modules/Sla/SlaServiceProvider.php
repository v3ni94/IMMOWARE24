<?php

declare(strict_types=1);

namespace App\Modules\Sla;

use App\Modules\Cases\Services\CaseStatusLogger;
use App\Modules\Sla\Calendar\BusinessTime;
use App\Modules\Sla\Calendar\WorkCalendar;
use App\Modules\Sla\Channels\AlertChannelInterface;
use App\Modules\Sla\Channels\EmailAlertChannel;
use App\Modules\Sla\Channels\NotConfiguredAlertChannel;
use App\Modules\Sla\Channels\WebhookAlertChannel;
use App\Modules\Sla\Services\EmergencyQueue;
use App\Modules\Sla\Services\PriorityClassifier;
use App\Modules\Sla\Services\SlaClockService;
use App\Modules\Sla\Services\SlaRuleResolver;
use App\Modules\Sla\Support\StagingGuard;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Support\ServiceProvider;

/**
 * Modul Sla: Arbeitskalender (Europe/Berlin, Feiertage NRW), BusinessTime, Prioritätsvorstufe P0 bis P3, vier Uhren
 * je Teilanliegen, Ampel, Notfallwarteschlange mit Eskalationskette (Queue mail-high), SlaCheckJob minütlich.
 * Routen aus routes/modules/sla.php sind API- und Webhook-Routen ohne Domain-Bindung; Oberflächenrouten
 * gehören in routes/modules/mail.php (Domain mail.muellerhv.de, Modul MailUi).
 */
class SlaServiceProvider extends ServiceProvider
{
    public const string MODULE = 'sla';

    public function register(): void
    {
        $config = base_path('config/hub/'.self::MODULE.'.php');

        if (is_file($config)) {
            $this->mergeConfigFrom($config, 'hub.'.self::MODULE);
        }

        $this->app->singleton(WorkCalendar::class, static fn ($app): WorkCalendar => WorkCalendar::fromConfig((array) $app['config']->get('hub.sla', [])));
        $this->app->singleton(BusinessTime::class, static fn ($app): BusinessTime => new BusinessTime($app->make(WorkCalendar::class), (int) $app['config']->get('hub.sla.work_day_minutes', 510)));
        $this->app->singleton(SlaRuleResolver::class);
        $this->app->singleton(PriorityClassifier::class);
        $this->app->singleton(SlaClockService::class);

        $this->app->singleton(EmergencyQueue::class, static function ($app): EmergencyQueue {
            /** @var array<int, AlertChannelInterface> $channels */
            $channels = [];

            // Staging-Schutz wie MailBootGuard: keine Alarme an reale Empfänger außerhalb der Betriebsdomain.
            $staging = new StagingGuard($app['config'], (string) $app->environment());

            foreach ((array) $app['config']->get('hub.sla.emergency.channels', ['email', 'webhook', 'sms', 'call']) as $name) {
                $channels[] = match ((string) $name) {
                    'email' => new EmailAlertChannel($app->make(Mailer::class), $app['config'], $staging),
                    'webhook' => new WebhookAlertChannel($app['config'], $staging),
                    default => new NotConfiguredAlertChannel((string) $name),
                };
            }

            return new EmergencyQueue($channels, $app->make(WorkCalendar::class), $app->make(CaseStatusLogger::class), $app['config'], $app->make(BusDispatcher::class));
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

        // SlaCheckJob (minütlich, Queue mail-high) ist zentral in routes/console.php eingeplant.
    }
}
