<?php

declare(strict_types=1);

namespace App\Modules\Sync\Console;

use App\Core\Enums\SyncMode;
use App\Modules\Sync\Enums\SyncEntity;
use App\Modules\Sync\Services\SyncDispatcher;
use Illuminate\Console\Command;

/**
 * Vom Scheduler aufgerufen: plant je aktiver Connection des passenden Adapters einen Lauf ein.
 */
final class DispatchSyncCommand extends Command
{
    protected $signature = 'hub:sync:dispatch {entity : document, contact, calendar_event oder all} {--mode=incremental : incremental oder full}';

    protected $description = 'Plant Sync-Läufe für alle aktiven Connections einer Entität ein.';

    public function handle(SyncDispatcher $dispatcher): int
    {
        $mode = SyncMode::tryFrom((string) $this->option('mode')) ?? SyncMode::Incremental;
        $entityArgument = (string) $this->argument('entity');
        $entities = $entityArgument === 'all' ? SyncEntity::cases() : [SyncEntity::tryFrom($entityArgument)];
        $total = 0;

        foreach ($entities as $entity) {
            if ($entity === null) {
                $this->error('Unbekannte Entität. Erlaubt: '.implode(', ', SyncEntity::values()).', all.');

                return self::INVALID;
            }

            $count = $dispatcher->dispatchForAll($entity, $mode, 'schedule');
            $total += $count;
            $this->line(sprintf('%s: %d Läufe eingeplant.', $entity->label(), $count));
        }

        $this->info(sprintf('Insgesamt %d Läufe eingeplant (%s).', $total, $mode->value));

        return self::SUCCESS;
    }
}
