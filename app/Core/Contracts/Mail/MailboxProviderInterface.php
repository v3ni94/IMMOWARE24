<?php

declare(strict_types=1);

namespace App\Core\Contracts\Mail;

/**
 * Interner Adaptervertrag für ein Postfachsystem (Gmail). Kein Herstellerendpunkt: Rückgaben sind normalisierte
 * Arrays des Hubs, die Abbildung auf die Gmail-API liegt ausschließlich im Modul Gmail. Aussagen zur Fremd-API
 * stammen aus Snippets (docs/mail/research) und sind vor Implementierung am Original zu prüfen.
 *
 * Alle Methoden werfen App\Modules\Mail\Exceptions\MailIntegrationNotConfiguredException, wenn das Postfach
 * nicht eingerichtet ist, und App\Modules\Mail\Exceptions\MailRemoteException bei Fehlern der Gegenstelle.
 */
interface MailboxProviderInterface
{
    /**
     * @param  array<string, mixed>  $options  z. B. ['page_token' => ..., 'query' => ..., 'max_results' => 100, 'label_ids' => [...]]
     * @return array{messages: array<int, array{id: string, thread_id: string}>, next_page_token: ?string, result_size_estimate: ?int}
     */
    public function listMessages(int $mailboxId, array $options = []): array;

    /**
     * @param  string  $format  metadata|full|raw
     * @return array<string, mixed> Normalisierte Nachricht: id, thread_id, history_id, label_ids, internal_date, headers, parts, snippet, size_estimate, raw (bei raw)
     */
    public function getMessage(int $mailboxId, string $messageId, string $format = 'metadata'): array;

    /**
     * @return array{history: array<int, array<string, mixed>>, history_id: ?string, next_page_token: ?string}
     */
    public function listHistory(int $mailboxId, string $startHistoryId, ?string $pageToken = null): array;

    /**
     * Beginnt Push-Benachrichtigungen. Rückgabe ist nur ein "requested", kein bestätigter Watch.
     *
     * @param  array<int, string>  $labelIds
     * @return array{history_id: string, expiration: string}
     */
    public function watch(int $mailboxId, string $topicName, array $labelIds = ['INBOX']): array;

    public function stopWatch(int $mailboxId): void;

    /**
     * @param  array<string, mixed>  $mime  Normalisierte Nachricht: from, to[], cc[], bcc[], subject, text, html, attachments[], in_reply_to, references[], message_id
     * @return array{draft_id: string, message_id: string}
     */
    public function createDraft(int $mailboxId, array $mime): array;

    /**
     * @param  array<string, mixed>  $mime
     * @return array{draft_id: string, message_id: string}
     */
    public function updateDraft(int $mailboxId, string $draftId, array $mime): array;

    /**
     * @return array<string, mixed>
     */
    public function getDraft(int $mailboxId, string $draftId): array;

    /**
     * Versand anfordern. Die Rückgabe ist kein verifizierter Versand; Verifikation erfolgt über listSent/getMessage
     * (Label SENT, RFC-Message-ID) im SendReconciliationService.
     *
     * @return array{message_id: string, thread_id: ?string, label_ids: array<int, string>}
     */
    public function sendDraft(int $mailboxId, string $draftId): array;

    /**
     * @return array{messages: array<int, array{id: string, thread_id: string}>, next_page_token: ?string}
     */
    public function listSent(int $mailboxId, ?string $rfcMessageId = null, ?string $pageToken = null): array;

    /**
     * Absenderadressen des Postfachs (Send-as). Die Signatur kommt aus dem CI-Skill, nicht aus dem Postfach.
     *
     * @return array<int, array{send_as_email: string, display_name: ?string, reply_to: ?string, is_default: bool, is_primary: bool, verification_status: string}>
     */
    public function listSendAs(int $mailboxId): array;
}
