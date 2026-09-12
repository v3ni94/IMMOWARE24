<?php

declare(strict_types=1);

namespace App\Modules\Sync\Console;

use App\Modules\Connector\Models\ImmowareConnection;
use App\Modules\Sync\Enums\SyncEntity;
use App\Modules\Sync\Services\BootstrapService;
use Illuminate\Console\Command;
use InvalidArgumentException;

final class BootstrapSyncCommand extends Command
{
    protected $signature = 'hub:sync:bootstrap {connection : ID der Connection} {entity : document, contact oder calendar_event} {--stages=1,10,100,1000,alle : Limit-Stufen} {--max-error-rate= : Abbruchschwelle, z. B. 0.05}';

    protected $description = 'Erstimport in Stufen 1, 10, 100, 1000, alle; bricht ab, wenn die Fehlerquote die Schwelle überschreitet.';

    public function handle(BootstrapService $bootstrap): int
    {
        $connection = ImmowareConnection::query()->withoutGlobalScopes()->find((int) $this->argument('connection'));
        $entity = SyncEntity::tryFrom((string) $this->argument('entity'));

        if ($connection === null || $entity === null) {
            $this->error('Connection oder Entität ungültig.');

            return self::INVALID;
        }

        try {
            $stages = BootstrapService::parseStages((string) $this->option('stages'));
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        }

        $maxErrorRate = $this->option('max-error-rate') !== null ? (float) $this->option('max-error-rate') : null;

        $report = $bootstrap->run($connection, $entity->value, $stages, $maxErrorRate, null, function (array $entry): void {
            $this->line(sprintf(
                'Stufe %s: verarbeitet %d, fehlgeschlagen %d, Quote %s%s',
                (string) $entry['stage'],
                (int) $entry['processed'],
                (int) $entry['failed'],
                number_format((float) $entry['error_rate'] * 100, 2, ',', '.').' %',
                $entry['passed'] ? '' : ' -> ABBRUCH',
            ));
        });

        if (! $report['completed']) {
            $this->error(sprintf('Bootstrap abgebrochen auf Stufe %s.', (string) $report['aborted_at_stage']));

            return self::FAILURE;
        }

        $this->info('Bootstrap abgeschlossen.');

        return self::SUCCESS;
    }
}
