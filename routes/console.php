<?php

declare(strict_types=1);

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
