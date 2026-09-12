<?php

declare(strict_types=1);

namespace App\Modules\Gmail\Services;

use App\Modules\Gmail\Contracts\GmailProviderInterface;
use App\Modules\Gmail\Mime\MimeBuilder;
use App\Modules\Mail\Exceptions\MailRemoteException;
use App\Modules\Mail\Models\Mailbox;

/**
 * Live-Implementierung des Postfachvertrags über die Gmail REST API (nur Http-Facade, GmailApiClient).
 * Normalisiert die Antworten auf die Hub-Form des Vertrags. Jede URL gemäß Gmail API Referenz, vor Produktivbetrieb
 * am Original prüfen. Rückgaben sind Antworten der Gegenstelle, keine verifizierten Geschäftsergebnisse: watch()
 * liefert nur "requested", sendDraft() nur "angefordert", die Verifikation erfolgt in den Services.
 * raw wird dekodiert (MIME-Text) zurückgegeben, damit MimeParser direkt arbeiten kann.
 */
final class GmailProvider implements GmailProviderInterface
{
    /** Header, die bei format=metadata angefordert werden. */
    public const array METADATA_HEADERS = ['From', 'To', 'Cc', 'Bcc', 'Reply-To', 'Subject', 'Date', 'Message-ID', 'In-Reply-To', 'References'];

    public function __construct(private readonly GmailApiClient $client) {}

    public function listMessages(int $mailboxId, array $options = []): array
    {
        $mailbox = $this->mailbox($mailboxId);

        // gemäß Gmail API Referenz (users.messages.list), vor Produktivbetrieb am Original prüfen
        $response = $this->client->get($mailbox, 'messages.list', 'users/me/messages', [
            'maxResults' => (int) ($options['max_results'] ?? 100),
            'pageToken' => $options['page_token'] ?? null,
            'labelIds' => (array) ($options['label_ids'] ?? []),
            'q' => $options['query'] ?? null,
            'includeSpamTrash' => isset($options['include_spam_trash']) ? (bool) $options['include_spam_trash'] : null,
        ]);

        return [
            'messages' => array_map(static fn (array $m): array => ['id' => (string) $m['id'], 'thread_id' => (string) ($m['threadId'] ?? '')], (array) ($response['messages'] ?? [])),
            'next_page_token' => isset($response['nextPageToken']) ? (string) $response['nextPageToken'] : null,
            'result_size_estimate' => isset($response['resultSizeEstimate']) ? (int) $response['resultSizeEstimate'] : null,
        ];
    }

    public function getMessage(int $mailboxId, string $messageId, string $format = 'metadata'): array
    {
        $mailbox = $this->mailbox($mailboxId);
        $query = ['format' => $format];

        if ($format === 'metadata') {
            $query['metadataHeaders'] = self::METADATA_HEADERS;
        }

        // gemäß Gmail API Referenz (users.messages.get), vor Produktivbetrieb am Original prüfen
        $response = $this->client->get($mailbox, 'messages.get', 'users/me/messages/'.rawurlencode($messageId), $query);

        return $this->normalizeMessage($response);
    }

    public function listHistory(int $mailboxId, string $startHistoryId, ?string $pageToken = null): array
    {
        return $this->listHistoryFiltered($mailboxId, $startHistoryId, [], null, $pageToken);
    }

    public function listHistoryFiltered(int $mailboxId, string $startHistoryId, array $historyTypes = [], ?string $labelId = null, ?string $pageToken = null, int $maxResults = 100): array
    {
        $mailbox = $this->mailbox($mailboxId);

        // gemäß Gmail API Referenz (users.history.list), vor Produktivbetrieb am Original prüfen
        $response = $this->client->get($mailbox, 'history.list', 'users/me/history', [
            'startHistoryId' => $startHistoryId,
            'historyTypes' => $historyTypes,
            'labelId' => $labelId,
            'pageToken' => $pageToken,
            'maxResults' => $maxResults,
        ]);

        $history = [];

        foreach ((array) ($response['history'] ?? []) as $record) {
            $history[] = [
                'id' => (string) ($record['id'] ?? ''),
                'messages_added' => $this->normalizeHistoryMessages((array) ($record['messagesAdded'] ?? [])),
                'messages_deleted' => $this->normalizeHistoryMessages((array) ($record['messagesDeleted'] ?? [])),
                'labels_added' => $this->normalizeHistoryMessages((array) ($record['labelsAdded'] ?? [])),
                'labels_removed' => $this->normalizeHistoryMessages((array) ($record['labelsRemoved'] ?? [])),
            ];
        }

        return [
            'history' => $history,
            'history_id' => isset($response['historyId']) ? (string) $response['historyId'] : null,
            'next_page_token' => isset($response['nextPageToken']) ? (string) $response['nextPageToken'] : null,
        ];
    }

    public function getProfile(int $mailboxId): array
    {
        $mailbox = $this->mailbox($mailboxId);

        // gemäß Gmail API Referenz (users.getProfile), vor Produktivbetrieb am Original prüfen
        $response = $this->client->get($mailbox, 'getProfile', 'users/me/profile');

        if (! isset($response['historyId'])) {
            throw new MailRemoteException('Profilantwort ohne historyId.', 'gmail', null, null);
        }

        return [
            'email_address' => (string) ($response['emailAddress'] ?? ''),
            'history_id' => (string) $response['historyId'],
            'messages_total' => isset($response['messagesTotal']) ? (int) $response['messagesTotal'] : null,
            'threads_total' => isset($response['threadsTotal']) ? (int) $response['threadsTotal'] : null,
        ];
    }

    public function getThread(int $mailboxId, string $threadId): array
    {
        $mailbox = $this->mailbox($mailboxId);

        // gemäß Gmail API Referenz (users.threads.get), vor Produktivbetrieb am Original prüfen
        $response = $this->client->get($mailbox, 'threads.get', 'users/me/threads/'.rawurlencode($threadId), [
            'format' => 'metadata',
            'metadataHeaders' => self::METADATA_HEADERS,
        ]);

        return [
            'id' => (string) ($response['id'] ?? $threadId),
            'history_id' => isset($response['historyId']) ? (string) $response['historyId'] : null,
            'messages' => array_map(fn (array $m): array => $this->normalizeMessage($m), (array) ($response['messages'] ?? [])),
        ];
    }

    public function watch(int $mailboxId, string $topicName, array $labelIds = ['INBOX']): array
    {
        $mailbox = $this->mailbox($mailboxId);

        // gemäß Gmail API Referenz (users.watch), vor Produktivbetrieb am Original prüfen
        $response = $this->client->post($mailbox, 'watch', 'users/me/watch', [
            'topicName' => $topicName,
            'labelIds' => array_values($labelIds),
            'labelFilterBehavior' => 'include',
        ]);

        if (! isset($response['historyId'], $response['expiration'])) {
            throw new MailRemoteException('watch-Antwort ohne historyId oder expiration.', 'gmail', null, null);
        }

        return ['history_id' => (string) $response['historyId'], 'expiration' => (string) $response['expiration']];
    }

    public function stopWatch(int $mailboxId): void
    {
        $mailbox = $this->mailbox($mailboxId);

        // gemäß Gmail API Referenz (users.stop), vor Produktivbetrieb am Original prüfen
        $this->client->post($mailbox, 'stop', 'users/me/stop', []);
    }

    public function createDraft(int $mailboxId, array $mime): array
    {
        $mailbox = $this->mailbox($mailboxId);

        // gemäß Gmail API Referenz (users.drafts.create), vor Produktivbetrieb am Original prüfen
        $response = $this->client->post($mailbox, 'drafts.create', 'users/me/drafts', ['message' => $this->draftMessageBody($mime)]);

        return $this->normalizeDraftReference($response);
    }

    public function updateDraft(int $mailboxId, string $draftId, array $mime): array
    {
        $mailbox = $this->mailbox($mailboxId);

        // gemäß Gmail API Referenz (users.drafts.update), vor Produktivbetrieb am Original prüfen
        $response = $this->client->put($mailbox, 'drafts.update', 'users/me/drafts/'.rawurlencode($draftId), ['id' => $draftId, 'message' => $this->draftMessageBody($mime)]);

        return $this->normalizeDraftReference($response);
    }

    public function getDraft(int $mailboxId, string $draftId): array
    {
        $mailbox = $this->mailbox($mailboxId);

        // gemäß Gmail API Referenz (users.drafts.get), vor Produktivbetrieb am Original prüfen
        $response = $this->client->get($mailbox, 'drafts.get', 'users/me/drafts/'.rawurlencode($draftId), ['format' => 'raw']);
        $message = (array) ($response['message'] ?? []);

        return [
            'id' => (string) ($response['id'] ?? $draftId),
            'message_id' => (string) ($message['id'] ?? ''),
            'message' => $this->normalizeMessage($message),
        ];
    }

    public function sendDraft(int $mailboxId, string $draftId): array
    {
        $mailbox = $this->mailbox($mailboxId);

        // gemäß Gmail API Referenz (users.drafts.send), vor Produktivbetrieb am Original prüfen
        $response = $this->client->post($mailbox, 'drafts.send', 'users/me/drafts/send', ['id' => $draftId]);

        return [
            'message_id' => (string) ($response['id'] ?? ''),
            'thread_id' => isset($response['threadId']) ? (string) $response['threadId'] : null,
            'label_ids' => array_values(array_map('strval', (array) ($response['labelIds'] ?? []))),
        ];
    }

    public function listSent(int $mailboxId, ?string $rfcMessageId = null, ?string $pageToken = null): array
    {
        $result = $this->listMessages($mailboxId, [
            'label_ids' => ['SENT'],
            'query' => $rfcMessageId !== null ? 'rfc822msgid:'.trim($rfcMessageId, '<>') : null,
            'page_token' => $pageToken,
            'max_results' => 50,
        ]);

        return ['messages' => $result['messages'], 'next_page_token' => $result['next_page_token']];
    }

    public function listSendAs(int $mailboxId): array
    {
        $mailbox = $this->mailbox($mailboxId);

        // gemäß Gmail API Referenz (users.settings.sendAs.list), vor Produktivbetrieb am Original prüfen
        $response = $this->client->get($mailbox, 'sendAs.list', 'users/me/settings/sendAs');
        $result = [];

        foreach ((array) ($response['sendAs'] ?? []) as $alias) {
            $result[] = [
                'send_as_email' => strtolower((string) ($alias['sendAsEmail'] ?? '')),
                'display_name' => isset($alias['displayName']) && $alias['displayName'] !== '' ? (string) $alias['displayName'] : null,
                'reply_to' => isset($alias['replyToAddress']) && $alias['replyToAddress'] !== '' ? (string) $alias['replyToAddress'] : null,
                'is_default' => (bool) ($alias['isDefault'] ?? false),
                'is_primary' => (bool) ($alias['isPrimary'] ?? false),
                'verification_status' => strtolower((string) ($alias['verificationStatus'] ?? 'unknown')),
            ];
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $message  Gmail-Message-Ressource
     * @return array<string, mixed>
     */
    private function normalizeMessage(array $message): array
    {
        $headers = [];

        foreach ((array) data_get($message, 'payload.headers', []) as $header) {
            if (isset($header['name'], $header['value'])) {
                $headers[(string) $header['name']] = (string) $header['value'];
            }
        }

        $normalized = [
            'id' => (string) ($message['id'] ?? ''),
            'thread_id' => (string) ($message['threadId'] ?? ''),
            'history_id' => isset($message['historyId']) ? (string) $message['historyId'] : null,
            'label_ids' => array_values(array_map('strval', (array) ($message['labelIds'] ?? []))),
            'internal_date' => isset($message['internalDate']) ? (string) $message['internalDate'] : null,
            'snippet' => isset($message['snippet']) ? (string) $message['snippet'] : null,
            'size_estimate' => isset($message['sizeEstimate']) ? (int) $message['sizeEstimate'] : null,
            'headers' => $headers,
            'parts' => (array) data_get($message, 'payload.parts', []),
            'payload' => (array) ($message['payload'] ?? []),
        ];

        if (isset($message['raw']) && is_string($message['raw'])) {
            $normalized['raw'] = MimeBuilder::base64urlDecode($message['raw']);
        }

        return $normalized;
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     * @return array<int, array{id: string, thread_id: string, label_ids: array<int, string>}>
     */
    private function normalizeHistoryMessages(array $entries): array
    {
        $result = [];

        foreach ($entries as $entry) {
            $message = (array) ($entry['message'] ?? []);
            $labels = (array) ($entry['labelIds'] ?? $message['labelIds'] ?? []);
            $result[] = [
                'id' => (string) ($message['id'] ?? ''),
                'thread_id' => (string) ($message['threadId'] ?? ''),
                'label_ids' => array_values(array_map('strval', $labels)),
            ];
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $mime  Normalisiert (raw bereits gebaut) oder mit Schlüssel raw
     * @return array<string, mixed>
     */
    private function draftMessageBody(array $mime): array
    {
        $raw = $mime['raw'] ?? null;

        if (! is_string($raw) || $raw === '') {
            throw new MailRemoteException('Entwurf ohne MIME-Inhalt (raw).', 'gmail', null, null);
        }

        $body = ['raw' => MimeBuilder::base64url($raw)];

        if (isset($mime['thread_id']) && is_string($mime['thread_id']) && $mime['thread_id'] !== '') {
            $body['threadId'] = $mime['thread_id'];
        }

        return $body;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array{draft_id: string, message_id: string}
     */
    private function normalizeDraftReference(array $response): array
    {
        if (! isset($response['id'])) {
            throw new MailRemoteException('Entwurfsantwort ohne id.', 'gmail', null, null);
        }

        return ['draft_id' => (string) $response['id'], 'message_id' => (string) data_get($response, 'message.id', '')];
    }

    private function mailbox(int $mailboxId): Mailbox
    {
        $mailbox = Mailbox::query()->withoutGlobalScopes()->find($mailboxId);

        if (! $mailbox instanceof Mailbox) {
            throw new MailRemoteException('Postfach '.$mailboxId.' unbekannt.', 'gmail', null, null);
        }

        return $mailbox;
    }
}
