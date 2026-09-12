<?php

declare(strict_types=1);

namespace App\Modules\Gmail\Services;

use App\Core\Enums\AuditSource;
use App\Modules\Gmail\Contracts\GmailProviderInterface;
use App\Modules\Gmail\Exceptions\DraftApprovalRefusedException;
use App\Modules\Gmail\Exceptions\DraftConflictException;
use App\Modules\Gmail\Mime\MimeBuilder;
use App\Modules\Gmail\Mime\MimeParser;
use App\Modules\Gmail\Models\MailDraft;
use App\Modules\Gmail\Models\MailMessage;
use App\Modules\Mail\Exceptions\MailRemoteException;
use App\Modules\Mail\Models\Mailbox;
use App\Modules\Mail\Models\MailboxAlias;
use App\Modules\Mail\Services\MailAccess;
use App\Modules\Mail\Services\MailFeatureFlags;
use App\Modules\Security\Models\User;
use App\Modules\Security\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;

/**
 * Entwürfe: baut RFC-konformes MIME (In-Reply-To, References, threadId), speichert mail_drafts mit content_hash und
 * revision und legt den Entwurf bei aktiviertem Flag gmail_drafts in Gmail an (drafts.create). update prüft zuvor den
 * entfernten Zustand: außerhalb geänderter Entwurf → conflict (kein Überschreiben), verschwundener Entwurf → missing
 * (nie sent). Der Inhaltshash ist kanonisch (Empfänger, Betreff, Text), nicht der rohe MIME-Text, weil Gmail den
 * Rohtext neu serialisiert (aus Snippets abgeleitet, am Original zu prüfen). Hub-intern schützt die Revision:
 * updateDraft nimmt die vom Bearbeitenden erwartete Revision an und lehnt veraltete Stände ab (stale_revision), das
 * Hochzählen erfolgt als bedingtes Update. approve dokumentiert die Freigabe einer zweiten Person (Vier-Augen);
 * jede Inhaltsänderung setzt die Freigabe zurück.
 */
final class DraftService
{
    public const string STATUS_LOCAL = 'local';

    public const string STATUS_PUSHED = 'pushed_to_gmail';

    public const string STATUS_MISSING = 'missing';

    public const string STATUS_CONFLICT = 'conflict';

    public const string REMOTE_OK = 'ok';

    public const string STATUS_STALE_REVISION = 'stale_revision';

    public function __construct(
        private readonly GmailProviderInterface $provider,
        private readonly MimeBuilder $builder,
        private readonly MimeParser $parser,
        private readonly MailFeatureFlags $flags,
        private readonly Repository $config,
        private readonly AuditLogger $audit,
        private readonly MailAccess $access,
    ) {}

    /**
     * Antwortentwurf auf eine importierte Nachricht.
     *
     * @param  array<int, array{filename: string, mime_type: string, content: string, content_id?: ?string}>  $attachments
     * @param  array<string, mixed>  $options  to (Liste), cc, bcc, case_id, generated_by
     */
    public function createReplyDraft(MailMessage $message, string $bodyText, ?string $bodyHtml, MailboxAlias $fromAlias, array $attachments = [], ?User $user = null, array $options = []): MailDraft
    {
        $mailbox = $message->mailbox()->withoutGlobalScopes()->first();

        if (! $mailbox instanceof Mailbox) {
            throw new InvalidArgumentException('Nachricht ohne Postfach.');
        }

        $this->assertAliasBelongsTo($fromAlias, $mailbox);
        $this->assertAttachments($attachments);

        $to = $options['to'] ?? $this->replyRecipients($message);
        $subject = $this->replySubject((string) $message->getAttribute('subject'));
        $references = array_values(array_map('strval', (array) $message->getAttribute('references_json')));
        $originalId = $message->getAttribute('rfc_message_id');

        if (is_string($originalId) && $originalId !== '' && ! in_array($originalId, $references, true)) {
            $references[] = $originalId;
        }

        $threadId = $message->thread()->withoutGlobalScopes()->first()?->getAttribute('gmail_thread_id');

        $draft = new MailDraft;
        $draft->forceFill([
            'organization_id' => $mailbox->getAttribute('organization_id'),
            'case_id' => $options['case_id'] ?? null,
            'mailbox_id' => $mailbox->getKey(),
            'alias_id' => $fromAlias->getKey(),
            'reply_to_message_id' => $message->getKey(),
            'to_json' => array_values((array) $to),
            'cc_json' => array_values((array) ($options['cc'] ?? [])),
            'bcc_json' => array_values((array) ($options['bcc'] ?? [])),
            'subject' => $subject,
            'body_text' => $bodyText,
            'body_html' => $bodyHtml,
            'attachments_json' => $this->attachmentMeta($attachments),
            'generated_by' => (string) ($options['generated_by'] ?? 'user'),
            'status' => self::STATUS_LOCAL,
            'revision' => 0,
            'rfc_message_id' => $this->builder->newMessageId((string) $this->config->get('hub.gmail.drafts.message_id_domain', 'mail.muellerhv.de')),
            'gmail_thread_id' => is_string($threadId) && $threadId !== '' ? $threadId : null,
            'delivery_status' => 'unknown',
            'created_by' => $user?->getKey(),
        ]);
        $draft->save();

        $this->pushToGmail($draft, $mailbox, $fromAlias, $attachments, is_string($originalId) ? $originalId : null, $references, create: true);

        $this->audit->record('mail.draft.created', $draft, [], ['status' => $draft->getAttribute('status'), 'revision' => $draft->getAttribute('revision')], AuditSource::Mail);

        return $draft;
    }

    /**
     * Inhalt ändern. Vorher entfernten Zustand prüfen; bei conflict oder missing wird nichts geschrieben. Mit
     * expectedRevision (aus dem Formular) wird ein zwischenzeitlich von einer anderen Person geänderter Entwurf
     * als stale_revision abgelehnt statt überschrieben (kein last write wins).
     *
     * @param  array<string, mixed>  $changes  body_text, body_html, to, cc, bcc, subject
     * @param  array<int, array{filename: string, mime_type: string, content: string, content_id?: ?string}>  $attachments
     */
    public function updateDraft(MailDraft $draft, array $changes, array $attachments = [], ?User $user = null, ?int $expectedRevision = null): MailDraft
    {
        $draft->refresh();

        if ($expectedRevision !== null && $expectedRevision !== (int) $draft->getAttribute('revision')) {
            throw new DraftConflictException((int) $draft->getKey(), self::STATUS_STALE_REVISION, 'Der Entwurf wurde inzwischen von einer anderen Person geändert (Revision '.$draft->getAttribute('revision').'). Bitte neu laden.');
        }

        $remote = $this->refreshRemoteState($draft);

        if ($remote !== self::REMOTE_OK) {
            throw new DraftConflictException((int) $draft->getKey(), $remote, $remote === self::STATUS_MISSING
                ? 'Der Gmail-Entwurf existiert nicht mehr. Der Entwurf gilt nicht als gesendet.'
                : 'Der Gmail-Entwurf wurde außerhalb des Hubs geändert. Kein Überschreiben.');
        }

        $this->assertAttachments($attachments);

        $draft->forceFill(array_filter([
            'body_text' => $changes['body_text'] ?? null,
            'body_html' => array_key_exists('body_html', $changes) ? $changes['body_html'] : null,
            'to_json' => isset($changes['to']) ? array_values((array) $changes['to']) : null,
            'cc_json' => isset($changes['cc']) ? array_values((array) $changes['cc']) : null,
            'bcc_json' => isset($changes['bcc']) ? array_values((array) $changes['bcc']) : null,
            'subject' => $changes['subject'] ?? null,
        ], static fn (mixed $value): bool => $value !== null));

        if (array_key_exists('body_html', $changes) && $changes['body_html'] === null) {
            $draft->setAttribute('body_html', null);
        }

        if ($attachments !== []) {
            $draft->setAttribute('attachments_json', $this->attachmentMeta($attachments));
        }

        // Inhaltsänderung: eine frühere Freigabe gilt nicht mehr (Vier-Augen bezieht sich auf den freigegebenen Stand).
        $draft->setAttribute('approved_by', null);
        $draft->setAttribute('approved_at', null);

        $mailbox = $draft->mailbox()->withoutGlobalScopes()->firstOrFail();
        $alias = $draft->alias()->firstOrFail();
        $original = $draft->replyToMessage()->withoutGlobalScopes()->first();
        $references = $original !== null ? array_values(array_map('strval', (array) $original->getAttribute('references_json'))) : [];
        $originalId = $original?->getAttribute('rfc_message_id');

        if (is_string($originalId) && $originalId !== '' && ! in_array($originalId, $references, true)) {
            $references[] = $originalId;
        }

        $this->pushToGmail($draft, $mailbox, $alias, $attachments, is_string($originalId) ? $originalId : null, $references, create: false);

        $this->audit->record('mail.draft.updated', $draft, [], ['revision' => $draft->getAttribute('revision')], AuditSource::Mail);

        return $draft;
    }

    /**
     * Freigabe durch eine zweite Person (Vier-Augen): Freigebende braucht mail.approve.standard im Team des
     * Postfachs, darf nicht Autor des Entwurfs sein und muss eine aktuelle Re-Authentifizierung nachweisen
     * (Zeitstempel aus der Sitzung, Fenster hub.security.totp.fresh_minutes). Ohne Nachweis gibt es keinen
     * Ersatzwert: die Freigabe wird mit reauth_missing abgelehnt, unabhängig von der Middleware 2fa.fresh
     * (analog ApprovalService). Die Freigabe bezieht sich auf die aktuelle Revision; updateDraft setzt sie zurück.
     * Der Status bleibt unverändert (pushed_to_gmail bleibt versandfähig).
     *
     * @throws DraftApprovalRefusedException
     */
    public function approve(MailDraft $draft, User $approver, ?CarbonImmutable $reauthConfirmedAt = null): MailDraft
    {
        $draft->refresh();

        if (! in_array((string) $draft->getAttribute('status'), [self::STATUS_PUSHED, 'pending_approval', 'approved'], true)) {
            throw new DraftApprovalRefusedException('status', 'Nur in Gmail hinterlegte oder zur Prüfung gegebene Entwürfe können freigegeben werden (Status '.$draft->getAttribute('status').').');
        }

        $author = $draft->getAttribute('created_by');

        if ($author !== null && (int) $author === (int) $approver->getKey()) {
            throw new DraftApprovalRefusedException('approval_self', 'Der Autor kann den eigenen Entwurf nicht freigeben (Vier-Augen-Prinzip).');
        }

        $mailbox = $draft->mailbox()->withoutGlobalScopes()->firstOrFail();
        $teamId = $mailbox->getAttribute('team_id');

        if (! $this->access->can($approver, 'mail.approve.standard', $teamId === null ? null : (int) $teamId)) {
            throw new DraftApprovalRefusedException('permission_denied', 'Kein Recht mail.approve.standard für dieses Postfach.');
        }

        if (! self::reauthIsFresh($reauthConfirmedAt, $this->config)) {
            $this->audit->record('mail.draft.approval_reauth_missing', $draft, [], ['revision' => $draft->getAttribute('revision')], AuditSource::Mail);

            throw new DraftApprovalRefusedException('reauth_missing', 'Freigabe ohne aktuelle Re-Authentifizierung ist nicht zulässig.');
        }

        $draft->forceFill([
            'approved_by' => $approver->getKey(),
            'approved_at' => CarbonImmutable::now(),
            'approval_reauth_confirmed_at' => $reauthConfirmedAt,
        ])->save();
        $this->audit->record('mail.draft.approved', $draft, [], ['revision' => $draft->getAttribute('revision'), 'content_hash' => $draft->getAttribute('content_hash'), 'reauth_confirmed_at' => $reauthConfirmedAt?->toIso8601String()], AuditSource::Mail);

        return $draft;
    }

    /**
     * Re-Authentifizierung gilt als aktuell, wenn ein Zeitstempel vorliegt, nicht in der Zukunft liegt und nicht
     * älter als hub.security.totp.fresh_minutes (Standard 15) ist. Gemeinsame Regel für Freigabe und Versand.
     */
    public static function reauthIsFresh(?CarbonImmutable $reauthConfirmedAt, Repository $config): bool
    {
        if ($reauthConfirmedAt === null) {
            return false;
        }

        $maxAge = max(1, (int) $config->get('hub.security.totp.fresh_minutes', 15));
        $now = CarbonImmutable::now();

        return $reauthConfirmedAt->lessThanOrEqualTo($now->addMinute()) && $reauthConfirmedAt->addMinutes($maxAge)->isFuture();
    }

    /**
     * Entfernten Zustand prüfen: ok, missing (404) oder conflict (Inhalt abweichend). Setzt den Entwurfsstatus bei
     * missing und conflict, nie auf sent.
     */
    public function refreshRemoteState(MailDraft $draft): string
    {
        $gmailDraftId = $draft->getAttribute('gmail_draft_id');

        if (! is_string($gmailDraftId) || $gmailDraftId === '') {
            return self::REMOTE_OK;
        }

        try {
            $remote = $this->provider->getDraft((int) $draft->getAttribute('mailbox_id'), $gmailDraftId);
        } catch (MailRemoteException $exception) {
            if ($exception->httpStatus === 404) {
                $this->markQuietly($draft, ['status' => self::STATUS_MISSING, 'remote_checked_at' => CarbonImmutable::now()]);

                return self::STATUS_MISSING;
            }

            throw $exception;
        }

        $raw = data_get($remote, 'message.raw');
        $remoteHash = is_string($raw) && $raw !== '' ? $this->canonicalHashFromRaw($raw) : null;
        $localHash = $draft->getAttribute('content_hash');

        if ($remoteHash !== null && is_string($localHash) && $localHash !== '' && ! hash_equals($localHash, $remoteHash)) {
            $this->markQuietly($draft, ['status' => self::STATUS_CONFLICT, 'remote_checked_at' => CarbonImmutable::now()]);

            return self::STATUS_CONFLICT;
        }

        $this->markQuietly($draft, ['remote_checked_at' => CarbonImmutable::now()]);

        return self::REMOTE_OK;
    }

    /**
     * Kanonischer Inhaltshash über Empfänger, Betreff und Text (Leerraum normalisiert).
     *
     * @param  array<int, string>  $to
     * @param  array<int, string>  $cc
     * @param  array<int, string>  $bcc
     */
    public function canonicalHash(array $to, array $cc, array $bcc, string $subject, string $text): string
    {
        $normalize = static fn (array $list): array => array_values(array_unique(array_map(static fn (string $a): string => strtolower(trim($a)), $list)));
        sort($to);
        sort($cc);
        sort($bcc);

        $payload = [
            'to' => $normalize($to),
            'cc' => $normalize($cc),
            'bcc' => $normalize($bcc),
            'subject' => trim((string) preg_replace('/\s+/u', ' ', $subject)),
            'text' => trim((string) preg_replace('/\s+/u', ' ', $text)),
        ];

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  array<int, array{filename: string, mime_type: string, content: string, content_id?: ?string}>  $attachments
     * @param  array<int, string>  $references
     * @return array<string, mixed> Normalisierte Nachricht inklusive raw
     */
    public function buildMime(MailDraft $draft, MailboxAlias $alias, array $attachments, ?string $inReplyTo, array $references): array
    {
        $to = array_values(array_map('strval', (array) $draft->getAttribute('to_json')));
        $cc = array_values(array_map('strval', (array) $draft->getAttribute('cc_json')));
        $bcc = array_values(array_map('strval', (array) $draft->getAttribute('bcc_json')));

        $message = [
            'from' => (string) $alias->getAttribute('send_as_email'),
            'from_name' => $alias->getAttribute('display_name'),
            'to' => $to,
            'cc' => $cc,
            'bcc' => $bcc,
            'reply_to' => $alias->getAttribute('reply_to'),
            'subject' => (string) $draft->getAttribute('subject'),
            'text' => (string) $draft->getAttribute('body_text'),
            'html' => $draft->getAttribute('body_html'),
            'message_id' => (string) $draft->getAttribute('rfc_message_id'),
            'in_reply_to' => $inReplyTo,
            'references' => $references,
            'attachments' => $attachments,
        ];

        $raw = $this->builder->build($message);

        return $message + ['raw' => $raw, 'thread_id' => $draft->getAttribute('gmail_thread_id')];
    }

    /**
     * @param  array<int, array{filename: string, mime_type: string, content: string, content_id?: ?string}>  $attachments
     * @param  array<int, string>  $references
     */
    private function pushToGmail(MailDraft $draft, Mailbox $mailbox, MailboxAlias $alias, array $attachments, ?string $inReplyTo, array $references, bool $create): void
    {
        $mime = $this->buildMime($draft, $alias, $attachments, $inReplyTo, $references);
        $hash = $this->canonicalHash($mime['to'], $mime['cc'], $mime['bcc'], $mime['subject'], $mime['text']);
        $currentRevision = (int) $draft->getAttribute('revision');

        if (! $create) {
            // Bedingtes Hochzählen: hat eine andere Person die Revision inzwischen erhöht, wird nichts überschrieben.
            $claimed = MailDraft::query()->withoutGlobalScopes()
                ->whereKey($draft->getKey())
                ->where('revision', $currentRevision)
                ->update(['revision' => $currentRevision + 1]);

            if ($claimed !== 1) {
                throw new DraftConflictException((int) $draft->getKey(), self::STATUS_STALE_REVISION, 'Der Entwurf wurde gleichzeitig von einer anderen Person geändert. Bitte neu laden.');
            }
        }

        $draft->setAttribute('content_hash', $hash);
        $draft->setAttribute('revision', $currentRevision + 1);

        if (! $this->flags->gmailDraftsEnabled()) {
            // Flag aus: Entwurf bleibt lokal, sichtbar als nicht nach Gmail übertragen.
            $draft->setAttribute('status', self::STATUS_LOCAL);
            $draft->save();

            return;
        }

        $gmailDraftId = $draft->getAttribute('gmail_draft_id');

        $result = $create || ! is_string($gmailDraftId) || $gmailDraftId === ''
            ? $this->provider->createDraft((int) $mailbox->getKey(), $mime)
            : $this->provider->updateDraft((int) $mailbox->getKey(), $gmailDraftId, $mime);

        $draft->forceFill([
            'gmail_draft_id' => $result['draft_id'],
            'gmail_draft_message_id' => $result['message_id'] !== '' ? $result['message_id'] : null,
            'status' => self::STATUS_PUSHED,
            'remote_checked_at' => CarbonImmutable::now(),
        ])->save();
    }

    private function canonicalHashFromRaw(string $raw): string
    {
        $parsed = $this->parser->parse($raw);
        $emails = static fn (array $list): array => array_map(static fn (array $a): string => $a['email'], $list);

        return $this->canonicalHash($emails($parsed->to), $emails($parsed->cc), $emails($parsed->bcc), (string) $parsed->subject, (string) ($parsed->text ?? ''));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function markQuietly(MailDraft $draft, array $attributes): void
    {
        $draft->timestamps = false;
        $draft->forceFill($attributes)->save();
        $draft->timestamps = true;
    }

    /**
     * @return array<int, string>
     */
    private function replyRecipients(MailMessage $message): array
    {
        $replyTo = $message->getAttribute('reply_to');

        if (is_string($replyTo) && $replyTo !== '') {
            return [$replyTo];
        }

        return [(string) $message->getAttribute('from_address')];
    }

    private function replySubject(string $subject): string
    {
        $subject = trim($subject);

        return preg_match('/^(re|aw)\s*:/i', $subject) === 1 ? $subject : 'Re: '.$subject;
    }

    private function assertAliasBelongsTo(MailboxAlias $alias, Mailbox $mailbox): void
    {
        if ((int) $alias->getAttribute('mailbox_id') !== (int) $mailbox->getKey()) {
            throw new InvalidArgumentException('Der Absender-Alias gehört nicht zu diesem Postfach.');
        }

        if ((string) $alias->getAttribute('legal_entity_code') !== (string) $mailbox->getAttribute('legal_entity_code')) {
            throw new InvalidArgumentException('Absender und Postfach gehören zu verschiedenen Gesellschaften.');
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $attachments
     */
    private function assertAttachments(array $attachments): void
    {
        $max = (int) $this->config->get('hub.gmail.drafts.max_attachment_bytes', 10485760);

        foreach ($attachments as $attachment) {
            if (! isset($attachment['filename'], $attachment['mime_type'], $attachment['content'])) {
                throw new InvalidArgumentException('Anhang unvollständig (filename, mime_type, content).');
            }

            if (strlen((string) $attachment['content']) > $max) {
                throw new InvalidArgumentException('Anhang zu groß: '.$attachment['filename']);
            }
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $attachments
     * @return array<int, array{filename: string, mime_type: string, size_bytes: int, sha256: string}>
     */
    private function attachmentMeta(array $attachments): array
    {
        return array_map(static fn (array $a): array => [
            'filename' => (string) $a['filename'],
            'mime_type' => (string) $a['mime_type'],
            'size_bytes' => strlen((string) $a['content']),
            'sha256' => hash('sha256', (string) $a['content']),
        ], $attachments);
    }
}
