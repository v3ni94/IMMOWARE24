<?php

declare(strict_types=1);

namespace App\Modules\Gmail\Console;

use App\Modules\Gmail\Services\Push\PushEventService;
use Illuminate\Console\Command;

/**
 * Aufbewahrung der Pub/Sub-Push-Ereignisse (mail_push_events): entfernt Einträge, die älter sind als
 * hub.gmail.push.dedup_retention_days (Standard 30 Tage), in Blöcken (PushEventService::prune). Die Tabelle dient
 * nur der Dedup und Protokollierung, nicht als Spiegeldatenbestand. Zeitplan täglich in routes/console.php.
 */
final class PrunePushEventsCommand extends Command
{
    protected $signature = 'mail:push:prune {--chunk=1000 : Zeilen je Löschblock}';

    protected $description = 'Push-Ereignisse älter als die Aufbewahrungsfrist (hub.gmail.push.dedup_retention_days) entfernen.';

    public function handle(PushEventService $events): int
    {
        $deleted = $events->prune(max(1, (int) $this->option('chunk')));
        $days = max(1, (int) config('hub.gmail.push.dedup_retention_days', 30));

        $this->info(sprintf('%d Push-Ereignisse älter als %d Tage entfernt.', $deleted, $days));

        return self::SUCCESS;
    }
}
