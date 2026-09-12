<?php

declare(strict_types=1);

namespace App\Modules\Gmail\Jobs;

use App\Modules\Gmail\Contracts\GmailProviderInterface;
use App\Modules\Gmail\Jobs\Concerns\GmailJobRetries;
use App\Modules\Gmail\Models\MailSyncState;
use App\Modules\Gmail\Services\Sync\MessageImporter;
use App\Modules\Gmail\Services\Sync\SyncStateService;
use App\Modules\Mail\Exceptions\MailRemoteException;
use App\Modules\Mail\Models\Mailbox;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Inkrementeller Abgleich über users.history.list ab der zuletzt gespeicherten History-ID (nie ab der aus dem Push).
 * Verarbeitet messagesAdded, messagesDeleted, labelsAdded, labelsRemoved inklusive SENT und DRAFT. Die History-ID
 * wird nur bis zum letzten vollständig verarbeiteten Eintrag fortgeschrieben: bei Erreichen von max_messages_per_run
 * endet der Lauf hinter dem letzten vollständigen Eintrag und plant sich erneut ein (kein Überspringen von
 * Einträgen); liefert Gmail für eine hinzugefügte Nachricht 404 (Eventual Consistency), bleibt der Cursor vor diesem
 * Eintrag stehen und der Lauf wird verzögert wiederholt (history_missing_retries). HTTP 404 auf history.list
 * (History zu alt) startet FullResyncJob als kontrollierten Neuabgleich ohne Löschung, sofern keiner läuft. Ohne
 * gespeicherte History-ID wird InitialImportJob angestoßen. Ein belegter Lock führt nicht zu einer Release-Schleife:
 * der Push wird als ausstehend vermerkt und vom laufenden Abgleich nach Abschluss als Folgelauf nachgeholt.
 */
class HistorySyncJob implements ShouldQueue
{
    use Dispatchable, GmailJobRetries, InteractsWithQueue, Queueable;

    public function __construct(
        public readonly int $mailboxId,
        public readonly ?string $pageToken = null,
        public readonly ?string $pendingHistoryId = null,
        public readonly int $missingRetry = 0,
    ) {
        $this->applyGmailRetryConfig();
    }

    public function handle(GmailProviderInterface $provider, MessageImporter $importer, SyncStateService $states): void
    {
        $mailbox = Mailbox::query()->withoutGlobalScopes()->find($this->mailboxId);

        if (! $mailbox instanceof Mailbox || ! (bool) $mailbox->getAttribute('import_enabled')) {
            return;
        }

        if (in_array($mailbox->getAttribute('status'), ['revoked', 'reauth_required', 'not_configured'], true)) {
            Log::info('Gmail: History-Abgleich übersprungen, Postfach nicht autorisiert.', ['mailbox_id' => $this->mailboxId, 'status' => $mailbox->getAttribute('status')]);

            return;
        }

        $state = $states->for($mailbox);
        $startHistoryId = $state->getAttribute('last_history_id');

        if (! is_string($startHistoryId) || $startHistoryId === '') {
            if ($state->getAttribute('full_sync_started_at') === null || $state->getAttribute('full_sync_finished_at') !== null) {
                InitialImportJob::dispatch($this->mailboxId);
            }

            return;
        }

        // Lock je Postfach ohne Job-Middleware, TTL an den Job-Timeout gekoppelt (kein Dauer-Lock nach Worker-Abbruch).
        $lock = Cache::lock(self::lockKey($this->mailboxId), $this->timeout + 60);

        if (! $lock->get()) {
            // Kein release(): der laufende Abgleich holt den Push als Folgelauf nach (pending-Marke).
            Cache::put(self::pendingKey($this->mailboxId), 1, $this->timeout + 120);

            return;
        }

        $continuation = null;

        try {
            $continuation = $this->run($mailbox, $state, $startHistoryId, $provider, $importer, $states);
        } catch (MailRemoteException $exception) {
            if ($exception->httpStatus === 404) {
                $continuation = $this->resyncContinuation($state, $startHistoryId);
            } else {
                $this->handleGmailFailure($exception, $this->mailboxId);
            }
        } catch (Throwable $exception) {
            $this->handleGmailFailure($exception, $this->mailboxId);
        } finally {
            $lock->release();
        }

        if ($continuation !== null) {
            $continuation();

            return;
        }

        if (Cache::pull(self::pendingKey($this->mailboxId)) !== null) {
            self::dispatch($this->mailboxId);
        }
    }

    public static function lockKey(int $mailboxId): string
    {
        return 'mail:gmail:history:'.$mailboxId;
    }

    public static function pendingKey(int $mailboxId): string
    {
        return 'mail:gmail:history:pending:'.$mailboxId;
    }

    /**
     * @return (Closure(): mixed)|null Fortsetzung, die nach Freigabe des Locks ausgeführt wird
     */
    private function run(Mailbox $mailbox, MailSyncState $state, string $startHistoryId, GmailProviderInterface $provider, MessageImporter $importer, SyncStateService $states): ?Closure
    {
        $page = $provider->listHistoryFiltered(
            $this->mailboxId,
            $startHistoryId,
            (array) config('hub.gmail.sync.history_types', []),
            null,
            $this->pageToken,
            (int) config('hub.gmail.sync.page_size', 100),
        );

        $touched = 0;
        $limit = max(1, (int) config('hub.gmail.sync.max_messages_per_run', 200));
        $lastCompleteId = null;   // letzter vollständig verarbeiteter Eintrag (Cursor darf bis hierhin)
        $beforeMissingId = null;  // Eintrag vor dem ersten Eintrag mit fehlender Nachricht (404)
        $missing = [];
        $stoppedAtLimit = false;
        $records = array_values($page['history']);

        foreach ($records as $index => $record) {
            $recordMissing = [];

            foreach ((array) ($record['messages_added'] ?? []) as $added) {
                if ($importer->import($mailbox, (string) $added['id']) === null) {
                    $recordMissing[] = (string) $added['id'];
                }

                $touched++;
            }

            foreach ((array) ($record['messages_deleted'] ?? []) as $deleted) {
                $importer->markDeleted($mailbox, (string) $deleted['id']);
            }

            foreach ((array) ($record['labels_added'] ?? []) as $labelled) {
                $this->applyLabels($mailbox, $importer, $labelled, add: true);
            }

            foreach ((array) ($record['labels_removed'] ?? []) as $labelled) {
                $this->applyLabels($mailbox, $importer, $labelled, add: false);
            }

            if ($recordMissing !== [] && $missing === []) {
                $beforeMissingId = $lastCompleteId;
            }

            $missing = array_merge($missing, $recordMissing);
            $lastCompleteId = (string) $record['id'];

            if ($touched >= $limit && isset($records[$index + 1])) {
                $stoppedAtLimit = true;
                break;
            }
        }

        $maxRetries = max(0, (int) config('hub.gmail.sync.history_missing_retries', 3));

        if ($missing !== [] && $this->missingRetry < $maxRetries) {
            // Cursor nur bis vor den ersten fehlenden Eintrag, dann verzögert erneut ab dort (Eventual Consistency).
            if ($beforeMissingId !== null) {
                $states->commitHistoryId($mailbox, $beforeMissingId);
            }

            Log::notice('Gmail: Nachrichten aus History noch nicht abrufbar, Wiederholung eingeplant.', ['mailbox_id' => $this->mailboxId, 'missing' => count($missing), 'retry' => $this->missingRetry + 1]);
            $delay = max(1, (int) config('hub.gmail.sync.history_missing_retry_seconds', 120));

            return fn (): mixed => self::dispatch($this->mailboxId, null, null, $this->missingRetry + 1)->delay(CarbonImmutable::now()->addSeconds($delay));
        }

        if ($missing !== []) {
            Log::warning('Gmail: Nachrichten aus History nach allen Versuchen nicht abrufbar, gelten als gelöscht.', ['mailbox_id' => $this->mailboxId, 'gmail_message_ids' => $missing]);
        }

        if ($stoppedAtLimit && $lastCompleteId !== null) {
            // Begrenzung erreicht: Seite nicht als abgeschlossen behandeln, ab dem letzten vollständigen Eintrag weiter.
            $states->commitHistoryId($mailbox, $lastCompleteId);

            return fn (): mixed => self::dispatch($this->mailboxId);
        }

        $next = $page['next_page_token'];
        $finalHistoryId = $page['history_id'] ?? $this->pendingHistoryId;

        if ($next !== null) {
            // Seite verarbeitet, Lauf noch nicht abgeschlossen: Cursor nur bis zum letzten Eintrag, Fortsetzung planen.
            if ($lastCompleteId !== null) {
                $states->commitHistoryId($mailbox, $lastCompleteId);
            }

            return fn (): mixed => self::dispatch($this->mailboxId, $next, $finalHistoryId);
        }

        if (is_string($finalHistoryId) && $finalHistoryId !== '') {
            $states->commitHistoryId($mailbox, $finalHistoryId);
        }

        if ($mailbox->getAttribute('status') === 'degraded') {
            $mailbox->forceFill(['status' => 'active', 'status_reason' => null])->save();
        }

        return null;
    }

    /**
     * 404 auf history.list: Neuabgleich nur einmal anstoßen. Läuft bereits einer (full_sync_started_at gesetzt,
     * nicht beendet und jünger als die Lock-Laufzeit), wird nur protokolliert; weitere Pushes lösen keinen zweiten aus.
     */
    private function resyncContinuation(MailSyncState $state, string $startHistoryId): ?Closure
    {
        $state->refresh();
        $startedAt = $state->getAttribute('full_sync_started_at');
        $running = $startedAt instanceof \DateTimeInterface
            && $state->getAttribute('full_sync_finished_at') === null
            && CarbonImmutable::instance($startedAt)->greaterThan(CarbonImmutable::now()->subSeconds($this->timeout * 4));

        if ($running) {
            Log::info('Gmail: History-ID ungültig, Neuabgleich läuft bereits.', ['mailbox_id' => $this->mailboxId, 'start_history_id' => $startHistoryId]);

            return null;
        }

        Log::warning('Gmail: History-ID ungültig, kontrollierter Neuabgleich.', ['mailbox_id' => $this->mailboxId, 'start_history_id' => $startHistoryId]);

        return fn (): mixed => FullResyncJob::dispatch($this->mailboxId);
    }

    /**
     * @param  array{id: string, thread_id: string, label_ids: array<int, string>}  $entry
     */
    private function applyLabels(Mailbox $mailbox, MessageImporter $importer, array $entry, bool $add): void
    {
        $labels = array_values(array_map('strval', (array) ($entry['label_ids'] ?? [])));
        $message = $importer->applyLabelChange($mailbox, (string) $entry['id'], $add ? $labels : [], $add ? [] : $labels);

        if ($message === null && $add && array_intersect($labels, ['INBOX', 'SENT', 'DRAFT']) !== []) {
            // Label auf unbekannter Nachricht (z. B. aus Entwurf gesendet): jetzt importieren.
            $importer->import($mailbox, (string) $entry['id']);
        }
    }

    public function failed(Throwable $exception): void
    {
        Mailbox::query()->withoutGlobalScopes()->whereKey($this->mailboxId)->whereNotIn('status', ['revoked', 'reauth_required'])->update([
            'status' => 'degraded',
            'status_reason' => mb_substr('History-Abgleich fehlgeschlagen: '.$exception->getMessage(), 0, 200),
            'last_error_at' => now(),
            'last_error_class' => $exception::class,
        ]);

        $this->storeInDlq($exception, ['mailboxId' => $this->mailboxId, 'pageToken' => $this->pageToken, 'pendingHistoryId' => $this->pendingHistoryId, 'missingRetry' => $this->missingRetry]);
    }
}
