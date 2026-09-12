<?php

declare(strict_types=1);

namespace App\Modules\Sync\Jobs;

use App\Core\Support\CorrelationId;
use App\Modules\Sync\Models\DlqItem;
use App\Modules\Sync\Services\DlqService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Führt einen DLQ-Eintrag genau einmal erneut aus (manuelle Wiederaufnahme durch Rolle operator).
 * Kein automatischer Retry dieses Jobs: ein zweites Scheitern bleibt in der DLQ.
 */
final class ProcessDlqRetryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(
        public readonly int $dlqItemId,
        public readonly ?int $userId = null,
        public readonly ?string $correlationId = null,
    ) {
        $this->onQueue((string) config('hub.sync.queue', 'sync'));
        $this->timeout = max(60, (int) config('hub.sync.jobs.timeout_seconds', 900));
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        // expireAfter: ohne Ablauf bliebe der Lock nach einem harten Worker-Abbruch dauerhaft und jeder weitere Retry
        // desselben Eintrags würde still verworfen (Änderungsvermerk 12.09.2026).
        return [
            (new WithoutOverlapping('immoware:dlq:'.$this->dlqItemId))
                ->dontRelease()
                ->expireAfter($this->timeout + 60),
        ];
    }

    public function handle(DlqService $dlq, CorrelationId $correlation): void
    {
        if ($this->correlationId !== null) {
            $correlation->set($this->correlationId);
        }

        /** @var DlqItem|null $item */
        $item = DlqItem::query()->find($this->dlqItemId);

        if ($item === null) {
            Log::warning('ProcessDlqRetryJob: DLQ-Eintrag nicht gefunden.', ['dlq_item_id' => $this->dlqItemId]);

            return;
        }

        if ($this->userId !== null) {
            $item->forceFill(['replayed_by' => $this->userId]);
            $item->save();
        }

        try {
            $dlq->replay($item);
        } catch (Throwable $exception) {
            Log::warning('ProcessDlqRetryJob: Wiederaufnahme gescheitert.', ['dlq_item_id' => $this->dlqItemId, 'error' => $dlq->describe($exception)]);
        }
    }

    /**
     * Timeout oder harter Abbruch während des Replays: der Eintrag darf nicht im Status retrying verharren.
     */
    public function failed(?Throwable $exception): void
    {
        $exception ??= new \RuntimeException('ProcessDlqRetryJob ohne Exception fehlgeschlagen.');

        app(DlqService::class)->markReplayFailed($this->dlqItemId, $exception);

        Log::error('ProcessDlqRetryJob endgültig fehlgeschlagen.', ['dlq_item_id' => $this->dlqItemId, 'error' => $exception->getMessage()]);
    }
}
