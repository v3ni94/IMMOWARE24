<?php

declare(strict_types=1);

namespace App\Modules\Gmail\Services\Push;

use App\Modules\Gmail\Jobs\HistorySyncJob;
use App\Modules\Gmail\Models\MailSyncState;
use App\Modules\Gmail\Models\PushEvent;
use App\Modules\Mail\Models\Mailbox;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Verarbeitet eine Pub/Sub-Push-Nutzlast: dekodiert message.data (emailAddress, historyId), speichert das Ereignis
 * mit Dedup über pubsub_message_id (unique), ordnet das Postfach über die Adresse zu und plant HistorySyncJob ein.
 * Die historyId aus dem Push ist nur ein Signal; der Abgleich startet bei der zuletzt gespeicherten History-ID.
 * Dedup gilt nur für erfolgreich verarbeitete Ereignisse (queued, ignored): eine erneute Zustellung nach
 * fehlgeschlagenem Einplanen (outcome failed oder offen) plant den Abgleich erneut ein. Nur Unique-Verletzungen
 * (SQLSTATE 23000) gelten als Duplikat; andere Datenbankfehler werden weitergereicht, damit Pub/Sub erneut zustellt.
 */
final class PushEventService
{
    public const string OUTCOME_QUEUED = 'queued';

    public const string OUTCOME_DUPLICATE = 'duplicate';

    public const string OUTCOME_IGNORED = 'ignored';

    public const string OUTCOME_FAILED = 'failed';

    public const string OUTCOME_INVALID = 'invalid';

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload, string $authResult): string
    {
        $message = (array) ($payload['message'] ?? []);
        $pubsubMessageId = (string) ($message['messageId'] ?? $message['message_id'] ?? '');
        $data = $message['data'] ?? null;

        if ($pubsubMessageId === '' || ! is_string($data)) {
            return self::OUTCOME_INVALID;
        }

        $decoded = json_decode((string) base64_decode(strtr($data, '-_', '+/'), false), true);

        if (! is_array($decoded) || ! isset($decoded['emailAddress'], $decoded['historyId'])) {
            return self::OUTCOME_INVALID;
        }

        $email = strtolower(trim((string) $decoded['emailAddress']));
        $historyId = (string) $decoded['historyId'];
        $publishTime = isset($message['publishTime']) ? $this->parseTime((string) $message['publishTime']) : null;

        $mailbox = Mailbox::query()->withoutGlobalScopes()->where('email_address', $email)->first();

        try {
            $event = PushEvent::query()->create([
                'mailbox_id' => $mailbox?->getKey(),
                'pubsub_message_id' => mb_substr($pubsubMessageId, 0, 64),
                'email_address' => mb_substr($email, 0, 254),
                'history_id' => mb_substr($historyId, 0, 32),
                'publish_time' => $publishTime,
                'received_at' => CarbonImmutable::now(),
                'auth_result' => mb_substr($authResult, 0, 16),
            ]);
        } catch (QueryException $exception) {
            if (! $this->isUniqueViolation($exception)) {
                // Deadlock, Verbindungsabbruch: kein Duplikat, Pub/Sub soll erneut zustellen.
                throw $exception;
            }

            $existing = PushEvent::query()->where('pubsub_message_id', mb_substr($pubsubMessageId, 0, 64))->first();

            if (! $existing instanceof PushEvent || in_array($existing->getAttribute('outcome'), [self::OUTCOME_QUEUED, self::OUTCOME_IGNORED, self::OUTCOME_DUPLICATE], true)) {
                Log::info('Gmail Push: Duplikat ignoriert.', ['pubsub_message_id' => $pubsubMessageId]);

                return self::OUTCOME_DUPLICATE;
            }

            // Erste Zustellung ist beim Einplanen gescheitert: erneut versuchen statt den Push endgültig zu verwerfen.
            Log::notice('Gmail Push: erneute Zustellung nach fehlgeschlagenem Einplanen.', ['pubsub_message_id' => $pubsubMessageId, 'previous_outcome' => $existing->getAttribute('outcome')]);
            $event = $existing;
        }

        if (! $mailbox instanceof Mailbox) {
            Log::warning('Gmail Push: unbekanntes Postfach.', ['email_hash' => hash('sha256', $email)]);
            $event->forceFill(['outcome' => self::OUTCOME_IGNORED, 'processed_at' => CarbonImmutable::now()])->save();

            return self::OUTCOME_IGNORED;
        }

        try {
            HistorySyncJob::dispatch((int) $mailbox->getKey());

            MailSyncState::query()->where('mailbox_id', $mailbox->getKey())->where('watch_status', 'requested')->update([
                'watch_status' => 'active',
                'watch_confirmed_at' => CarbonImmutable::now(),
            ]);

            $event->forceFill(['outcome' => self::OUTCOME_QUEUED, 'processed_at' => CarbonImmutable::now()])->save();

            return self::OUTCOME_QUEUED;
        } catch (Throwable $exception) {
            Log::error('Gmail Push: Einplanen fehlgeschlagen.', ['mailbox_id' => $mailbox->getKey(), 'reason' => $exception->getMessage()]);
            $event->forceFill(['outcome' => self::OUTCOME_FAILED, 'processed_at' => CarbonImmutable::now()])->save();

            throw $exception;
        }
    }

    /**
     * Entfernt Ereignisse älter als dedup_retention_days, in Blöcken (kein einzelnes großes DELETE mit langer Sperre).
     */
    public function prune(int $chunk = 1000): int
    {
        $days = max(1, (int) config('hub.gmail.push.dedup_retention_days', 30));
        $threshold = CarbonImmutable::now()->subDays($days);
        $total = 0;

        do {
            $ids = PushEvent::query()->where('received_at', '<', $threshold)->orderBy('id')->limit(max(1, $chunk))->pluck('id');
            $deleted = $ids->isEmpty() ? 0 : PushEvent::query()->whereIn('id', $ids->all())->delete();
            $total += $deleted;
        } while ($deleted > 0 && $deleted >= $chunk);

        return $total;
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        $message = strtolower($exception->getMessage());

        // MariaDB/MySQL und SQLite: SQLSTATE 23000, SQLite meldet zusätzlich "UNIQUE constraint failed".
        return $sqlState === '23000' || str_contains($message, 'unique constraint') || str_contains($message, 'duplicate entry');
    }

    private function parseTime(string $value): ?CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }
}
