<?php

declare(strict_types=1);

namespace App\Modules\Sync\Console;

use App\Modules\Sync\Services\QueueDepthProbe;
use Illuminate\Console\Command;

/**
 * hub:sync:queue-depth: setzt das Gauge queue_depth je Queue (minütlich im Scheduler, siehe SyncSchedule).
 */
final class QueueDepthCommand extends Command
{
    protected $signature = 'hub:sync:queue-depth {--json : Ausgabe als JSON}';

    protected $description = 'Misst die Queue-Tiefe je Queue (Redis LLEN, Fallback Tabelle jobs) und setzt das Gauge queue_depth.';

    public function handle(QueueDepthProbe $probe): int
    {
        $result = $probe->record();

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($result['by_queue'] as $queue => $depth) {
            $rows[] = [$queue, (string) $depth];
        }

        $this->table(['Queue', 'Tiefe'], $rows);
        $this->info(sprintf('Gesamt %d (Quelle %s).', $result['total'], $result['source']));

        return self::SUCCESS;
    }
}
