<?php

declare(strict_types=1);

namespace App\Modules\Actions\Jobs;

use App\Core\Support\CorrelationId;
use App\Modules\Actions\Services\ActionOutboxDispatcher;
use App\Modules\Sync\Services\DlqService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Verarbeiter der mail_outbox (Scheduler, jede Minute, Queue aus hub.actions.jobs.queue). Eindeutig in der Queue,
 * damit Scheduler und manuelle Anstöße nicht parallel dieselben Einträge übergeben.
 */
class DispatchActionOutboxJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public int $uniqueFor = 300;

    public function __construct(public readonly ?string $correlationId = null)
    {
        $this->onQueue((string) config('hub.actions.jobs.queue', 'mail-high'));
    }

    /**
     * @return array{dispatched: int, failed: int, retried: int}
     */
    public function handle(ActionOutboxDispatcher $dispatcher, CorrelationId $correlation): array
    {
        if ($this->correlationId !== null) {
            $correlation->set($this->correlationId);
        }

        $stats = $dispatcher->dispatchPending();

        if ($stats['dispatched'] > 0 || $stats['failed'] > 0) {
            Log::info('mail_outbox verarbeitet.', $stats);
        }

        return $stats;
    }

    public function failed(?Throwable $exception): void
    {
        $exception ??= new \RuntimeException('DispatchActionOutboxJob ohne Exception fehlgeschlagen.');

        app(DlqService::class)->store(static::class, ['correlationId' => $this->correlationId], $exception, null, 'mail_outbox', (string) $this->queue);
    }
}
