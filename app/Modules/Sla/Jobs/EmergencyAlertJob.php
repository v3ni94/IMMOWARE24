<?php

declare(strict_types=1);

namespace App\Modules\Sla\Jobs;

use App\Modules\Sla\Models\EmergencyAlert;
use App\Modules\Sla\Services\EmergencyQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Zustellung eines Notfallalarms auf Stufe $level (Queue mail-high, unabhängig vom Massenimport). Das Ergebnis der
 * Zustellung wird je Kanal protokolliert; die menschliche Annahme erfolgt getrennt über EmergencyQueue::acknowledge().
 */
class EmergencyAlertJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    public int $timeout = 60;

    public function __construct(
        public readonly int $alertId,
        public readonly int $level,
    ) {
        $this->onQueue((string) config('hub.sla.emergency.queue', 'mail-high'));
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function handle(EmergencyQueue $queue): void
    {
        $alert = EmergencyAlert::query()->allOrganizations()->find($this->alertId);

        if (! $alert instanceof EmergencyAlert) {
            Log::warning('Notfallalarm nicht gefunden.', ['alert_id' => $this->alertId]);

            return;
        }

        $queue->deliver($alert, $this->level);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Zustellung des Notfallalarms endgültig fehlgeschlagen.', ['alert_id' => $this->alertId, 'level' => $this->level, 'error' => $exception::class]);
    }
}
