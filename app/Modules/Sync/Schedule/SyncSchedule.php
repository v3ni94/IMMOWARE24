<?php

declare(strict_types=1);

namespace App\Modules\Sync\Schedule;

use App\Modules\Sync\Enums\SyncEntity;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

/**
 * Registriert die Sync-Zeitpläne. Wird vom SyncServiceProvider in booted() aufgerufen, damit
 * routes/console.php unangetastet bleibt. Intervalle aus config('hub.sync.schedule').
 */
final class SyncSchedule
{
    public const string DESCRIPTION_PREFIX = 'hub:sync ';

    public function register(Schedule $schedule): void
    {
        if (! (bool) config('hub.sync.schedule.enabled', true)) {
            return;
        }

        $timezone = (string) config('hub.sync.schedule.timezone', 'Europe/Berlin');

        foreach (SyncEntity::cases() as $entity) {
            $this->configure(
                $schedule->command('hub:sync:dispatch', [$entity->value, '--mode=incremental']),
                (string) config('hub.sync.schedule.'.$entity->value, '*/5 * * * *'),
                $timezone,
                self::DESCRIPTION_PREFIX.'incremental '.$entity->value,
            );
        }

        $this->configure(
            $schedule->command('hub:sync:dispatch', ['all', '--mode=full']),
            (string) config('hub.sync.schedule.full', '30 2 * * *'),
            $timezone,
            self::DESCRIPTION_PREFIX.'full all',
        );

        $this->configure(
            $schedule->command('hub:sync:stale'),
            (string) config('hub.sync.schedule.stale_check', '*/10 * * * *'),
            $timezone,
            self::DESCRIPTION_PREFIX.'stale check',
        );

        $this->configure(
            $schedule->command('hub:payloads:prune'),
            (string) config('hub.sync.schedule.payload_prune', '15 4 * * *'),
            $timezone,
            self::DESCRIPTION_PREFIX.'payload prune',
        );
    }

    private function configure(Event $event, string $cron, string $timezone, string $description): void
    {
        $event->cron($cron)
            ->timezone($timezone)
            ->withoutOverlapping()
            ->onOneServer()
            ->runInBackground()
            ->description($description);
    }
}
