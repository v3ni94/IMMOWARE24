<?php

declare(strict_types=1);

namespace App\Modules\Gmail\Jobs;

use App\Modules\Gmail\Jobs\Concerns\GmailJobRetries;
use App\Modules\Gmail\Services\Sync\LabelReconciler;
use App\Modules\Gmail\Services\Sync\SyncStateService;
use App\Modules\Gmail\Services\Sync\WatchService;
use App\Modules\Mail\Models\Mailbox;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/**
 * Regelmäßiger Abgleich (Polling-Fallback, Push ist nicht garantiert): erste Seite von INBOX, SENT und DRAFT gegen
 * die Datenbank, fehlende Nachrichten importieren, Lücken zählen. Löscht nie, ändert die History-ID nicht.
 * Ohne Postfach-ID werden alle aktiven Postfächer mit Import einzeln eingeplant. Zusätzlich prüft jeder Lauf den
 * Ablauf des Watch (alertIfExpiring), damit ein Watch mit Restlaufzeit unter watch_alert_hours unabhängig vom
 * täglichen Erneuerungslauf gemeldet wird.
 */
class ReconcileJob implements ShouldQueue
{
    use Dispatchable, GmailJobRetries, InteractsWithQueue, Queueable;

    public function __construct(public readonly ?int $mailboxId = null)
    {
        $this->applyGmailRetryConfig();
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return $this->mailboxId === null ? [] : [(new WithoutOverlapping('mail:gmail:reconcile:'.$this->mailboxId))->releaseAfter(60)->expireAfter(600)];
    }

    public function handle(LabelReconciler $reconciler, SyncStateService $states, WatchService $watches): void
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

        if (! $mailbox instanceof Mailbox || ! (bool) $mailbox->getAttribute('import_enabled') || ! in_array($mailbox->getAttribute('status'), ['active', 'degraded'], true)) {
            return;
        }

        $state = $states->for($mailbox);

        if ($watches->isConfigured() && $state->getAttribute('watch_expiration') !== null) {
            $watches->alertIfExpiring($mailbox, $state);
        }

        if ($state->getAttribute('last_history_id') === null) {
            // Vor dem Erstimport gibt es nichts abzugleichen.
            return;
        }

        try {
            $gaps = 0;

            foreach ((array) config('hub.gmail.sync.reconcile_label_ids', ['INBOX', 'SENT', 'DRAFT']) as $label) {
                $result = $reconciler->reconcileLabel($mailbox, (string) $label, null, (int) config('hub.gmail.sync.reconcile_page_size', 100), max(1, (int) config('hub.gmail.sync.max_messages_per_run', 200)));
                $gaps += $result['imported'];
            }

            $state->forceFill(['last_reconcile_at' => CarbonImmutable::now(), 'last_reconcile_gap_count' => $gaps])->save();
        } catch (Throwable $exception) {
            $this->handleGmailFailure($exception, $this->mailboxId);
        }
    }

    public function failed(Throwable $exception): void
    {
        if ($this->mailboxId === null) {
            return;
        }

        Mailbox::query()->withoutGlobalScopes()->whereKey($this->mailboxId)->whereNotIn('status', ['revoked', 'reauth_required'])->update([
            'status' => 'degraded',
            'status_reason' => mb_substr('Abgleich fehlgeschlagen: '.$exception->getMessage(), 0, 200),
            'last_error_at' => now(),
            'last_error_class' => $exception::class,
        ]);

        $this->storeInDlq($exception, ['mailboxId' => $this->mailboxId]);
    }
}
