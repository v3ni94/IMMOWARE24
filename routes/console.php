<?php

declare(strict_types=1);

use App\Modules\Actions\Jobs\RunScheduledActionsJob;
use App\Modules\Gmail\Jobs\ReconcileJob;
use App\Modules\Gmail\Jobs\SendReconciliationJob;
use App\Modules\Gmail\Jobs\WatchRenewJob;
use App\Modules\Gmail\Services\Push\PushEventService;
use App\Modules\Sla\Jobs\SlaCheckJob;
use App\Modules\Sync\Schedule\SyncSchedule;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Schedule as ScheduleFacade;

/*
 * Zentrale Scheduler-Registrierung des Hubs. Modulinterne Zeitpläne:
 * - Sync (inkrementell je Entität, Full Sync, Stale-Check, Payload-Prune) über SyncSchedule aus config('hub.sync.schedule')
 * - Security: audit:verify und audit:anchor (SecurityServiceProvider)
 * - Webhooks: hub:webhooks:redeliver (WebhooksServiceProvider)
 */

app()->afterResolving(Schedule::class, static function (Schedule $schedule): void {
    (new SyncSchedule)->register($schedule);
});

ScheduleFacade::command('hub:connector:prune-remote-requests')
    ->dailyAt('04:30')
    ->timezone((string) config('hub.sync.schedule.timezone', 'Europe/Berlin'))
    ->withoutOverlapping()
    ->onOneServer()
    ->description('hub:connector prune remote_requests');

ScheduleFacade::command('hub:imports:scan --process')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->onOneServer()
    ->description('hub:imports drop folder scan');

// 05-write-capabilities.md 3.3 und 3.4: Anträge in sent oder unknown ausschließlich per PROPFIND weiterführen.
ScheduleFacade::command('hub:write:resume')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer()
    ->description('hub:write resume sent/unknown via PROPFIND');

// 03-monitoring.md Abschnitt 5: Lebenszeichen für die Container-Healthchecks (Scheduler direkt, Worker über Queue high).
ScheduleFacade::command('hub:worker:heartbeat')
    ->everyMinute()
    ->onOneServer()
    ->description('hub heartbeat scheduler/worker');

/*
 * Mail- und Vorgangsbearbeitung (docs/mail/09-deployment.md Abschnitt 4). Alle Mail-Zeitpläne zentral hier, keine
 * Registrierung in Modul-Providern. Der Name (name()) ist zugleich die Beschreibung in schedule:list. Jobs laufen auf den Mail-Queues: SlaCheckJob und EmergencyAlertJob auf mail-high
 * (eigener Worker), Gmail-Abgleiche auf mail-sync, RunScheduledActionsJob auf der Queue aus hub.actions.jobs.queue.
 */
$mailTimezone = (string) config('hub.mail.display_timezone', 'Europe/Berlin');

ScheduleFacade::job(new WatchRenewJob)
    ->dailyAt('03:15')
    ->timezone($mailTimezone)
    ->name('mail-gmail-watch-renew')
    ->withoutOverlapping()
    ->onOneServer();

ScheduleFacade::job(new ReconcileJob)
    ->cron('*/'.max(5, min(59, (int) config('hub.gmail.sync.reconcile_interval_minutes', 30))).' * * * *')
    ->name('mail-gmail-reconcile')
    ->withoutOverlapping()
    ->onOneServer();

ScheduleFacade::job(new SendReconciliationJob)
    ->everyMinute()
    ->name('mail-gmail-send-reconcile')
    ->withoutOverlapping()
    ->onOneServer();

ScheduleFacade::call(static fn (): int => app(PushEventService::class)->prune())
    ->dailyAt('04:05')
    ->timezone($mailTimezone)
    ->name('mail-gmail-push-prune')
    ->onOneServer();

ScheduleFacade::job(new SlaCheckJob, (string) config('hub.sla.emergency.queue', 'mail-high'))
    ->everyMinute()
    ->name('mail:sla:check')
    ->withoutOverlapping(5)
    ->onOneServer();

ScheduleFacade::job(new RunScheduledActionsJob)
    ->dailyAt('06:15')
    ->timezone($mailTimezone)
    ->name('mail-actions-scheduled')
    ->withoutOverlapping()
    ->onOneServer();
