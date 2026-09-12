<?php

declare(strict_types=1);

namespace App\Modules\Sync\Console;

use App\Modules\Sync\Services\ExternalPayloadArchiver;
use Illuminate\Console\Command;

final class PrunePayloadsCommand extends Command
{
    protected $signature = 'hub:payloads:prune {--days= : Retention in Tagen, Standard aus Konfiguration} {--dry-run : nur zählen}';

    protected $description = 'Entfernt archivierte Rohnutzlasten nach Ablauf der Retention.';

    public function handle(ExternalPayloadArchiver $archiver): int
    {
        $days = $this->option('days') !== null ? (int) $this->option('days') : null;
        $dryRun = (bool) $this->option('dry-run');
        $count = $archiver->prune($days, $dryRun);

        $this->info(sprintf('%d Nutzlasten %s.', $count, $dryRun ? 'würden entfernt' : 'entfernt'));

        return self::SUCCESS;
    }
}
