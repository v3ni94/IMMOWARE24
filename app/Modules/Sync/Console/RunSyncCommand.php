<?php

declare(strict_types=1);

namespace App\Modules\Sync\Console;

use App\Core\Enums\SyncMode;
use App\Modules\Connector\Models\ImmowareConnection;
use App\Modules\Sync\Enums\SyncEntity;
use App\Modules\Sync\Services\SyncDispatcher;
use Illuminate\Console\Command;

final class RunSyncCommand extends Command
{
    protected $signature = 'hub:sync:run {connection : ID der Connection} {entity : document, contact oder calendar_event} {--mode=incremental : incremental oder full} {--now : synchron ausführen statt in die Queue}';

    protected $description = 'Plant einen Sync-Lauf für eine Connection und Entität ein (Event-Lauf, trigger_source manual).';

    public function handle(SyncDispatcher $dispatcher): int
    {
        $connection = ImmowareConnection::query()->withoutGlobalScopes()->find((int) $this->argument('connection'));

        if ($connection === null) {
            $this->error('Connection nicht gefunden.');

            return self::FAILURE;
        }

        $entity = SyncEntity::tryFrom((string) $this->argument('entity'));
        $mode = SyncMode::tryFrom((string) $this->option('mode'));

        if ($entity === null || $mode === null || ! in_array($mode, [SyncMode::Incremental, SyncMode::Full], true)) {
            $this->error('Entität oder Modus ungültig. Entität: '.implode(', ', SyncEntity::values()).'. Modus: incremental, full.');

            return self::INVALID;
        }

        $job = $dispatcher->job((int) $connection->getKey(), $entity, $mode, 'manual');

        if ((bool) $this->option('now')) {
            dispatch_sync($job);
            $this->info('Lauf synchron ausgeführt.');
        } else {
            dispatch($job);
            $this->info(sprintf('Lauf eingeplant (Queue %s).', (string) $job->queue));
        }

        return self::SUCCESS;
    }
}
