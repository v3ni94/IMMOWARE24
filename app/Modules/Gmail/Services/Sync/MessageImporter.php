<?php

declare(strict_types=1);

namespace App\Modules\Gmail\Services\Sync;

use App\Modules\Gmail\Contracts\GmailProviderInterface;
use App\Modules\Gmail\Events\GmailReplyDetected;
use App\Modules\Gmail\Events\MessageCopyCandidateDetected;
use App\Modules\Gmail\Events\MessageImported;
use App\Modules\Gmail\Mime\HtmlSanitizer;
use App\Modules\Gmail\Mime\MimeParser;
use App\Modules\Gmail\Mime\ParsedMessage;
use App\Modules\Gmail\Models\MailAttachment;
use App\Modules\Gmail\Models\MailDraft;
use App\Modules\Gmail\Models\MailMessage;
use App\Modules\Gmail\Models\MailMessagePart;
use App\Modules\Gmail\Models\MailThread;
use App\Modules\Mail\Exceptions\MailRemoteException;
use App\Modules\Mail\Models\Mailbox;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Importiert eine Gmail-Nachricht (format raw) in mail_messages, mail_threads, mail_message_parts und
 * mail_attachments. Identität (mailbox_id, gmail_message_id) ist unique, ein erneuter Import aktualisiert nur.
 * Kopien in anderen Postfächern (gleiche RFC-Message-ID) werden als Kandidat gemeldet, nie zusammengeführt.
 * Eine SENT-Nachricht in einem bekannten Thread ohne Hub-Entwurf wird als direkte Gmail-Antwort gemeldet.
 * Gelöschte Nachrichten werden nur weich markiert (deleted_at). Anhangsinhalte werden nicht persistiert (offen),
 * nur Metadaten und SHA-256.
 */
final class MessageImporter
{
    public const string STATUS_IMPORTED = 'imported';

    public function __construct(
        private readonly GmailProviderInterface $provider,
        private readonly MimeParser $parser,
        private readonly HtmlSanitizer $sanitizer,
        private readonly Repository $config,
    ) {}

    /**
     * Holt die Nachricht per format=raw und speichert sie. Liefert null, wenn die Gegenstelle 404 meldet
     * (Nachricht inzwischen gelöscht, Eventual Consistency); der Aufrufer entscheidet über Retry.
     */
    public function import(Mailbox $mailbox, string $gmailMessageId, bool $labelsOnlyIfExists = false): ?MailMessage
    {
        $existing = $this->find($mailbox, $gmailMessageId);

        if ($existing !== null && $labelsOnlyIfExists) {
            return $existing;
        }

        try {
            $remote = $this->provider->getMessage((int) $mailbox->getKey(), $gmailMessageId, 'raw');
        } catch (MailRemoteException $exception) {
            if ($exception->httpStatus === 404) {
                Log::info('Gmail: Nachricht bei Abruf nicht mehr vorhanden.', ['mailbox_id' => $mailbox->getKey(), 'gmail_message_id' => $gmailMessageId]);

                return null;
            }

            throw $exception;
        }

        return $this->store($mailbox, $remote, $existing);
    }

    /**
     * @param  array<string, mixed>  $remote  Normalisierte Nachricht des Providers (raw dekodiert)
     */
    public function store(Mailbox $mailbox, array $remote, ?MailMessage $existing = null): MailMessage
    {
        $raw = isset($remote['raw']) && is_string($remote['raw']) ? $remote['raw'] : null;
        $parsed = $raw !== null ? $this->parser->parse($raw) : null;
        $labels = array_values(array_map('strval', (array) ($remote['label_ids'] ?? [])));
        $mailboxAddress = strtolower((string) $mailbox->getAttribute('email_address'));
        $sender = $parsed?->sender();
        $fromAddress = $sender['email'] ?? strtolower($this->addressFromHeader((string) ($remote['headers']['From'] ?? '')));
        $direction = in_array('SENT', $labels, true) || ($fromAddress !== '' && $fromAddress === $mailboxAddress) ? 'outbound' : 'inbound';
        $receivedAt = $this->receivedAt($remote, $parsed);
        $rfcMessageId = $parsed->messageId ?? $this->firstMessageId((string) ($remote['headers']['Message-ID'] ?? ''));
        $checksum = hash('sha256', $raw ?? json_encode($remote['headers'] ?? [], JSON_THROW_ON_ERROR));
        $previous = $existing ?? $this->findWithTrashed($mailbox, (string) ($remote['id'] ?? ''));
        $previousLabels = $previous === null ? [] : array_values(array_map('strval', (array) $previous->getAttribute('label_ids_json')));

        $message = DB::transaction(function () use ($mailbox, $remote, $existing, $parsed, $labels, $fromAddress, $sender, $direction, $receivedAt, $rfcMessageId, $checksum, $raw): MailMessage {
            $thread = $this->upsertThread($mailbox, (string) ($remote['thread_id'] ?? ''), $parsed->subject ?? (string) ($remote['headers']['Subject'] ?? ''), $receivedAt);

            $attributes = [
                'organization_id' => $mailbox->getAttribute('organization_id'),
                'mailbox_id' => $mailbox->getKey(),
                'thread_id' => $thread?->getKey(),
                'gmail_message_id' => (string) $remote['id'],
                'gmail_history_id' => isset($remote['history_id']) ? (string) $remote['history_id'] : null,
                'rfc_message_id' => $rfcMessageId !== null ? mb_substr($rfcMessageId, 0, 998) : null,
                'rfc_message_id_hash' => $rfcMessageId !== null ? hash('sha256', $rfcMessageId) : null,
                'in_reply_to' => $parsed?->inReplyTo !== null ? mb_substr((string) $parsed->inReplyTo, 0, 998) : null,
                'references_json' => $parsed->references ?? [],
                'direction' => $direction,
                'from_address' => mb_substr($fromAddress !== '' ? $fromAddress : 'unbekannt@invalid', 0, 254),
                'from_name' => isset($sender['name']) ? mb_substr((string) $sender['name'], 0, 200) : null,
                'to_json' => $parsed->to ?? [],
                'cc_json' => $parsed->cc ?? [],
                'bcc_json' => $parsed->bcc ?? [],
                'reply_to' => isset($parsed->replyTo[0]['email']) ? mb_substr($parsed->replyTo[0]['email'], 0, 254) : null,
                'subject' => $parsed?->subject !== null ? mb_substr((string) $parsed->subject, 0, 998) : (isset($remote['headers']['Subject']) ? mb_substr((string) $remote['headers']['Subject'], 0, 998) : null),
                'snippet' => isset($remote['snippet']) ? mb_substr((string) $remote['snippet'], 0, 500) : null,
                'received_at' => $receivedAt,
                'imported_at' => $existing?->getAttribute('imported_at') ?? CarbonImmutable::now(),
                'label_ids_json' => $labels,
                'is_read_in_gmail' => ! in_array('UNREAD', $labels, true),
                'has_attachments' => $parsed?->hasAttachments() ?? false,
                'size_estimate' => isset($remote['size_estimate']) ? (int) $remote['size_estimate'] : ($raw !== null ? strlen($raw) : null),
                'checksum' => $checksum,
                'processing_status' => $existing?->getAttribute('processing_status') ?? self::STATUS_IMPORTED,
                'deleted_at' => null,
            ];

            if ($parsed !== null) {
                $attributes['body_text'] = $parsed->text ?? ($parsed->html !== null ? $this->htmlToText($parsed->html) : null);
                $attributes['body_html_sanitized'] = $parsed->html !== null ? $this->sanitizer->sanitize($parsed->html) : null;
                $attributes['body_fetched_at'] = CarbonImmutable::now();
            }

            $message = $existing ?? MailMessage::query()->withoutGlobalScopes()->withTrashed()
                ->where('mailbox_id', $mailbox->getKey())
                ->where('gmail_message_id', (string) $remote['id'])
                ->first();

            if ($message instanceof MailMessage) {
                $message->forceFill($attributes)->save();
            } else {
                $message = MailMessage::query()->create($attributes);
            }

            if ($parsed !== null) {
                $this->storeParts($message, $mailbox, $parsed);
            }

            if ($thread !== null) {
                $this->refreshThreadCounters($thread);
            }

            return $message;
        });

        $created = $existing === null && $message->wasRecentlyCreated;
        $sentBefore = ! $created && in_array('SENT', $previousLabels, true);

        // Ereignisse nur bei neuer Nachricht oder neu hinzugekommenem SENT: ein erneuter Import derselben Nachricht
        // (Replay einer History-Seite, Versandabgleich) erzeugt keine doppelte Antworterkennung.
        if ($created) {
            $this->detectCopies($message);
        }

        if ($created || ! $sentBefore) {
            $this->detectDirectReply($mailbox, $message, $labels);
        }

        event(new MessageImported((int) $mailbox->getKey(), (int) $message->getKey(), $created));

        return $message;
    }

    /**
     * Labels aus History übernehmen, ohne die Nachricht neu zu laden.
     *
     * @param  array<int, string>  $add
     * @param  array<int, string>  $remove
     */
    public function applyLabelChange(Mailbox $mailbox, string $gmailMessageId, array $add, array $remove): ?MailMessage
    {
        $message = $this->find($mailbox, $gmailMessageId);

        if ($message === null) {
            return null;
        }

        $labels = array_values(array_map('strval', (array) $message->getAttribute('label_ids_json')));
        $labels = array_values(array_unique(array_diff(array_merge($labels, $add), $remove)));

        $message->forceFill([
            'label_ids_json' => $labels,
            'is_read_in_gmail' => ! in_array('UNREAD', $labels, true),
        ])->save();

        return $message;
    }

    /**
     * Weiche Markierung; nie Hard Delete auf Spiegeldaten.
     */
    public function markDeleted(Mailbox $mailbox, string $gmailMessageId): void
    {
        $message = $this->find($mailbox, $gmailMessageId);

        if ($message !== null && $message->getAttribute('deleted_at') === null) {
            $message->delete();
        }
    }

    private function findWithTrashed(Mailbox $mailbox, string $gmailMessageId): ?MailMessage
    {
        if ($gmailMessageId === '') {
            return null;
        }

        $message = MailMessage::query()->withoutGlobalScopes()->withTrashed()
            ->where('mailbox_id', $mailbox->getKey())
            ->where('gmail_message_id', $gmailMessageId)
            ->first();

        return $message instanceof MailMessage ? $message : null;
    }

    public function find(Mailbox $mailbox, string $gmailMessageId): ?MailMessage
    {
        $message = MailMessage::query()->withoutGlobalScopes()
            ->where('mailbox_id', $mailbox->getKey())
            ->where('gmail_message_id', $gmailMessageId)
            ->first();

        return $message instanceof MailMessage ? $message : null;
    }

    private function upsertThread(Mailbox $mailbox, string $gmailThreadId, string $subject, CarbonImmutable $receivedAt): ?MailThread
    {
        if ($gmailThreadId === '') {
            return null;
        }

        $thread = MailThread::query()->withoutGlobalScopes()
            ->where('mailbox_id', $mailbox->getKey())
            ->where('gmail_thread_id', $gmailThreadId)
            ->first();

        if (! $thread instanceof MailThread) {
            $thread = MailThread::query()->create([
                'organization_id' => $mailbox->getAttribute('organization_id'),
                'mailbox_id' => $mailbox->getKey(),
                'gmail_thread_id' => $gmailThreadId,
                'subject_normalized' => mb_substr($this->normalizeSubject($subject), 0, 500),
                'first_message_at' => $receivedAt,
                'last_message_at' => $receivedAt,
                'message_count' => 0,
            ]);
        }

        return $thread;
    }

    private function refreshThreadCounters(MailThread $thread): void
    {
        $query = MailMessage::query()->withoutGlobalScopes()->where('thread_id', $thread->getKey());

        $thread->forceFill([
            'message_count' => (int) $query->count(),
            'first_message_at' => $query->min('received_at'),
            'last_message_at' => $query->max('received_at'),
        ])->save();
    }

    private function storeParts(MailMessage $message, Mailbox $mailbox, ParsedMessage $parsed): void
    {
        $allowlist = (array) $this->config->get('hub.gmail.sync.attachment_allowlist', []);
        $maxBytes = (int) $this->config->get('hub.gmail.sync.attachment_max_bytes', 26214400);
        $attachmentIds = array_map(static fn (array $a): string => (string) $a['part_id'], $parsed->attachments);

        foreach ($parsed->parts as $part) {
            $record = MailMessagePart::query()->updateOrCreate(
                ['message_id' => $message->getKey(), 'part_id' => (string) $part['part_id']],
                [
                    'parent_part_id' => $part['parent_part_id'],
                    'mime_type' => mb_substr((string) $part['mime_type'], 0, 120),
                    'filename' => $part['filename'] !== null ? mb_substr((string) $part['filename'], 0, 255) : null,
                    'content_id' => $part['content_id'] !== null ? mb_substr((string) $part['content_id'], 0, 255) : null,
                    'disposition' => $part['disposition'] !== null ? mb_substr((string) $part['disposition'], 0, 20) : null,
                    'size_bytes' => (int) $part['size_bytes'],
                    'headers_json' => $part['headers'],
                ],
            );

            if (! in_array((string) $part['part_id'], $attachmentIds, true)) {
                continue;
            }

            $content = is_string($part['content']) ? $part['content'] : '';
            $mime = (string) $part['mime_type'];
            $scan = match (true) {
                strlen($content) > $maxBytes => 'skipped',
                $allowlist !== [] && ! in_array($mime, $allowlist, true) => 'blocked',
                default => 'pending',
            };

            MailAttachment::query()->withoutGlobalScopes()->updateOrCreate(
                ['message_id' => $message->getKey(), 'part_id' => $record->getKey()],
                [
                    'organization_id' => $mailbox->getAttribute('organization_id'),
                    'filename' => mb_substr((string) ($part['filename'] ?? 'anhang'), 0, 255),
                    'mime_type' => mb_substr($mime, 0, 120),
                    'size_bytes' => strlen($content),
                    'sha256' => $content !== '' ? hash('sha256', $content) : null,
                    'scan_status' => $scan,
                ],
            );
        }
    }

    private function detectCopies(MailMessage $message): void
    {
        $hash = $message->getAttribute('rfc_message_id_hash');

        if (! is_string($hash) || $hash === '') {
            return;
        }

        $candidates = MailMessage::query()->withoutGlobalScopes()
            ->where('organization_id', $message->getAttribute('organization_id'))
            ->where('rfc_message_id_hash', $hash)
            ->where('mailbox_id', '!=', $message->getAttribute('mailbox_id'))
            ->limit(20)
            ->pluck('id');

        foreach ($candidates as $candidateId) {
            event(new MessageCopyCandidateDetected((int) $message->getKey(), (int) $candidateId));
        }
    }

    /**
     * @param  array<int, string>  $labels
     */
    private function detectDirectReply(Mailbox $mailbox, MailMessage $message, array $labels): void
    {
        if (! in_array('SENT', $labels, true) || $message->getAttribute('thread_id') === null) {
            return;
        }

        $othersInThread = MailMessage::query()->withoutGlobalScopes()
            ->where('thread_id', $message->getAttribute('thread_id'))
            ->where('id', '!=', $message->getKey())
            ->exists();

        if (! $othersInThread) {
            return;
        }

        $rfcMessageId = $message->getAttribute('rfc_message_id');

        $hubDraft = MailDraft::query()->withoutGlobalScopes()
            ->where('mailbox_id', $mailbox->getKey())
            ->where(static function ($query) use ($rfcMessageId, $message): void {
                $query->where('gmail_draft_message_id', $message->getAttribute('gmail_message_id'))
                    ->orWhere('sent_message_id', $message->getKey());

                if (is_string($rfcMessageId) && $rfcMessageId !== '') {
                    $query->orWhere('rfc_message_id', $rfcMessageId);
                }
            })
            ->exists();

        if ($hubDraft) {
            return;
        }

        $thread = $message->thread;

        event(new GmailReplyDetected(
            (int) $mailbox->getKey(),
            (int) $message->getKey(),
            (int) $message->getAttribute('thread_id'),
            (string) ($thread?->getAttribute('gmail_thread_id') ?? ''),
        ));
    }

    /**
     * @param  array<string, mixed>  $remote
     */
    private function receivedAt(array $remote, ?ParsedMessage $parsed): CarbonImmutable
    {
        if (isset($remote['internal_date']) && is_numeric($remote['internal_date'])) {
            return CarbonImmutable::createFromTimestampMs((int) $remote['internal_date'])->utc();
        }

        return $parsed->date ?? CarbonImmutable::now();
    }

    private function normalizeSubject(string $subject): string
    {
        $normalized = (string) preg_replace('/^\s*((re|aw|fwd?|wg|antw)\s*:\s*)+/iu', '', trim($subject));

        return mb_strtolower(trim($normalized));
    }

    private function addressFromHeader(string $header): string
    {
        if (preg_match('/<([^>]+)>/', $header, $m) === 1) {
            return trim($m[1]);
        }

        return trim($header);
    }

    private function firstMessageId(string $header): ?string
    {
        return preg_match('/<[^<>\s]+>/', $header, $m) === 1 ? $m[0] : null;
    }

    private function htmlToText(string $html): string
    {
        $text = (string) preg_replace('/<(br|\/p|\/div|\/li|\/tr)[^>]*>/i', "\n", $html);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace("/\n{3,}/", "\n\n", $text));
    }
}
