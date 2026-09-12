<?php

declare(strict_types=1);

namespace App\Modules\Gmail\Jobs\Concerns;

use App\Modules\Gmail\Exceptions\GmailQuotaExceededException;
use App\Modules\Gmail\Exceptions\GmailReauthRequiredException;
use App\Modules\Gmail\Services\GmailApiClient;
use App\Modules\Mail\Exceptions\MailIntegrationNotConfiguredException;
use App\Modules\Mail\Exceptions\MailRemoteException;
use App\Modules\Sync\Support\SyncBackoff;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Retry-Konvention der Gmail-Jobs nach Muster SyncJobRetries: Backoff 30 s, 2 min, 10 min, 30 min plus Jitter,
 * maxExceptions aus hub.mail.jobs.tries, Queue mail-sync. Vorübergehende Fehler (429, 5xx, Quota) werden erneut
 * versucht; reauth_required, nicht eingerichtet und 4xx außer 429 nicht (fail sofort). Ein Fehler erscheint nie als Erfolg.
 */
trait GmailJobRetries
{
    public int $tries = 0;

    public int $timeout = 300;

    public int $maxExceptions = 5;

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return SyncBackoff::seconds(
            base: array_map('intval', (array) config('hub.mail.jobs.backoff', SyncBackoff::BASE_SECONDS)),
            withJitter: (bool) config('hub.sync.jobs.jitter_enabled', true),
        );
    }

    protected function applyGmailRetryConfig(): void
    {
        $this->tries = 0;
        $this->maxExceptions = max(1, (int) config('hub.mail.jobs.tries', 5));
        $this->timeout = max(60, (int) config('hub.mail.jobs.timeout', 300));
        $this->onQueue((string) config('hub.mail.queues.sync', 'mail-sync'));
    }

    /**
     * Entscheidet, ob eine Exception erneut versucht wird. Nicht wiederholbare Fehler beenden den Job über fail().
     */
    protected function handleGmailFailure(Throwable $exception, int $mailboxId): void
    {
        $retryable = $exception instanceof GmailQuotaExceededException
            || ($exception instanceof MailRemoteException && GmailApiClient::isRetryableStatus($exception->httpStatus));

        if ($exception instanceof GmailReauthRequiredException || $exception instanceof MailIntegrationNotConfiguredException) {
            Log::warning('Gmail-Job beendet, Postfach nicht nutzbar.', ['job' => static::class, 'mailbox_id' => $mailboxId, 'reason' => $exception->getMessage()]);
            $this->fail($exception);

            return;
        }

        if ($retryable) {
            Log::notice('Gmail-Job: vorübergehender Fehler, erneuter Versuch.', ['job' => static::class, 'mailbox_id' => $mailboxId, 'reason' => $exception->getMessage()]);

            throw $exception;
        }

        Log::error('Gmail-Job fehlgeschlagen.', ['job' => static::class, 'mailbox_id' => $mailboxId, 'reason' => $exception->getMessage()]);
        $this->fail($exception);
    }
}
