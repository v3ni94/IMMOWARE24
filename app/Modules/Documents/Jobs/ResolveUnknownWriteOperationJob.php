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
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Löst eine write_operation in status unknown ausschließlich per PROPFIND auf und plant sich im
 * konfigurierten Intervall erneut ein, bis der Service den Antrag als verified oder failed abschließt.
 * Es wird nie ein PUT ausgelöst.
 */
final class ResolveUnknownWriteOperationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(
        public readonly int $operationId,
        public readonly ?string $correlationId = null,
    ) {
        $this->onQueue((string) config('hub.documents.queues.write', 'write'));
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return array_map('intval', (array) config('hub.core.job_backoff', [30, 120, 600, 1800]));
    }

    public function handle(PosteingangUploadService $service, CorrelationId $correlation): void
    {
        if ($this->correlationId !== null) {
            $correlation->set($this->correlationId);
        }

        /** @var WriteOperation|null $operation */
        $operation = WriteOperation::query()->find($this->operationId);

        if ($operation === null || ! $service->isUnresolvedUnknown($operation)) {
            return;
        }

        $result = $service->resolveUnknown($operation);

        if ($service->isUnresolvedUnknown($result->operation)) {
            self::dispatch($this->operationId, $correlation->current())->delay($service->unknownRetryIntervalSeconds());
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('ResolveUnknownWriteOperationJob endgültig fehlgeschlagen.', [
            'operation_id' => $this->operationId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
