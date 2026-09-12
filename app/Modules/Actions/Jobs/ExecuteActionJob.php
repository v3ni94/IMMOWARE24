<?php

declare(strict_types=1);

namespace App\Modules\Actions\Jobs;

use App\Core\Support\CorrelationId;
use App\Modules\Actions\Models\ActionPlanVersion;
use App\Modules\Actions\Services\ExecutionService;
use App\Modules\Sync\Services\DlqService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Einziger Ausführungspfad eines Planschritts. actionKey = idempotency_key des Schritts (eindeutig je Version und
 * Schritt), Eindeutigkeit in der Queue bis zum Start (ShouldBeUniqueUntilProcessing), Cache-Lock je actionKey während
 * der Ausführung, dazu der Beleg (mail_executions.status running) als zweite Sperre; begrenzte Retries mit Backoff,
 * endgültiges Scheitern in die DLQ (dlq_items). Ein Retry nach Worker-Neustart ruft nie erneut extern auf, wenn ein
 * Ergebnis vorliegt (ExecutionService liest nach).
 */
class ExecuteActionJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries;

    public int $timeout;

    public int $uniqueFor = 600;

    public function __construct(
        public readonly int $versionId,
        public readonly int $stepIndex,
        public readonly string $actionKey,
        public readonly ?int $executedBy = null,
        public readonly ?string $correlationId = null,
    ) {
        $this->tries = max(1, (int) config('hub.actions.jobs.tries', 3));
        $this->timeout = max(30, (int) config('hub.actions.jobs.timeout', 120));
        $this->onQueue((string) config('hub.actions.jobs.queue', 'mail-high'));
    }

    public static function forStep(int $versionId, int $stepIndex, ?int $executedBy = null, ?string $correlationId = null): self
    {
        return new self($versionId, $stepIndex, ExecutionService::idempotencyKey($versionId, $stepIndex), $executedBy, $correlationId);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public static function fromDlqArguments(array $arguments): self
    {
        return self::forStep((int) ($arguments['versionId'] ?? 0), (int) ($arguments['stepIndex'] ?? 0), isset($arguments['executedBy']) ? (int) $arguments['executedBy'] : null, isset($arguments['correlationId']) ? (string) $arguments['correlationId'] : null);
    }

    public function uniqueId(): string
    {
        return $this->actionKey;
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return array_map('intval', (array) config('hub.actions.jobs.backoff', [30, 120, 600]));
    }

    public function handle(ExecutionService $executions, CacheFactory $cache, CorrelationId $correlation): void
    {
        if ($this->correlationId !== null) {
            $correlation->set($this->correlationId);
        }

        $version = ActionPlanVersion::query()->with('plan')->find($this->versionId);

        if ($version === null) {
            Log::warning('ExecuteActionJob: Planversion nicht gefunden.', ['version_id' => $this->versionId]);

            return;
        }

        $store = $cache->store()->getStore();
        $lockOwner = 'job:'.($this->job?->uuid() ?? substr($this->actionKey, 0, 16));
        $run = fn (): mixed => $executions->executeStep($version, $this->stepIndex, $this->executedBy, $lockOwner);

        if ($store instanceof LockProvider) {
            $lock = $store->lock('mail:action:'.$this->actionKey, max(30, (int) config('hub.actions.jobs.lock_seconds', 300)), $lockOwner);

            if (! $lock->get()) {
                if ($executions->isRunningElsewhere($this->versionId, $this->stepIndex)) {
                    // Ein anderer Worker führt den Schritt gerade aus: nicht parallel ausführen, später erneut prüfen.
                    $this->release(30);

                    return;
                }

                // Lock ohne laufenden Beleg (abgestürzter Worker oder Folgejob im selben Prozess): Beleg entscheidet.
                $run();

                return;
            }

            try {
                $run();
            } finally {
                $lock->release();
            }

            return;
        }

        $run();
    }

    public function failed(?Throwable $exception): void
    {
        $exception ??= new \RuntimeException('ExecuteActionJob ohne Exception fehlgeschlagen.');

        app(DlqService::class)->store(static::class, $this->dlqArguments(), $exception, null, 'mail_action', (string) $this->queue);
    }

    /**
     * @return array<string, mixed>
     */
    public function dlqArguments(): array
    {
        return ['versionId' => $this->versionId, 'stepIndex' => $this->stepIndex, 'executedBy' => $this->executedBy, 'correlationId' => $this->correlationId];
    }
}
