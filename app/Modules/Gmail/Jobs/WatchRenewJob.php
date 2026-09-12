<?php

declare(strict_types=1);

namespace App\Modules\Gmail\Jobs;

use App\Modules\Gmail\Events\WatchExpiringSoon;
use App\Modules\Gmail\Jobs\Concerns\GmailJobRetries;
use App\Modules\Gmail\Models\MailSyncState;
use App\Modules\Gmail\Services\Sync\WatchService;
use App\Modules\Mail\Models\Mailbox;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Tägliche Erneuerung des Gmail-Watch (Scheduler im GmailServiceProvider). Ohne Postfach-ID werden alle aktiven
 * Postfächer einzeln eingeplant. Nach der Erneuerung wird zusätzlich der Ablauf geprüft und bei Restlaufzeit unter
 * 24 Stunden ein Alarm ausgelöst; scheitert die Erneuerung, bleibt der alte Ablauf stehen (kein Erfolg vorgetäuscht).
 * Läuft auf mail-high (kurz, keine Massenverarbeitung), damit die Erneuerung nicht hinter Erstimporten auf mail-sync
 * wartet. Scheitert sie endgültig, wird das Postfach als degraded markiert, ein DLQ-Eintrag angelegt und die
 * Erneuerung nach watch_retry_hours erneut eingeplant statt erst am Folgetag.
 */
class WatchRenewJob implements ShouldQueue
{
    use Dispatchable, GmailJobRetries, InteractsWithQueue, Queueable;

    public function __construct(public readonly ?int $mailboxId = null)
    {
        $this->applyGmailRetryConfig((string) config('hub.mail.queues.high', 'mail-high'));
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

    public function failed(Throwable $exception): void
    {
        if ($this->mailboxId === null) {
            return;
        }

        Mailbox::query()->withoutGlobalScopes()->whereKey($this->mailboxId)->whereNotIn('status', ['revoked', 'reauth_required'])->update([
            'status' => 'degraded',
            'status_reason' => mb_substr('Watch-Erneuerung fehlgeschlagen: '.$exception->getMessage(), 0, 200),
            'last_error_at' => CarbonImmutable::now(),
            'last_error_class' => $exception::class,
        ]);

        $state = MailSyncState::query()->where('mailbox_id', $this->mailboxId)->first();
        $expiration = $state?->getAttribute('watch_expiration');
        $expiresAt = $expiration instanceof \DateTimeInterface ? CarbonImmutable::instance($expiration)->toIso8601String() : null;

        $state?->forceFill(['watch_status' => 'failed', 'watch_alerted_at' => CarbonImmutable::now()])->save();
        event(new WatchExpiringSoon($this->mailboxId, $expiresAt, 'renew_failed_final: '.mb_substr($exception->getMessage(), 0, 120)));

        $this->storeInDlq($exception, ['mailboxId' => $this->mailboxId]);

        $retryHours = max(1, (int) config('hub.gmail.push.watch_retry_hours', 6));
        self::dispatch($this->mailboxId)->delay(CarbonImmutable::now()->addHours($retryHours));
    }
}
