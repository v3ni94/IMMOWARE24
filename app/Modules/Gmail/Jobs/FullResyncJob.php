<?php

declare(strict_types=1);

namespace App\Modules\Gmail\Jobs;

use App\Modules\Gmail\Contracts\GmailProviderInterface;
use App\Modules\Gmail\Events\HistoryGapDetected;
use App\Modules\Gmail\Jobs\Concerns\GmailJobRetries;
use App\Modules\Gmail\Models\MailSyncState;
use App\Modules\Gmail\Services\Sync\LabelReconciler;
use App\Modules\Gmail\Services\Sync\SyncStateService;
use App\Modules\Mail\Models\Mailbox;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Kontrollierter Neuabgleich nach ungültiger History-ID (404): merkt die aktuelle History-ID aus dem Profil VOR dem
 * Abgleich, listet INBOX, SENT und DRAFT seitenweise, importiert nur fehlende Nachrichten (Lückenerkennung, gapCount),
 * löscht nichts und speichert die neue History-ID erst nach Abschluss. Fortsetzung über Label-Index und pageToken.
 */
class FullResyncJob implements ShouldQueue
{
    use Dispatchable, GmailJobRetries, InteractsWithQueue, Queueable;

    public function __construct(
        public readonly int $mailboxId,
        public readonly ?string $targetHistoryId = null,
        public readonly int $labelIndex = 0,
        public readonly ?string $pageToken = null,
        public readonly int $gapCount = 0,
    ) {
        $this->applyGmailRetryConfig();
    }

    /**
     * Lock je Postfach ohne Job-Middleware: die Fortsetzung wird erst nach Freigabe des Locks eingeplant.
     */
    public function handle(GmailProviderInterface $provider, LabelReconciler $reconciler, SyncStateService $states): void
    {
        $mailbox = Mailbox::query()->withoutGlobalScopes()->find($this->mailboxId);

        if (! $mailbox instanceof Mailbox || ! (bool) $mailbox->getAttribute('import_enabled')) {
            return;
        }

        $lock = Cache::lock('mail:gmail:resync:'.$this->mailboxId, 900);

        if (! $lock->get()) {
            $this->release(60);

            return;
        }

        $continuation = null;

        try {
            $continuation = $this->run($mailbox, $provider, $reconciler, $states);
        } catch (Throwable $exception) {
            $this->handleGmailFailure($exception, $this->mailboxId);
        } finally {
            $lock->release();
        }

        if ($continuation !== null) {
            $continuation();
        }
    }

    /**
     * @return (Closure(): mixed)|null Fortsetzung, die nach Freigabe des Locks ausgeführt wird
     */
    private function run(Mailbox $mailbox, GmailProviderInterface $provider, LabelReconciler $reconciler, SyncStateService $states): ?Closure
    {
        $target = $this->targetHistoryId ?? $provider->getProfile($this->mailboxId)['history_id'];
        $labels = array_values((array) config('hub.gmail.sync.reconcile_label_ids', ['INBOX', 'SENT', 'DRAFT']));
        $state = $states->for($mailbox);

        if ($this->targetHistoryId === null) {
            $state->forceFill(['full_sync_started_at' => CarbonImmutable::now(), 'full_sync_finished_at' => null])->save();
        }

        $label = $labels[$this->labelIndex] ?? null;

        if ($label === null) {
            $this->finish($mailbox, $state, $target);

            return null;
        }

        $result = $reconciler->reconcileLabel(
            $mailbox,
            (string) $label,
            $this->pageToken,
            (int) config('hub.gmail.sync.reconcile_page_size', 100),
            max(1, (int) config('hub.gmail.sync.max_messages_per_run', 200)),
        );

        $gaps = $this->gapCount + $result['imported'];

        if ($result['next_page_token'] !== null) {
            $state->forceFill(['full_sync_cursor' => $label.':'.$result['next_page_token']])->save();

            return fn (): mixed => self::dispatch($this->mailboxId, $target, $this->labelIndex, $result['next_page_token'], $gaps);
        }

        if (isset($labels[$this->labelIndex + 1])) {
            return fn (): mixed => self::dispatch($this->mailboxId, $target, $this->labelIndex + 1, null, $gaps);
        }

        $this->finish($mailbox, $state, $target, $gaps);

        return null;
    }

    private function finish(Mailbox $mailbox, MailSyncState $state, string $target, int $gaps = 0): void
    {
        $state->forceFill([
            'full_sync_cursor' => null,
            'full_sync_finished_at' => CarbonImmutable::now(),
            'last_full_resync_at' => CarbonImmutable::now(),
            'last_resync_gap_count' => $gaps,
            // Zielstand erzwingen: die alte History-ID ist ungültig, auch wenn sie numerisch größer wäre.
            'last_history_id' => $target,
            'history_id_updated_at' => CarbonImmutable::now(),
        ])->save();

        if ($mailbox->getAttribute('status') === 'degraded') {
            $mailbox->forceFill(['status' => 'active', 'status_reason' => null])->save();
        }

        event(new HistoryGapDetected($this->mailboxId, $gaps));
    }

    public function failed(Throwable $exception): void
    {
        Mailbox::query()->withoutGlobalScopes()->whereKey($this->mailboxId)->whereNotIn('status', ['revoked', 'reauth_required'])->update([
            'status' => 'degraded',
            'status_reason' => mb_substr('Neuabgleich fehlgeschlagen: '.$exception->getMessage(), 0, 200),
            'last_error_at' => now(),
            'last_error_class' => $exception::class,
        ]);
    }
}
