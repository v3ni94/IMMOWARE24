<?php

declare(strict_types=1);

namespace App\Modules\MailIntegration\Listeners;

use App\Modules\MailUi\Services\MailOpsMetrics;
use App\Modules\Sync\Services\SyncMetrics;
use Illuminate\Log\Events\MessageLogged;

/**
 * Zählt Drosselungen des Pub/Sub-Push-Endpunkts (HTTP 429). Das Modul Mail protokolliert jede Drosselung als
 * Log-Ereignis mail.push.rate_limited; dieser Listener übersetzt es in den Zähler mail_push_429 (SyncMetrics),
 * den das Dashboard (MailOpsMetrics) anzeigt. Kein Zugriff auf Schlüssel oder Adressen, nur die Anzahl.
 */
final class CountPushRateLimit
{
    public function __construct(private readonly SyncMetrics $metrics) {}

    public function handle(MessageLogged $event): void
    {
        if ($event->message !== 'mail.push.rate_limited') {
            return;
        }

        $this->metrics->increment(MailOpsMetrics::PUSH_RATE_LIMITED);
    }
}
