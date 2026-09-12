<?php

declare(strict_types=1);

namespace App\Modules\Imports\Connectors;

use App\Core\Contracts\ImmowareConnectorInterface;
use App\Core\DTO\CheckResult;
use App\Core\DTO\ConnectionResult;
use App\Core\DTO\SyncRequest;
use App\Core\DTO\SyncResult;
use App\Core\Exceptions\WriteBlockedException;
use App\Modules\Imports\Enums\ImportFileStatus;
use App\Modules\Imports\Models\ImportFile;
use App\Modules\Imports\Services\DropFolderScanner;
use App\Modules\Imports\Services\ImportProcessor;
use App\Modules\Imports\Services\ImportStorage;
use Throwable;

/**
 * Adapter "file_import": kapselt den manuellen Dateiexport (CSV, DATEV-CSV, CAMT.053) als Zugangsweg.
 * pull() erfasst neue Dateien im Drop-Ordner und verarbeitet alle Dateien im Status received.
 * Ein Schreibpfad existiert nicht, push() ist gesperrt.
 */
final class FileImportConnector implements ImmowareConnectorInterface
{
    public const string NAME = 'file_import';

    public function __construct(
        private readonly ImportStorage $storage,
        private readonly DropFolderScanner $scanner,
        private readonly ImportProcessor $processor,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function authenticate(): bool
    {
        return $this->testConnection()->ok;
    }

    public function testConnection(): ConnectionResult
    {
        $path = $this->storage->dropPath();

        if ($path === '') {
            return ConnectionResult::fromChecks(['file_import.drop_path' => CheckResult::disabled('HUB_IMPORT_DROP_PATH ist nicht gesetzt.')]);
        }

        if (! is_dir($path) || ! is_readable($path)) {
            return ConnectionResult::fromChecks(['file_import.drop_path' => CheckResult::failed('Drop-Ordner nicht vorhanden oder nicht lesbar.')]);
        }

        return ConnectionResult::fromChecks(['file_import.drop_path' => CheckResult::ok('Drop-Ordner erreichbar.')]);
    }

    public function capabilities(): array
    {
        return ['imports.read'];
    }

    public function pull(SyncRequest $request): SyncResult
    {
        $scan = $this->scanner->scan();
        $errors = array_map(static fn (string $message): array => ['message' => $message], $scan->errors);
        $processed = 0;
        $created = 0;
        $updated = 0;
        $failed = count($scan->errors);

        ImportFile::query()->withoutGlobalScope('organization')
            ->where('status', ImportFileStatus::Received->value)
            ->lazyById(50)
            ->each(function (ImportFile $file) use (&$processed, &$created, &$updated, &$failed, &$errors): void {
                try {
                    $result = $this->processor->process($file);
                    $processed++;
                    $created += (int) ($result->getAttribute('rows_imported') ?? 0);
                    $updated += (int) ($result->getAttribute('rows_duplicate') ?? 0);
                } catch (Throwable $e) {
                    $failed++;
                    $errors[] = ['import_file_id' => (int) $file->getKey(), 'message' => $e->getMessage()];
                }
            });

        return new SyncResult(processed: $processed, created: $created, updated: $updated, failed: $failed, errors: $errors);
    }

    public function push(SyncRequest $request): SyncResult
    {
        throw new WriteBlockedException('Dateiimport kennt keinen Schreibpfad Richtung Immoware24.', 'file_import.write');
    }
}
