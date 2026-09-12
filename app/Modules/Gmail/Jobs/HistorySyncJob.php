<?php

declare(strict_types=1);

namespace App\Modules\Gmail\Jobs;

use App\Modules\Gmail\Contracts\GmailProviderInterface;
use App\Modules\Gmail\Jobs\Concerns\GmailJobRetries;
use App\Modules\Gmail\Services\Sync\MessageImporter;
use App\Modules\Gmail\Services\Sync\SyncStateService;
use App\Modules\Mail\Exceptions\MailRemoteException;
use App\Modules\Mail\Models\Mailbox;
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
 * Verarbeitet messagesAdded, messagesDeleted, labelsAdded, labelsRemoved inklusive SENT und DRAFT. Die neue
 * History-ID wird erst nach vollständiger Verarbeitung der Seite gespeichert. HTTP 404 (History zu alt) startet
 * FullResyncJob als kontrollierten Neuabgleich ohne Löschung. Ohne gespeicherte History-ID wird InitialImportJob
 * angestoßen. Nachrichten je Lauf begrenzt; Fortsetzung über pageToken durch erneutes Einplanen.
 */
class HistorySyncJob implements ShouldQueue
{
    use Dispatchable, GmailJobRetries, InteractsWithQueue, Queueable;

    public function __construct(
        public readonly int $mailboxId,
        public readonly ?string $pageToken = null,
        public readonly ?string $pendingHistoryId = null,
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

        // Lock je Postfach ohne Job-Middleware: Fortsetzung und Neuabgleich werden erst nach Freigabe eingeplant.
        $lock = Cache::lock('mail:gmail:history:'.$this->mailboxId, 600);

        if (! $lock->get()) {
            $this->release(30);

            return;
        }

        $continuation = null;

        try {
            $continuation = $this->run($mailbox, $startHistoryId, $provider, $importer, $states);
        } catch (MailRemoteException $exception) {
            if ($exception->httpStatus === 404) {
                Log::warning('Gmail: History-ID ungültig, kontrollierter Neuabgleich.', ['mailbox_id' => $this->mailboxId, 'start_history_id' => $startHistoryId]);
                $continuation = fn (): mixed => FullResyncJob::dispatch($this->mailboxId);
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
        }
    }

    /**
     * @return (Closure(): mixed)|null Fortsetzung, die nach Freigabe des Locks ausgeführt wird
     */
    private function run(Mailbox $mailbox, string $startHistoryId, GmailProviderInterface $provider, MessageImporter $importer, SyncStateService $states): ?Closure
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

        foreach ($page['history'] as $record) {
            foreach ((array) ($record['messages_added'] ?? []) as $added) {
                $importer->import($mailbox, (string) $added['id']);
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

            if ($touched >= $limit) {
                break;
            }
        }

        $next = $page['next_page_token'];
        $finalHistoryId = $page['history_id'] ?? $this->pendingHistoryId;

        if ($next !== null) {
            // Seite verarbeitet, aber Lauf noch nicht abgeschlossen: History-ID noch nicht speichern, Fortsetzung planen.
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
    }
}
