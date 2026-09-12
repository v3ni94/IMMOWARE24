<?php

declare(strict_types=1);

namespace App\Modules\Sla\Jobs;

use App\Modules\Sla\Services\EmergencyQueue;
use App\Modules\Sla\Services\SlaClockService;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Minütlich (Scheduler im SlaServiceProvider, Queue mail-high): alle aktiven Uhren neu bewerten und fällige
 * Notfall-Eskalationen auslösen. Läuft unabhängig von Importjobs auf mail-sync.
 */
class SlaCheckJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct()
    {
        $this->onQueue((string) config('hub.sla.emergency.queue', 'mail-high'));
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 120, 600];
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('mail:sla:check'))->expireAfter(180)->dontRelease()];
    }

    public function handle(SlaClockService $clocks, EmergencyQueue $emergencies): void
    {
        $now = CarbonImmutable::now();
        $evaluated = $clocks->evaluateAll($now);
        $escalated = $emergencies->escalateDue($now);

        Log::info('SLA-Prüfung ausgeführt.', ['clocks' => $evaluated, 'escalations' => $escalated]);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('SLA-Prüfung fehlgeschlagen.', ['error' => $exception::class, 'message' => $exception->getMessage()]);
    }
}
