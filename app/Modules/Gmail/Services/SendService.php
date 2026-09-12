<?php

declare(strict_types=1);

namespace App\Modules\Gmail\Services;

use App\Core\Enums\AuditSource;
use App\Modules\Gmail\Contracts\GmailProviderInterface;
use App\Modules\Gmail\Exceptions\SendRefusedException;
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
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Versand eines Entwurfs. Reihenfolge: Flag gmail_send, Recht mail.send plus Postfachfreigabe can_send, Alias erlaubt
 * (gehört zum Postfach, Gesellschaft passt, verifiziert), Entwurf in Gmail vorhanden und unverändert (Hash), keine neue
 * Threadnachricht seit der letzten Änderung, kein früherer Versand mit unklarem Ergebnis. Erst danach drafts.send.
 * Die Antwort ist kein Versandnachweis: SendReconciliationService prüft SENT und Message-ID. Ein Fehler der
 * Gegenstelle setzt nie sent, der Abgleich entscheidet (sent_unverified bis unclear).
 */
final class SendService
{
    public const string STATUS_SENT_REQUESTED = 'sent_requested';

    /** @var array<int, string> */
    private const array RESENDABLE_STATUSES = ['pushed_to_gmail', 'approved'];

    public function __construct(
        private readonly GmailProviderInterface $provider,
        private readonly DraftService $drafts,
        private readonly SendReconciliationService $reconciliation,
        private readonly MailFeatureFlags $flags,
        private readonly MailAccess $access,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws SendRefusedException
     */
    public function send(MailDraft $draft, User $user): MailDraft
    {
        if (! $this->flags->gmailSendEnabled()) {
            throw new SendRefusedException('send_disabled', 'Versand ist deaktiviert (MAIL_GMAIL_SEND_ENABLED=false).');
        }

        $mailbox = $draft->mailbox()->withoutGlobalScopes()->first();

        if (! $mailbox instanceof Mailbox) {
            throw new SendRefusedException('mailbox_missing', 'Entwurf ohne Postfach.');
        }

        if (! $this->access->canSendFromMailbox($user, $mailbox)) {
            throw new SendRefusedException('permission_denied', 'Kein Recht mail.send für dieses Postfach.');
        }

        $alias = $draft->alias()->first();

        if (! $alias instanceof MailboxAlias || (int) $alias->getAttribute('mailbox_id') !== (int) $mailbox->getKey()) {
            throw new SendRefusedException('alias_not_allowed', 'Absender-Alias gehört nicht zum Postfach.');
        }

        if ((string) $alias->getAttribute('legal_entity_code') !== (string) $mailbox->getAttribute('legal_entity_code')) {
            throw new SendRefusedException('alias_not_allowed', 'Absender und Postfach gehören zu verschiedenen Gesellschaften.');
        }

        if (strtolower((string) $alias->getAttribute('verification_status')) !== 'accepted') {
            throw new SendRefusedException('alias_not_verified', 'Absender-Alias ist in Gmail nicht verifiziert.');
        }

        if ($draft->getAttribute('send_verification') === SendReconciliationService::UNCLEAR) {
            throw new SendRefusedException('previous_send_unclear', 'Ein früherer Versand dieses Entwurfs ist unklar. Kein erneuter Versand, bitte Postfach prüfen.');
        }

        if (in_array($draft->getAttribute('status'), [self::STATUS_SENT_REQUESTED, 'sent_verified'], true)) {
            throw new SendRefusedException('already_requested', 'Versand wurde bereits angefordert oder ist verifiziert.');
        }

        if (! in_array($draft->getAttribute('status'), self::RESENDABLE_STATUSES, true)) {
            throw new SendRefusedException('draft_not_ready', 'Entwurf ist nicht in Gmail hinterlegt oder hat einen Konflikt (Status '.$draft->getAttribute('status').').');
        }

        $gmailDraftId = $draft->getAttribute('gmail_draft_id');

        if (! is_string($gmailDraftId) || $gmailDraftId === '') {
            throw new SendRefusedException('draft_not_ready', 'Entwurf ohne Gmail-Entwurfs-ID.');
        }

        $lastChange = CarbonImmutable::instance($draft->getAttribute('updated_at') ?? $draft->getAttribute('created_at') ?? now());

        if ($this->hasNewThreadMessageSince($draft, $lastChange)) {
            throw new SendRefusedException('thread_changed', 'Im Thread ist seit dem Entwurf eine neue Nachricht eingegangen. Entwurf prüfen.');
        }

        $remote = $this->drafts->refreshRemoteState($draft);

        if ($remote !== DraftService::REMOTE_OK) {
            throw new SendRefusedException('draft_'.$remote, $remote === DraftService::STATUS_MISSING
                ? 'Der Gmail-Entwurf existiert nicht mehr; er gilt nicht als gesendet.'
                : 'Der Gmail-Entwurf wurde außerhalb des Hubs geändert.');
        }

        $draft->forceFill([
            'status' => self::STATUS_SENT_REQUESTED,
            'sent_requested_by' => $user->getKey(),
            'sent_requested_at' => CarbonImmutable::now(),
            'send_verification' => SendReconciliationService::UNVERIFIED,
            'delivery_status' => 'unknown',
        ])->save();

        $this->audit->record('mail.draft.send_requested', $draft, [], ['alias' => $alias->getAttribute('send_as_email')], AuditSource::Mail);

        $responseMessageId = null;

        try {
            $response = $this->provider->sendDraft((int) $mailbox->getKey(), $gmailDraftId);
            $responseMessageId = $response['message_id'] !== '' ? $response['message_id'] : null;
        } catch (MailRemoteException $exception) {
            // Antwort fehlt: der Versand kann trotzdem erfolgt sein (Timeout, 5xx). Nie sent, nie failed ohne Abgleich.
            Log::warning('Gmail: drafts.send ohne bestätigende Antwort.', ['draft_id' => $draft->getKey(), 'status' => $exception->httpStatus]);
        } catch (Throwable $exception) {
            Log::error('Gmail: drafts.send mit Fehler.', ['draft_id' => $draft->getKey(), 'reason' => $exception->getMessage()]);
        }

        $reconciliation = $this->reconciliation->start($draft, $responseMessageId);
        $this->reconciliation->reconcile($reconciliation);

        return $draft->refresh();
    }

    private function hasNewThreadMessageSince(MailDraft $draft, CarbonImmutable $since): bool
    {
        $original = $draft->replyToMessage()->withoutGlobalScopes()->first();

        if (! $original instanceof MailMessage || $original->getAttribute('thread_id') === null) {
            return false;
        }

        return MailMessage::query()->withoutGlobalScopes()
            ->where('thread_id', $original->getAttribute('thread_id'))
            ->where('id', '!=', $original->getKey())
            ->where('direction', 'inbound')
            ->where('imported_at', '>', $since)
            ->exists();
    }
}
