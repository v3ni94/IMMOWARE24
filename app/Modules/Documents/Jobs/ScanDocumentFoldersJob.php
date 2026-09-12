<?php

declare(strict_types=1);

namespace App\Modules\Documents\Jobs;

use App\Core\DTO\SyncRequest;
use App\Core\Enums\SyncMode;
use App\Core\Support\CorrelationId;
use App\Modules\Connector\Models\ImmowareConnection;
use App\Modules\Connector\Services\ConnectorManager;
use App\Modules\Documents\Connectors\WebDavConnector;
use App\Modules\Sync\Services\DlqService;
use App\Modules\Sync\Support\SyncLockManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Traversiert die WebDAV-Ordner einer Connection in Chunks. Jeder Lauf verarbeitet höchstens
 * folders_per_run Ordner und plant sich mit dem Rest-Cursor selbst erneut ein. Teilt sich den
 * Overlap-Lock mit RunSyncJob (SyncLockManager::key(connection, document)), damit nie zwei Scans
 * derselben Connection gleichzeitig Mark-and-Sweep ausführen. Erschöpfte Versuche landen in der DLQ.
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

    /**
     * @param  array<string, mixed>  $arguments
     */
    public static function fromDlqArguments(array $arguments): self
    {
        $mode = $arguments['mode'] ?? SyncMode::Incremental->value;

        return new self(
            connectionId: (int) ($arguments['connectionId'] ?? 0),
            mode: $mode instanceof SyncMode ? $mode : (SyncMode::tryFrom((string) $mode) ?? SyncMode::Incremental),
            cursor: isset($arguments['cursor']) ? (string) $arguments['cursor'] : null,
            correlationId: isset($arguments['correlationId']) ? (string) $arguments['correlationId'] : null,
        );
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping(SyncLockManager::key($this->connectionId, WebDavConnector::ENTITY_TYPE)))
                ->releaseAfter(60)
                ->expireAfter((int) config('hub.sync.locks.overlap_ttl_seconds', 3600)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function dlqArguments(): array
    {
        return [
            'connectionId' => $this->connectionId,
            'mode' => $this->mode->value,
            'cursor' => $this->cursor,
            'correlationId' => $this->correlationId,
        ];
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

        if ($result->cursor !== null && $result->cursor === $this->cursor) {
            // Cursor-Vertrag verletzt: kein Fortschritt, sonst Endlos-Kette (vgl. RunSyncJob).
            throw new RuntimeException(sprintf('Adapter lieferte unveränderten Cursor "%s", Scan-Kette abgebrochen.', mb_substr($this->cursor, 0, 80)));
        }

        if ($result->cursor !== null) {
            self::dispatch($this->connectionId, $this->mode, $result->cursor, $correlation->current());
        }
    }

    public function failed(?Throwable $exception): void
    {
        $exception ??= new RuntimeException('Job ohne Exception fehlgeschlagen.');

        Log::error('ScanDocumentFoldersJob endgültig fehlgeschlagen.', [
            'connection_id' => $this->connectionId,
            'error' => $exception->getMessage(),
        ]);

        // 07-sync-strategy.md Abschnitt 6.1 Stufe 5: erschöpfte Jobs in die DLQ, Wiederaufnahme über fromDlqArguments().
        app(DlqService::class)->store(static::class, $this->dlqArguments(), $exception, $this->connectionId, WebDavConnector::ENTITY_TYPE, (string) $this->queue);
    }
}
