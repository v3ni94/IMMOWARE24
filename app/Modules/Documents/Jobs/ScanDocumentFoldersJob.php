<?php

declare(strict_types=1);

namespace App\Modules\Documents\Jobs;

use App\Core\DTO\SyncRequest;
use App\Core\Enums\SyncMode;
use App\Core\Support\CorrelationId;
use App\Modules\Connector\Models\ImmowareConnection;
use App\Modules\Connector\Services\ConnectorManager;
use App\Modules\Documents\Connectors\WebDavConnector;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Traversiert die WebDAV-Ordner einer Connection in Chunks. Jeder Lauf verarbeitet höchstens
 * folders_per_run Ordner und plant sich mit dem Rest-Cursor selbst erneut ein.
 */
final class ScanDocumentFoldersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 900;

    public function __construct(
        public readonly int $connectionId,
        public readonly SyncMode $mode = SyncMode::Incremental,
        public readonly ?string $cursor = null,
        public readonly ?string $correlationId = null,
    ) {
        $this->onQueue((string) config('hub.documents.queues.scan', 'documents'));
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return array_map('intval', (array) config('hub.core.job_backoff', [30, 120, 600, 1800]));
    }

    public function handle(ConnectorManager $connectors, CorrelationId $correlation): void
    {
        if ($this->correlationId !== null) {
            $correlation->set($this->correlationId);
        }

        /** @var ImmowareConnection|null $connection */
        $connection = ImmowareConnection::query()->withoutGlobalScopes()->find($this->connectionId);

        if ($connection === null) {
            Log::warning('ScanDocumentFoldersJob: Connection nicht gefunden.', ['connection_id' => $this->connectionId]);

            return;
        }

        $connector = $connectors->resolve($connection);

        if (! $connector instanceof WebDavConnector) {
            Log::warning('ScanDocumentFoldersJob: Connection ist kein WebDAV-Adapter.', ['connection_id' => $this->connectionId]);

            return;
        }

        $result = $connector->pull(new SyncRequest($this->connectionId, WebDavConnector::ENTITY_TYPE, $this->mode, null, $this->cursor));

        Log::info('Dokumenten-Scan-Chunk abgeschlossen.', ['connection_id' => $this->connectionId, ...$result->toArray(), 'cursor' => $result->cursor !== null ? 'pending' : null]);

        if ($result->cursor !== null) {
            self::dispatch($this->connectionId, $this->mode, $result->cursor, $correlation->current());
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('ScanDocumentFoldersJob endgültig fehlgeschlagen.', [
            'connection_id' => $this->connectionId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
