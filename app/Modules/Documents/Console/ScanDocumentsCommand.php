<?php

declare(strict_types=1);

namespace App\Modules\Documents\Console;

use App\Core\Enums\SyncMode;
use App\Modules\Connector\Models\ImmowareConnection;
use App\Modules\Documents\Jobs\ScanDocumentFoldersJob;
use Illuminate\Console\Command;

/**
 * Plant den WebDAV-Dokumentenscan einer Connection ein (Queue documents).
 */
final class ScanDocumentsCommand extends Command
{
    protected $signature = 'documents:scan {connection : ID der ImmowareConnection} {--full : Full Reconcile statt Incremental} {--sync : Job sofort im Prozess ausführen}';

    protected $description = 'Startet den WebDAV-Dokumentenscan (PROPFIND, Spiegel, Mark-and-Sweep) für eine Connection.';

    public function handle(): int
    {
        $connectionId = (int) $this->argument('connection');

        $connection = ImmowareConnection::query()->withoutGlobalScopes()->find($connectionId);

        if ($connection === null) {
            $this->error(sprintf('Connection %d nicht gefunden.', $connectionId));

            return self::FAILURE;
        }

        if (! str_starts_with((string) $connection->getAttribute('connector_type'), 'webdav')) {
            $this->error('Die Connection ist kein WebDAV-Adapter.');

            return self::FAILURE;
        }

        $mode = $this->option('full') ? SyncMode::Full : SyncMode::Incremental;

        if ($this->option('sync')) {
            ScanDocumentFoldersJob::dispatchSync($connectionId, $mode);
            $this->info('Scan ausgeführt.');

            return self::SUCCESS;
        }

        ScanDocumentFoldersJob::dispatch($connectionId, $mode);
        $this->info(sprintf('Scan für Connection %d eingeplant (Modus %s).', $connectionId, $mode->value));

        return self::SUCCESS;
    }
}
