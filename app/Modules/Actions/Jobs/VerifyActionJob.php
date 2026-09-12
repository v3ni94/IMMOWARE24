<?php

declare(strict_types=1);

namespace App\Modules\Actions\Jobs;

use App\Core\Support\CorrelationId;
use App\Modules\Actions\Models\Execution;
use App\Modules\Actions\Services\ExecutionService;
use App\Modules\Sync\Services\DlqService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Liest den Zielzustand einer Ausführung nach (result_unclear, http_ok_unverified). Läuft immer, bevor irgendetwas
 * wiederholt wird.
 */
class VerifyActionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries;

    public int $timeout;

    public function __construct(
        public readonly int $executionId,
        public readonly ?string $correlationId = null,
    ) {
        $this->tries = max(1, (int) config('hub.actions.jobs.tries', 3));
        $this->timeout = max(30, (int) config('hub.actions.jobs.timeout', 120));
        $this->onQueue((string) config('hub.actions.jobs.queue', 'mail-high'));
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public static function fromDlqArguments(array $arguments): self
    {
        return new self((int) ($arguments['executionId'] ?? 0), isset($arguments['correlationId']) ? (string) $arguments['correlationId'] : null);
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return array_map('intval', (array) config('hub.actions.jobs.backoff', [30, 120, 600]));
    }

    public function handle(ExecutionService $executions, CorrelationId $correlation): void
    {
        if ($this->correlationId !== null) {
            $correlation->set($this->correlationId);
        }

        $execution = Execution::query()->find($this->executionId);

        if ($execution === null) {
            Log::warning('VerifyActionJob: Ausführung nicht gefunden.', ['execution_id' => $this->executionId]);

            return;
        }

        if ($execution->getAttribute('status') === ExecutionService::STATUS_VERIFIED) {
            return;
        }

        $executions->verify($execution);
    }

    public function failed(?Throwable $exception): void
    {
        $exception ??= new \RuntimeException('VerifyActionJob ohne Exception fehlgeschlagen.');

        app(DlqService::class)->store(static::class, ['executionId' => $this->executionId, 'correlationId' => $this->correlationId], $exception, null, 'mail_action', (string) $this->queue);
    }
}
