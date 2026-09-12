<?php

declare(strict_types=1);

namespace App\Modules\Gmail\Jobs;

use App\Modules\Gmail\Jobs\Concerns\GmailJobRetries;
use App\Modules\Gmail\Services\SendReconciliationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Verarbeitet fällige Versandabgleiche (Scheduler, minütlich). Fehler einzelner Einträge werden im Service protokolliert
 * und beim nächsten Lauf erneut versucht.
 */
class SendReconciliationJob implements ShouldQueue
{
    use Dispatchable, GmailJobRetries, InteractsWithQueue, Queueable;

    public function __construct()
    {
        $this->applyGmailRetryConfig();
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('mail:gmail:send-reconcile'))->releaseAfter(30)->expireAfter(300)];
    }

    public function handle(SendReconciliationService $service): void
    {
        $service->processDue();
    }
}
