<?php

declare(strict_types=1);

namespace App\Modules\Connector\Console;

use App\Modules\Connector\Services\RemoteRequestLogger;
use Illuminate\Console\Command;

final class PruneRemoteRequestsCommand extends Command
{
    protected $signature = 'hub:connector:prune-remote-requests {--days= : Aufbewahrung in Tagen (Standard aus Konfiguration)}';

    protected $description = 'Löscht Einträge aus remote_requests, die älter als die konfigurierte Aufbewahrung sind.';

    public function handle(RemoteRequestLogger $logger): int
    {
        $days = $this->option('days');
        $deleted = $logger->prune(is_numeric($days) ? max(1, (int) $days) : null);

        $this->info(sprintf('%d Einträge aus remote_requests gelöscht.', $deleted));

        return self::SUCCESS;
    }
}
