<?php

declare(strict_types=1);

namespace App\Modules\Imports\Console;

use App\Modules\Imports\Enums\ImportFileStatus;
use App\Modules\Imports\Models\ImportFile;
use App\Modules\Imports\Services\DropFolderScanner;
use App\Modules\Imports\Services\ImportProcessor;
use Illuminate\Console\Command;

/**
 * Drop-Ordner-Watcher: erfasst neue Dateien aus HUB_IMPORT_DROP_PATH. Mit --process werden erfasste Dateien
 * (Status received) direkt verarbeitet.
 */
final class ScanImportsCommand extends Command
{
    protected $signature = 'hub:imports:scan {--process : Erfasste Dateien direkt verarbeiten}';

    protected $description = 'Durchsucht den Import-Drop-Ordner nach neuen Exportdateien und legt import_files an.';

    public function handle(DropFolderScanner $scanner, ImportProcessor $processor): int
    {
        $result = $scanner->scan();

        $this->info(sprintf(
            'Erfasst: %d (received %d, quarantined %d), Duplikate: %d, Fehler: %d',
            $result->total(),
            count($result->received),
            count($result->quarantined),
            count($result->duplicates),
            count($result->errors),
        ));

        foreach ($result->errors as $error) {
            $this->warn($error);
        }

        if ($this->option('process')) {
            ImportFile::query()->withoutGlobalScope('organization')
                ->where('status', ImportFileStatus::Received->value)
                ->lazyById(50)
                ->each(function (ImportFile $file) use ($processor): void {
                    $processed = $processor->process($file);
                    $this->line(sprintf(
                        '#%d %s: %s (%d importiert, %d abgelehnt, %d Duplikate)',
                        (int) $processed->getKey(),
                        (string) $processed->original_filename,
                        (string) $processed->status,
                        (int) ($processed->rows_imported ?? 0),
                        (int) ($processed->rows_rejected ?? 0),
                        (int) ($processed->rows_duplicate ?? 0),
                    ));
                });
        }

        return self::SUCCESS;
    }
}
