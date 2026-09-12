<?php

declare(strict_types=1);

namespace App\Modules\Sync\Jobs;

use App\Modules\Sync\Support\Heartbeat;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Lebenszeichen des Queue-Workers: schreibt den Zeitstempel in storage/framework/worker-heartbeat. Läuft auf der Queue
 * high, ein Versuch, keine Wiederholung (ein verpasster Schlag ist die Information). ShouldBeUnique verhindert,
 * dass sich bei stehendem Worker minütlich neue Einträge stapeln.
 */
final class WorkerHeartbeatJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public int $uniqueFor = 120;

    public function __construct()
    {
        $this->onQueue('high');
    }

    public function handle(Heartbeat $heartbeat): void
    {
        $heartbeat->beat(Heartbeat::WORKER);
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('hub.heartbeat.worker_failed', ['error' => $exception?->getMessage()]);
    }
}
