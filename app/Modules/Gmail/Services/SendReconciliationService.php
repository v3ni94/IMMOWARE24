<?php

declare(strict_types=1);

namespace App\Modules\Gmail\Services;

use App\Core\Enums\AuditSource;
use App\Modules\Gmail\Contracts\GmailProviderInterface;
use App\Modules\Gmail\Events\SendVerificationCompleted;
use App\Modules\Gmail\Models\MailDraft;
use App\Modules\Gmail\Models\SendReconciliation;
use App\Modules\Gmail\Services\Sync\MessageImporter;
use App\Modules\Mail\Exceptions\MailRemoteException;
use App\Modules\Mail\Models\Mailbox;
use App\Modules\Security\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * Versandabgleich: sucht nach drafts.send die Nachricht mit Label SENT über rfc822msgid (und threadId). Ergebnis
 * am Entwurf (send_verification): sent_verified (gefunden, Message-ID passt), sent_unverified (noch nicht gefunden,
 * weitere Prüfungen ausstehend), unclear (alle Versuche erschöpft oder Widerspruch). Bei unclear ist kein erneuter
 * Versand erlaubt (SendService). Gesendet ist nie zugestellt: delivery_status bleibt unknown.
 */
final class SendReconciliationService
{
    public const string VERIFIED = 'sent_verified';

    public const string UNVERIFIED = 'sent_unverified';

    public const string UNCLEAR = 'unclear';

    public function __construct(
        private readonly GmailProviderInterface $provider,
        private readonly MessageImporter $importer,
        private readonly Repository $config,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Legt den Abgleich an. Je Entwurf ist höchstens ein Abgleich offen (Unique-Index auf open_key = draft_id):
     * existiert bereits ein pending-Abgleich, wird dieser zurückgegeben und nur die Antwort-ID ergänzt, damit ein
     * Retry von drafts.send nie einen zweiten offenen Abgleich erzeugt.
     */
    public function start(MailDraft $draft, ?string $gmailResponseMessageId): SendReconciliation
    {
        $existing = $this->openFor($draft);

        if ($existing instanceof SendReconciliation) {
            return $this->mergeResponseId($existing, $gmailResponseMessageId);
        }

        $intervals = (array) $this->config->get('hub.gmail.send.reconcile_interval_seconds', [60]);

        try {
            return SendReconciliation::query()->create([
                'draft_id' => $draft->getKey(),
                'open_key' => (int) $draft->getKey(),
                'requested_at' => CarbonImmutable::now(),
                'gmail_response_message_id' => $gmailResponseMessageId,
                'expected_rfc_message_id' => (string) $draft->getAttribute('rfc_message_id'),
                'attempts' => 0,
                'next_check_at' => CarbonImmutable::now()->addSeconds((int) ($intervals[0] ?? 60)),
                'result' => 'pending',
            ]);
        } catch (QueryException $exception) {
            // Wettlauf zweier Läufe: der Unique-Index hat den zweiten offenen Abgleich verhindert.
            $existing = $this->openFor($draft);

            if (! $existing instanceof SendReconciliation) {
                throw $exception;
            }

            Log::notice('Gmail: offener Versandabgleich existiert bereits, kein zweiter angelegt.', ['draft_id' => $draft->getKey()]);

            return $this->mergeResponseId($existing, $gmailResponseMessageId);
        }
    }

    private function openFor(MailDraft $draft): ?SendReconciliation
    {
        return SendReconciliation::query()->where('open_key', (int) $draft->getKey())->where('result', 'pending')->first();
    }

    private function mergeResponseId(SendReconciliation $reconciliation, ?string $gmailResponseMessageId): SendReconciliation
    {
        if ($gmailResponseMessageId !== null && $gmailResponseMessageId !== '' && $reconciliation->getAttribute('gmail_response_message_id') === null) {
            $reconciliation->forceFill(['gmail_response_message_id' => $gmailResponseMessageId])->save();
        }

        return $reconciliation;
    }

    public function reconcile(SendReconciliation $reconciliation): string
    {
        $draft = $reconciliation->draft()->withoutGlobalScopes()->first();

        if (! $draft instanceof MailDraft) {
            $reconciliation->forceFill(['result' => 'mismatch', 'open_key' => null])->save();

            return self::UNCLEAR;
        }

        $mailbox = $draft->mailbox()->withoutGlobalScopes()->first();
        $expected = (string) $reconciliation->getAttribute('expected_rfc_message_id');
        $attempts = (int) $reconciliation->getAttribute('attempts') + 1;
        $maxAttempts = max(1, (int) $this->config->get('hub.gmail.send.reconcile_attempts', 5));

        try {
            $found = $mailbox instanceof Mailbox ? $this->findInSent($mailbox, $expected, $reconciliation->getAttribute('gmail_response_message_id')) : null;
        } catch (MailRemoteException $exception) {
            Log::warning('Gmail: Versandabgleich vorerst nicht möglich.', ['draft_id' => $draft->getKey(), 'reason' => $exception->getMessage()]);
            $found = null;
        }

        $message = null;

        if ($found !== null && $found['matches']) {
            $message = $mailbox instanceof Mailbox ? $this->importer->import($mailbox, $found['id']) : null;

            if ($message === null && $attempts < $maxAttempts) {
                // Nachricht gefunden, aber Import (noch) nicht möglich (404, kurzzeitige Inkonsistenz): pending lassen,
                // damit sent_message_id gesetzt wird und die SLA-Uhren über MarkCommunicationSent stoppen können.
                Log::notice('Gmail: Versand gefunden, Import der gesendeten Nachricht noch nicht möglich.', ['draft_id' => $draft->getKey(), 'gmail_message_id' => $found['id']]);
                $found = null;
            }
        }

        if ($found !== null && $found['matches']) {
            $reconciliation->forceFill([
                'attempts' => $attempts,
                'found_in_sent_at' => CarbonImmutable::now(),
                'result' => 'verified',
                'open_key' => null,
                'next_check_at' => null,
            ])->save();

            $draft->forceFill([
                'status' => 'sent_verified',
                'send_verification' => self::VERIFIED,
                'sent_message_id' => $message?->getKey(),
                'delivery_status' => 'unknown',
            ])->save();

            $this->audit->record('mail.draft.sent_verified', $draft, [], ['gmail_message_id' => $found['id']], AuditSource::Mail);
            event(new SendVerificationCompleted((int) $draft->getKey(), self::VERIFIED));

            return self::VERIFIED;
        }

        if ($found !== null) {
            // Nachricht existiert, aber Message-ID widerspricht: unklar, kein erneuter Versand.
            $reconciliation->forceFill(['attempts' => $attempts, 'result' => 'mismatch', 'open_key' => null, 'next_check_at' => null])->save();
            $draft->forceFill(['send_verification' => self::UNCLEAR])->save();
            event(new SendVerificationCompleted((int) $draft->getKey(), self::UNCLEAR));

            return self::UNCLEAR;
        }

        if ($attempts >= $maxAttempts) {
            $reconciliation->forceFill(['attempts' => $attempts, 'result' => 'not_found', 'open_key' => null, 'next_check_at' => null])->save();
            $draft->forceFill(['send_verification' => self::UNCLEAR])->save();
            $this->audit->record('mail.draft.send_unclear', $draft, [], ['attempts' => $attempts], AuditSource::Mail);
            event(new SendVerificationCompleted((int) $draft->getKey(), self::UNCLEAR));

            return self::UNCLEAR;
        }

        $intervals = array_values((array) $this->config->get('hub.gmail.send.reconcile_interval_seconds', [60, 300, 900, 1800, 3600]));
        $delay = (int) ($intervals[min($attempts, count($intervals) - 1)] ?? 300);

        $reconciliation->forceFill(['attempts' => $attempts, 'result' => 'pending', 'next_check_at' => CarbonImmutable::now()->addSeconds($delay)])->save();
        $draft->forceFill(['send_verification' => self::UNVERIFIED])->save();

        return self::UNVERIFIED;
    }

    /**
     * Fällige Abgleiche verarbeiten (Scheduler). Liefert die Anzahl der geprüften Einträge.
     */
    public function processDue(int $limit = 100): int
    {
        $processed = 0;

        $ids = SendReconciliation::query()
            ->where('result', 'pending')
            ->where('next_check_at', '<=', CarbonImmutable::now())
            ->orderBy('next_check_at')
            ->limit(max(1, $limit))
            ->pluck('id');

        foreach ($ids as $id) {
            $reconciliation = SendReconciliation::query()->find($id);

            if ($reconciliation instanceof SendReconciliation) {
                $this->reconcile($reconciliation);
                $processed++;
            }
        }

        return $processed;
    }

    /**
     * @return array{id: string, matches: bool}|null
     */
    private function findInSent(Mailbox $mailbox, string $expectedRfcMessageId, ?string $gmailResponseMessageId): ?array
    {
        $sent = $this->provider->listSent((int) $mailbox->getKey(), $expectedRfcMessageId);

        foreach ($sent['messages'] as $reference) {
            $remote = $this->provider->getMessage((int) $mailbox->getKey(), (string) $reference['id'], 'metadata');
            $remoteId = $this->messageIdHeader($remote);
            $hasSent = in_array('SENT', (array) ($remote['label_ids'] ?? []), true);

            if ($hasSent && $remoteId !== null && trim($remoteId, '<>') === trim($expectedRfcMessageId, '<>')) {
                return ['id' => (string) $reference['id'], 'matches' => true];
            }
        }

        if ($gmailResponseMessageId !== null && $gmailResponseMessageId !== '') {
            try {
                $remote = $this->provider->getMessage((int) $mailbox->getKey(), $gmailResponseMessageId, 'metadata');
            } catch (MailRemoteException $exception) {
                if ($exception->httpStatus === 404) {
                    return null;
                }

                throw $exception;
            }

            $remoteId = $this->messageIdHeader($remote);
            $hasSent = in_array('SENT', (array) ($remote['label_ids'] ?? []), true);

            if ($hasSent && $remoteId !== null) {
                return ['id' => $gmailResponseMessageId, 'matches' => trim($remoteId, '<>') === trim($expectedRfcMessageId, '<>')];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $remote
     */
    private function messageIdHeader(array $remote): ?string
    {
        foreach ((array) ($remote['headers'] ?? []) as $name => $value) {
            if (strtolower((string) $name) === 'message-id' && is_string($value) && $value !== '') {
                return trim($value);
            }
        }

        return null;
    }
}
