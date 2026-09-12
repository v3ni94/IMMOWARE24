<?php

declare(strict_types=1);

namespace App\Modules\Documents\Console;

use App\Core\Enums\WriteOperationStatus;
use App\Modules\Documents\Services\PosteingangUploadService;
use App\Modules\Sync\Models\WriteOperation;
use Illuminate\Console\Command;
use Throwable;

/**
 * Neustartverhalten (05-write-capabilities.md 3.4): führt write_operations in sent, unknown und verifying
 * ausschließlich per PROPFIND weiter. Läuft im Scheduler und beim Start. Es wird nie ein PUT ausgelöst.
 */
final class ResumeWriteOperationsCommand extends Command
{
    protected $signature = 'hub:write:resume {--limit=100 : Höchstzahl je Lauf}';

    protected $description = 'Führt Upload-Anträge in sent oder unknown per PROPFIND weiter (nie ein zweites PUT).';

    public function handle(PosteingangUploadService $service): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $processed = 0;

        $operations = WriteOperation::query()
            ->whereIn('status', [WriteOperationStatus::Sent->value, WriteOperationStatus::Unknown->value])
            ->lazyById(min(100, $limit));

        foreach ($operations as $operation) {
            if (! $operation instanceof WriteOperation) {
                continue;
            }

            if ($processed >= $limit) {
                break;
            }

            try {
                $result = $service->resume($operation);
                $this->line(sprintf('write_operation %d: %s -> %s', (int) $operation->getKey(), $result->outcome, $result->status()->value));
            } catch (Throwable $e) {
                $this->warn(sprintf('write_operation %d: Fehler %s', (int) $operation->getKey(), $e::class));
            }

            $processed++;
        }

        $this->info(sprintf('%d Antrag/Anträge geprüft.', $processed));

        return self::SUCCESS;
    }
}
