<?php

declare(strict_types=1);

namespace App\Modules\Documents\Jobs;

use App\Core\Support\CorrelationId;
use App\Modules\Documents\Services\PosteingangUploadService;
use App\Modules\Sync\Models\WriteOperation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Führt einen über submit() angelegten Upload-Antrag (status pending, Inhalt im Blob-Speicher) auf der Queue write
 * aus: Precheck, genau ein PUT, Verifikation (PosteingangUploadService::process). Kein automatischer Retry
 * (tries 1): Nach einem Abbruch steht der Antrag in sent oder unknown und wird ausschließlich per PROPFIND
 * weitergeführt (hub:write:resume). Ein zweiter Job für dieselbe Operation wird durch den Overlap-Lock verworfen.
 * Änderungsvermerk 12.09.2026.
 */
final class ExecuteWriteOperationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public readonly int $operationId,
        public readonly ?string $correlationId = null,
    ) {
        $this->onQueue((string) config('hub.documents.queues.write', 'write'));
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('immoware:write:'.$this->operationId))
                ->dontRelease()
                ->expireAfter($this->timeout + 60),
        ];
    }

    public function handle(PosteingangUploadService $service, CorrelationId $correlation): void
    {
        if ($this->correlationId !== null) {
            $correlation->set($this->correlationId);
        }

        /** @var WriteOperation|null $operation */
        $operation = WriteOperation::query()->find($this->operationId);

        if ($operation === null) {
            Log::warning('ExecuteWriteOperationJob: write_operation nicht gefunden.', ['operation_id' => $this->operationId]);

            return;
        }

        $result = $service->process($operation);

        Log::info('ExecuteWriteOperationJob abgeschlossen.', [
            'operation_id' => $this->operationId,
            'operation_uuid' => $operation->getAttribute('operation_uuid'),
            'outcome' => $result->outcome,
            'status' => $result->status()->value,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        // Der Antrag bleibt in seinem persistierten Status (pending, prechecked, sent, unknown); hub:write:resume
        // führt sent und unknown ausschließlich per PROPFIND weiter. Es wird nie ein zweites PUT ausgelöst.
        Log::error('ExecuteWriteOperationJob fehlgeschlagen, Antrag wird über hub:write:resume weitergeführt.', [
            'operation_id' => $this->operationId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
