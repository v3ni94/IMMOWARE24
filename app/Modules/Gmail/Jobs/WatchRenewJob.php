<?php

declare(strict_types=1);

namespace App\Modules\Gmail\Jobs;

use App\Modules\Gmail\Jobs\Concerns\GmailJobRetries;
use App\Modules\Gmail\Services\Sync\WatchService;
use App\Modules\Mail\Models\Mailbox;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Tägliche Erneuerung des Gmail-Watch (Scheduler im GmailServiceProvider). Ohne Postfach-ID werden alle aktiven
 * Postfächer einzeln eingeplant. Nach der Erneuerung wird zusätzlich der Ablauf geprüft und bei Restlaufzeit unter
 * 24 Stunden ein Alarm ausgelöst; scheitert die Erneuerung, bleibt der alte Ablauf stehen (kein Erfolg vorgetäuscht).
 */
class WatchRenewJob implements ShouldQueue
{
    use Dispatchable, GmailJobRetries, InteractsWithQueue, Queueable;

    public function __construct(public readonly ?int $mailboxId = null)
    {
        $this->applyGmailRetryConfig();
    }

    public function handle(WatchService $watches): void
    {
        if ($this->mailboxId === null) {
            Mailbox::query()->withoutGlobalScopes()
                ->where('import_enabled', true)
                ->whereIn('status', ['active', 'degraded'])
                ->pluck('id')
                ->each(static function (mixed $id): void {
                    self::dispatch((int) $id);
                });

            return;
        }

        $mailbox = Mailbox::query()->withoutGlobalScopes()->find($this->mailboxId);

        if (! $mailbox instanceof Mailbox || ! in_array($mailbox->getAttribute('status'), ['active', 'degraded'], true)) {
            return;
        }

        if (! $watches->isConfigured()) {
            return;
        }

        try {
            $state = $watches->renew($mailbox);
            $watches->alertIfExpiring($mailbox, $state);
        } catch (Throwable $exception) {
            $this->handleGmailFailure($exception, $this->mailboxId);
        }
    }
}
