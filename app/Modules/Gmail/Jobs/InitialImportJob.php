<?php

declare(strict_types=1);

namespace App\Modules\Gmail\Jobs;

use App\Modules\Gmail\Contracts\GmailProviderInterface;
use App\Modules\Gmail\Jobs\Concerns\GmailJobRetries;
use App\Modules\Gmail\Models\MailSyncState;
use App\Modules\Gmail\Services\Sync\MessageImporter;
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
 * Erstimport eines Postfachs, begrenzt über Anzahl und Zeitraum (config hub.gmail.sync.initial_import_*), seitenweise
 * über pageToken (full_sync_cursor). Verlustfrei: die historyId aus dem Profil wird VOR dem Import gemerkt und nach
 * Abschluss als Startpunkt der History gespeichert; alles, was während des Imports eintrifft, holt der erste
 * HistorySyncJob nach. Nach max_messages_per_run plant sich der Job selbst erneut ein (kein Endloslauf).
 */
class InitialImportJob implements ShouldQueue
{
    use Dispatchable, GmailJobRetries, InteractsWithQueue, Queueable;

    public function __construct(
        public readonly int $mailboxId,
        public readonly ?string $pageToken = null,
        public readonly ?string $startHistoryId = null,
        public readonly int $importedSoFar = 0,
    ) {
        $this->applyGmailRetryConfig();
    }

    /**
     * Lock je Postfach ohne Job-Middleware, damit die Fortsetzung (nächste Seite) erst nach Freigabe des Locks
     * eingeplant wird; sonst würde sie bei synchroner Queue oder kurzer Verzögerung am eigenen Lock scheitern.
     */
    public function handle(GmailProviderInterface $provider, MessageImporter $importer, SyncStateService $states): void
    {
        $mailbox = Mailbox::query()->withoutGlobalScopes()->find($this->mailboxId);

        if (! $mailbox instanceof Mailbox || ! (bool) $mailbox->getAttribute('import_enabled')) {
            return;
        }

        $lock = Cache::lock('mail:gmail:import:'.$this->mailboxId, $this->timeout + 60);

        if (! $lock->get()) {
            $this->release(60);

            return;
        }

        $continuation = null;

        try {
            $continuation = $this->run($mailbox, $provider, $importer, $states);
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
     * @return Closure(): mixed Fortsetzung, die nach Freigabe des Locks ausgeführt wird
     */
    private function run(Mailbox $mailbox, GmailProviderInterface $provider, MessageImporter $importer, SyncStateService $states): Closure
    {
        $state = $states->for($mailbox);
        $startHistoryId = $this->startHistoryId;

        if ($startHistoryId === null) {
            // Vor dem ersten Abruf: aktuellen History-Stand merken (Lücke zwischen Import und History ausgeschlossen).
            $startHistoryId = $provider->getProfile($this->mailboxId)['history_id'];
            $state->forceFill(['full_sync_started_at' => CarbonImmutable::now(), 'full_sync_finished_at' => null, 'full_sync_cursor' => null])->save();
        }

        $maxTotal = max(1, (int) config('hub.gmail.sync.initial_import_max_messages', 500));
        $perRun = max(1, (int) config('hub.gmail.sync.max_messages_per_run', 200));
        $pageSize = min((int) config('hub.gmail.sync.page_size', 100), $maxTotal - $this->importedSoFar, $perRun);
        $days = (int) config('hub.gmail.sync.initial_import_days', 90);
        $importFrom = $mailbox->getAttribute('import_from');
        $query = null;

        if ($importFrom instanceof \DateTimeInterface) {
            $query = 'after:'.$importFrom->getTimestamp();
        } elseif ($days > 0) {
            $query = 'after:'.CarbonImmutable::now()->subDays($days)->getTimestamp();
        }

        $list = $provider->listMessages($this->mailboxId, [
            'label_ids' => (array) config('hub.gmail.sync.initial_import_label_ids', ['INBOX', 'SENT']),
            'max_results' => max(1, $pageSize),
            'page_token' => $this->pageToken,
            'query' => $query,
        ]);

        $imported = $this->importedSoFar;

        foreach ($list['messages'] as $reference) {
            $importer->import($mailbox, (string) $reference['id']);
            $imported++;

            if ($imported >= $maxTotal) {
                break;
            }
        }

        $next = $list['next_page_token'];

        if ($next !== null && $imported < $maxTotal) {
            $state->forceFill(['full_sync_cursor' => $next])->save();

            return fn (): mixed => self::dispatch($this->mailboxId, $next, $startHistoryId, $imported);
        }

        $state->forceFill(['full_sync_cursor' => null, 'full_sync_finished_at' => CarbonImmutable::now()])->save();
        $states->commitHistoryId($mailbox, $startHistoryId, incremental: false);

        if ($mailbox->getAttribute('status') === 'configured') {
            $mailbox->forceFill(['status' => 'active'])->save();
        }

        return fn (): mixed => HistorySyncJob::dispatch($this->mailboxId);
    }

    /**
     * Endgültiges Scheitern: full_sync_started_at zurücksetzen, damit der nächste HistorySyncJob (z. B. nach erneuter
     * Autorisierung) den Erstimport wieder anstößt statt still zurückzukehren; die History-ID wurde nicht gespeichert.
     */
    public function failed(Throwable $exception): void
    {
        Mailbox::query()->withoutGlobalScopes()->whereKey($this->mailboxId)->update([
            'status' => 'degraded',
            'status_reason' => mb_substr('Erstimport fehlgeschlagen: '.$exception->getMessage(), 0, 200),
            'last_error_at' => CarbonImmutable::now(),
            'last_error_class' => $exception::class,
        ]);

        MailSyncState::query()->where('mailbox_id', $this->mailboxId)->whereNull('full_sync_finished_at')->update([
            'full_sync_started_at' => null,
            'full_sync_cursor' => null,
        ]);

        $this->storeInDlq($exception, ['mailboxId' => $this->mailboxId, 'pageToken' => $this->pageToken, 'startHistoryId' => $this->startHistoryId, 'importedSoFar' => $this->importedSoFar]);
    }
}
