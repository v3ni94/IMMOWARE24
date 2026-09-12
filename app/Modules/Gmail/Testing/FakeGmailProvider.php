<?php

declare(strict_types=1);

namespace App\Modules\Gmail\Testing;

use App\Modules\Gmail\Contracts\GmailProviderInterface;
use App\Modules\Mail\Exceptions\MailRemoteException;

/**
 * In-Memory-Postfach für Tests. Führt Nachrichten, History, Entwürfe und Gesendet je Postfach-ID. Wird nur in testing
 * oder bei MAIL_GMAIL_PROVIDER=fake außerhalb von production gebunden (MailBootGuard). Ein Erfolg dieses Fakes ist
 * kein Live-Test der Gmail-API. raw wird wie beim Live-Provider dekodiert (MIME-Text) zurückgegeben.
 */
final class FakeGmailProvider implements GmailProviderInterface
{
    /** @var array<int, array<string, array<string, mixed>>> mailboxId => messageId => message */
    private array $messages = [];

    /** @var array<int, array<int, array<string, mixed>>> mailboxId => history records */
    private array $history = [];

    /** @var array<int, array<string, array<string, mixed>>> mailboxId => draftId => draft */
    private array $drafts = [];

    /** @var array<int, array<int, string>> mailboxId => messageIds mit Label SENT */
    private array $sent = [];

    /** @var array<int, array<int, array<string, mixed>>> */
    private array $sendAs = [];

    /** @var array<int, array{topic: string, label_ids: array<int, string>, history_id: string, expiration: string}|null> */
    private array $watches = [];

    /** @var array<int, array<string, mixed>> Protokoll aller Aufrufe */
    private array $calls = [];

    /** @var array<string, array{exception: \Throwable, skip: int}> Methode => Exception, die nach skip Aufrufen geworfen wird */
    private array $failures = [];

    /** @var array<int, string|null> mailboxId => historyId, ab der history.list als zu alt gilt (404) */
    private array $historyFloor = [];

    /** @var array<int, string> mailboxId => Postfachadresse */
    private array $emailAddresses = [];

    private int $historyCounter = 1000;

    private int $idCounter = 1;

    /**
     * Legt eine Nachricht ab und erzeugt einen History-Eintrag (messagesAdded).
     *
     * @param  array<string, mixed>  $attributes  from, to, subject, text, html, received_at (ISO), label_ids, thread_id, rfc_message_id, raw, headers
     */
    public function seedMessage(int $mailboxId, array $attributes = []): string
    {
        $id = (string) ($attributes['id'] ?? sprintf('fake-msg-%06d', $this->idCounter++));
        $historyId = (string) (++$this->historyCounter);
        $threadId = (string) ($attributes['thread_id'] ?? 'fake-thread-'.$id);
        $received = (string) ($attributes['received_at'] ?? now()->toIso8601String());

        $message = [
            'id' => $id,
            'thread_id' => $threadId,
            'history_id' => $historyId,
            'label_ids' => array_values((array) ($attributes['label_ids'] ?? ['INBOX', 'UNREAD'])),
            'internal_date' => (string) ((int) strtotime($received) * 1000),
            'snippet' => mb_substr((string) ($attributes['text'] ?? ''), 0, 200),
            'size_estimate' => strlen((string) ($attributes['text'] ?? '')),
            'headers' => [
                'From' => (string) ($attributes['from'] ?? 'absender@example.com'),
                'To' => (string) ($attributes['to'] ?? 'postfach@example.com'),
                'Subject' => (string) ($attributes['subject'] ?? 'Testnachricht'),
                'Date' => date(DATE_RFC2822, (int) strtotime($received)),
                'Message-ID' => (string) ($attributes['rfc_message_id'] ?? '<'.$id.'@fake.invalid>'),
            ] + (array) ($attributes['headers'] ?? []),
            'text' => (string) ($attributes['text'] ?? ''),
            'html' => $attributes['html'] ?? null,
            'attachments' => (array) ($attributes['attachments'] ?? []),
            'parts' => (array) ($attributes['parts'] ?? []),
            'raw_override' => $attributes['raw'] ?? null,
        ];

        $this->messages[$mailboxId][$id] = $message;
        $this->history[$mailboxId][] = ['id' => $historyId, 'messages_added' => [['id' => $id, 'thread_id' => $threadId, 'label_ids' => $message['label_ids']]]];

        return $id;
    }

    /**
     * Entfernt eine Nachricht (History messagesDeleted).
     */
    public function deleteMessage(int $mailboxId, string $messageId): void
    {
        $message = $this->messages[$mailboxId][$messageId] ?? null;
        unset($this->messages[$mailboxId][$messageId]);
        $this->history[$mailboxId][] = ['id' => (string) (++$this->historyCounter), 'messages_deleted' => [['id' => $messageId, 'thread_id' => (string) ($message['thread_id'] ?? ''), 'label_ids' => []]]];
    }

    /**
     * @param  array<int, string>  $add
     * @param  array<int, string>  $remove
     */
    public function changeLabels(int $mailboxId, string $messageId, array $add = [], array $remove = []): void
    {
        $message = $this->messages[$mailboxId][$messageId] ?? null;

        if ($message === null) {
            return;
        }

        $labels = array_values(array_diff(array_unique(array_merge($message['label_ids'], $add)), $remove));
        $this->messages[$mailboxId][$messageId]['label_ids'] = $labels;
        $record = ['id' => (string) (++$this->historyCounter)];

        if ($add !== []) {
            $record['labels_added'] = [['id' => $messageId, 'thread_id' => $message['thread_id'], 'label_ids' => array_values($add)]];
        }

        if ($remove !== []) {
            $record['labels_removed'] = [['id' => $messageId, 'thread_id' => $message['thread_id'], 'label_ids' => array_values($remove)]];
        }

        $this->history[$mailboxId][] = $record;
    }

    /**
     * Simuliert den Verlust alter History: history.list mit startHistoryId unterhalb dieser Grenze antwortet 404.
     */
    public function expireHistoryBefore(int $mailboxId, ?string $historyId = null): void
    {
        $this->historyFloor[$mailboxId] = $historyId ?? (string) $this->historyCounter;
    }

    /**
     * Entwurf außerhalb des Hubs löschen oder ändern (Konfliktszenarien).
     */
    public function removeDraft(int $mailboxId, string $draftId): void
    {
        unset($this->drafts[$mailboxId][$draftId]);
    }

    public function touchDraft(int $mailboxId, string $draftId, string $raw): void
    {
        if (isset($this->drafts[$mailboxId][$draftId])) {
            $this->drafts[$mailboxId][$draftId]['mime']['raw'] = $raw;
            $this->drafts[$mailboxId][$draftId]['updated']++;
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $aliases
     */
    public function seedSendAs(int $mailboxId, array $aliases): void
    {
        $this->sendAs[$mailboxId] = $aliases;
    }

    public function seedEmailAddress(int $mailboxId, string $email): void
    {
        $this->emailAddresses[$mailboxId] = $email;
    }

    /**
     * @param  int  $skip  Anzahl Aufrufe, die vorher noch erfolgreich durchlaufen (0 = der nächste Aufruf scheitert)
     */
    public function failNext(string $method, ?\Throwable $exception = null, int $skip = 0): void
    {
        $this->failures[$method] = ['exception' => $exception ?? new MailRemoteException('Fake: Gegenstelle antwortet mit Fehler.', 'gmail', 503, null), 'skip' => max(0, $skip)];
    }

    public function currentHistoryId(int $mailboxId): string
    {
        $records = $this->history[$mailboxId] ?? [];
        $last = end($records);

        return is_array($last) ? (string) $last['id'] : (string) $this->historyCounter;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function calls(?string $method = null): array
    {
        return $method === null
            ? $this->calls
            : array_values(array_filter($this->calls, static fn (array $call): bool => $call['method'] === $method));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function drafts(int $mailboxId): array
    {
        return $this->drafts[$mailboxId] ?? [];
    }

    /**
     * @return array<int, string>
     */
    public function sentIds(int $mailboxId): array
    {
        return $this->sent[$mailboxId] ?? [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function watchState(int $mailboxId): ?array
    {
        return $this->watches[$mailboxId] ?? null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function message(int $mailboxId, string $messageId): ?array
    {
        return $this->messages[$mailboxId][$messageId] ?? null;
    }

    public function listMessages(int $mailboxId, array $options = []): array
    {
        $this->record(__FUNCTION__, compact('mailboxId', 'options'));
        $labels = (array) ($options['label_ids'] ?? []);
        $max = (int) ($options['max_results'] ?? 100);
        $offset = (int) ($options['page_token'] ?? 0);
        $query = (string) ($options['query'] ?? '');

        $all = array_values(array_filter($this->messages[$mailboxId] ?? [], static function (array $message) use ($labels, $query): bool {
            if ($labels !== [] && array_intersect($labels, $message['label_ids']) === []) {
                return false;
            }

            if (preg_match('/rfc822msgid:(\S+)/', $query, $m) === 1) {
                return trim((string) ($message['headers']['Message-ID'] ?? ''), '<>') === trim($m[1], '<>');
            }

            return true;
        }));
        $page = array_slice($all, $offset, $max);
        $next = $offset + $max < count($all) ? (string) ($offset + $max) : null;

        return [
            'messages' => array_map(static fn (array $m): array => ['id' => $m['id'], 'thread_id' => $m['thread_id']], $page),
            'next_page_token' => $next,
            'result_size_estimate' => count($all),
        ];
    }

    public function getMessage(int $mailboxId, string $messageId, string $format = 'metadata'): array
    {
        $this->record(__FUNCTION__, compact('mailboxId', 'messageId', 'format'));
        $message = $this->messages[$mailboxId][$messageId] ?? null;

        if ($message === null) {
            throw new MailRemoteException('Fake: Nachricht nicht gefunden.', 'gmail', 404, null);
        }

        $rawOverride = $message['raw_override'];
        unset($message['raw_override']);

        if ($format === 'metadata') {
            unset($message['text'], $message['html'], $message['parts']);
        }

        if ($format === 'raw') {
            $message['raw'] = is_string($rawOverride) ? $rawOverride : $this->buildRaw($message);
        }

        return $message;
    }

    public function listHistory(int $mailboxId, string $startHistoryId, ?string $pageToken = null): array
    {
        return $this->listHistoryFiltered($mailboxId, $startHistoryId, [], null, $pageToken);
    }

    public function listHistoryFiltered(int $mailboxId, string $startHistoryId, array $historyTypes = [], ?string $labelId = null, ?string $pageToken = null, int $maxResults = 100): array
    {
        $this->record('listHistory', compact('mailboxId', 'startHistoryId', 'historyTypes', 'labelId', 'pageToken'));

        $floor = $this->historyFloor[$mailboxId] ?? null;

        if ($floor !== null && (int) $startHistoryId < (int) $floor) {
            throw new MailRemoteException('Fake: startHistoryId zu alt.', 'gmail', 404, null);
        }

        $records = array_values(array_filter($this->history[$mailboxId] ?? [], static fn (array $r): bool => (int) $r['id'] > (int) $startHistoryId));

        return ['history' => $records, 'history_id' => $this->currentHistoryId($mailboxId), 'next_page_token' => null];
    }

    public function getProfile(int $mailboxId): array
    {
        $this->record(__FUNCTION__, compact('mailboxId'));

        return [
            'email_address' => $this->emailAddresses[$mailboxId] ?? 'postfach@example.com',
            'history_id' => $this->currentHistoryId($mailboxId),
            'messages_total' => count($this->messages[$mailboxId] ?? []),
            'threads_total' => null,
        ];
    }

    public function getThread(int $mailboxId, string $threadId): array
    {
        $this->record(__FUNCTION__, compact('mailboxId', 'threadId'));
        $messages = [];

        foreach ($this->messages[$mailboxId] ?? [] as $message) {
            if ($message['thread_id'] === $threadId) {
                unset($message['text'], $message['html'], $message['parts'], $message['raw_override']);
                $messages[] = $message;
            }
        }

        return ['id' => $threadId, 'history_id' => $this->currentHistoryId($mailboxId), 'messages' => $messages];
    }

    public function watch(int $mailboxId, string $topicName, array $labelIds = ['INBOX']): array
    {
        $this->record(__FUNCTION__, compact('mailboxId', 'topicName', 'labelIds'));
        $result = ['history_id' => $this->currentHistoryId($mailboxId), 'expiration' => (string) ((time() + 7 * 86400) * 1000)];
        $this->watches[$mailboxId] = ['topic' => $topicName, 'label_ids' => $labelIds] + $result;

        return $result;
    }

    public function stopWatch(int $mailboxId): void
    {
        $this->record(__FUNCTION__, compact('mailboxId'));
        $this->watches[$mailboxId] = null;
    }

    public function createDraft(int $mailboxId, array $mime): array
    {
        $this->record(__FUNCTION__, compact('mailboxId', 'mime'));
        $draftId = sprintf('fake-draft-%06d', $this->idCounter++);
        $messageId = sprintf('fake-draftmsg-%06d', $this->idCounter++);
        $this->drafts[$mailboxId][$draftId] = ['id' => $draftId, 'message_id' => $messageId, 'mime' => $mime, 'updated' => 1];

        return ['draft_id' => $draftId, 'message_id' => $messageId];
    }

    public function updateDraft(int $mailboxId, string $draftId, array $mime): array
    {
        $this->record(__FUNCTION__, compact('mailboxId', 'draftId', 'mime'));
        $draft = $this->drafts[$mailboxId][$draftId] ?? null;

        if ($draft === null) {
            throw new MailRemoteException('Fake: Entwurf nicht gefunden.', 'gmail', 404, null);
        }

        $draft['mime'] = $mime;
        $draft['updated']++;
        $this->drafts[$mailboxId][$draftId] = $draft;

        return ['draft_id' => $draftId, 'message_id' => (string) $draft['message_id']];
    }

    public function getDraft(int $mailboxId, string $draftId): array
    {
        $this->record(__FUNCTION__, compact('mailboxId', 'draftId'));
        $draft = $this->drafts[$mailboxId][$draftId] ?? null;

        if ($draft === null) {
            throw new MailRemoteException('Fake: Entwurf nicht gefunden.', 'gmail', 404, null);
        }

        $mime = (array) $draft['mime'];

        return [
            'id' => $draftId,
            'message_id' => (string) $draft['message_id'],
            'message' => [
                'id' => (string) $draft['message_id'],
                'thread_id' => (string) ($mime['thread_id'] ?? ''),
                'label_ids' => ['DRAFT'],
                'raw' => (string) ($mime['raw'] ?? ''),
                'headers' => [],
            ],
            'updated' => $draft['updated'],
        ];
    }

    public function sendDraft(int $mailboxId, string $draftId): array
    {
        $this->record(__FUNCTION__, compact('mailboxId', 'draftId'));
        $draft = $this->drafts[$mailboxId][$draftId] ?? null;

        if ($draft === null) {
            throw new MailRemoteException('Fake: Entwurf nicht gefunden.', 'gmail', 404, null);
        }

        unset($this->drafts[$mailboxId][$draftId]);
        $mime = (array) $draft['mime'];
        $sentId = $this->seedMessage($mailboxId, [
            'id' => (string) $draft['message_id'],
            'from' => (string) ($mime['from'] ?? ''),
            'to' => implode(', ', (array) ($mime['to'] ?? [])),
            'subject' => (string) ($mime['subject'] ?? ''),
            'text' => (string) ($mime['text'] ?? ''),
            'label_ids' => ['SENT'],
            'rfc_message_id' => (string) ($mime['message_id'] ?? '<'.$draft['message_id'].'@fake.invalid>'),
            'thread_id' => $mime['thread_id'] ?? null,
            'raw' => $mime['raw'] ?? null,
        ]);
        $this->sent[$mailboxId][] = $sentId;

        return ['message_id' => $sentId, 'thread_id' => $this->messages[$mailboxId][$sentId]['thread_id'], 'label_ids' => ['SENT']];
    }

    public function listSent(int $mailboxId, ?string $rfcMessageId = null, ?string $pageToken = null): array
    {
        $this->record(__FUNCTION__, compact('mailboxId', 'rfcMessageId', 'pageToken'));
        $messages = [];

        foreach ($this->sent[$mailboxId] ?? [] as $id) {
            $message = $this->messages[$mailboxId][$id] ?? null;

            if ($message === null) {
                continue;
            }

            if ($rfcMessageId === null || trim((string) ($message['headers']['Message-ID'] ?? ''), '<>') === trim($rfcMessageId, '<>')) {
                $messages[] = ['id' => $message['id'], 'thread_id' => $message['thread_id']];
            }
        }

        return ['messages' => $messages, 'next_page_token' => null];
    }

    public function listSendAs(int $mailboxId): array
    {
        $this->record(__FUNCTION__, compact('mailboxId'));

        return $this->sendAs[$mailboxId] ?? [];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function record(string $method, array $arguments): void
    {
        $this->calls[] = ['method' => $method, 'arguments' => $arguments];

        if (isset($this->failures[$method])) {
            if ($this->failures[$method]['skip'] > 0) {
                $this->failures[$method]['skip']--;

                return;
            }

            $exception = $this->failures[$method]['exception'];
            unset($this->failures[$method]);

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function buildRaw(array $message): string
    {
        $lines = [];

        foreach ((array) $message['headers'] as $name => $value) {
            $lines[] = $name.': '.$value;
        }

        $lines[] = 'MIME-Version: 1.0';
        $lines[] = 'Content-Type: text/plain; charset=utf-8';
        $lines[] = 'Content-Transfer-Encoding: 8bit';
        $lines[] = '';
        $lines[] = (string) ($message['text'] ?? '');

        return implode("\r\n", $lines);
    }
}
