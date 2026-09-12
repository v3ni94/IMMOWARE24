<?php

declare(strict_types=1);

namespace App\Modules\Gmail\Services\Sync;

use App\Modules\Gmail\Contracts\GmailProviderInterface;
use App\Modules\Gmail\Events\WatchExpiringSoon;
use App\Modules\Gmail\Jobs\InitialImportJob;
use App\Modules\Gmail\Models\MailSyncState;
use App\Modules\Mail\Models\Mailbox;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * users.watch je Postfach: Erneuerung, Ablaufspeicherung, Alarm. HTTP 200 auf watch ist nur "requested"; active wird
 * der Watch erst nach dem ersten eingetroffenen Push oder History-Abgleich (SyncStateService::commitHistoryId).
 * Ohne konfiguriertes Topic ist Push "Nicht eingerichtet" und es wird kein Watch angefordert. Die historyId der
 * Watch-Antwort wird nie als Cursor gespeichert: ohne gespeicherte History-ID stößt renew den Erstimport an, der
 * die Profil-History-ID selbst merkt und nach Abschluss committet (sonst liefe der Bestandsimport nie).
 */
final class WatchService
{
    public function __construct(
        private readonly GmailProviderInterface $provider,
        private readonly SyncStateService $states,
        private readonly Repository $config,
    ) {}

    public function isConfigured(): bool
    {
        return trim((string) $this->config->get('hub.gmail.push.topic', '')) !== '';
    }

    /**
     * Erneuert den Watch. Bei Fehler bleibt der alte Ablauf stehen und ein Alarm wird ausgelöst, wenn er die
     * Alarmgrenze unterschreitet.
     */
    public function renew(Mailbox $mailbox): MailSyncState
    {
        $state = $this->states->for($mailbox);

        if (! $this->isConfigured()) {
            return $state;
        }

        try {
            $result = $this->provider->watch(
                (int) $mailbox->getKey(),
                (string) $this->config->get('hub.gmail.push.topic'),
                array_values((array) $this->config->get('hub.gmail.push.watch_label_ids', ['INBOX'])),
            );

            $expiration = CarbonImmutable::createFromTimestampMs((int) $result['expiration'])->utc();

            if ($state->getAttribute('last_history_id') === null && ($state->getAttribute('full_sync_started_at') === null || $state->getAttribute('full_sync_finished_at') !== null)) {
                // Vor dem Speichern des Watch-Zustands: der Erstimport bestätigt den Watch nicht (requested bleibt).
                Log::info('Gmail: Watch angelegt, Erstimport wird angestoßen.', ['mailbox_id' => $mailbox->getKey()]);
                InitialImportJob::dispatch((int) $mailbox->getKey());
            }

            $state->forceFill([
                'watch_expiration' => $expiration,
                'watch_requested_at' => CarbonImmutable::now(),
                'watch_status' => $state->getAttribute('watch_status') === 'active' ? 'active' : 'requested',
                'watch_alerted_at' => null,
            ])->save();
        } catch (Throwable $exception) {
            Log::warning('Gmail: Watch-Erneuerung fehlgeschlagen.', ['mailbox_id' => $mailbox->getKey(), 'reason' => $exception->getMessage()]);
            $state->forceFill(['watch_status' => 'failed'])->save();
            $this->alertIfExpiring($mailbox, $state, 'renew_failed: '.$exception->getMessage());

            throw $exception;
        }

        return $state;
    }

    public function stop(Mailbox $mailbox): void
    {
        $this->provider->stopWatch((int) $mailbox->getKey());
        $this->states->for($mailbox)->forceFill(['watch_status' => 'none', 'watch_expiration' => null])->save();
    }

    /**
     * Alarm, wenn der Watch abgelaufen ist oder die Restlaufzeit unter watch_alert_hours liegt. Höchstens ein Alarm
     * je Ablaufzeitpunkt (watch_alerted_at).
     */
    public function alertIfExpiring(Mailbox $mailbox, ?MailSyncState $state = null, ?string $reason = null): bool
    {
        $state ??= $this->states->for($mailbox);
        $expiration = $state->getAttribute('watch_expiration');
        $threshold = CarbonImmutable::now()->addHours((int) $this->config->get('hub.gmail.push.watch_alert_hours', 24));
        $expiring = ! $expiration instanceof \DateTimeInterface || CarbonImmutable::instance($expiration)->lessThan($threshold);

        if (! $expiring && $reason === null) {
            return false;
        }

        if ($state->getAttribute('watch_alerted_at') !== null && $reason === null) {
            return false;
        }

        $state->forceFill([
            'watch_alerted_at' => CarbonImmutable::now(),
            'watch_status' => $expiration instanceof \DateTimeInterface && CarbonImmutable::instance($expiration)->isPast() ? 'expired' : $state->getAttribute('watch_status'),
        ])->save();

        $expiresAt = $expiration instanceof \DateTimeInterface ? CarbonImmutable::instance($expiration)->toIso8601String() : null;
        Log::warning('Gmail: Watch läuft ab oder ist abgelaufen.', ['mailbox_id' => $mailbox->getKey(), 'expires_at' => $expiresAt, 'reason' => $reason ?? 'expiring']);
        event(new WatchExpiringSoon((int) $mailbox->getKey(), $expiresAt, $reason ?? 'expiring'));

        return true;
    }
}
