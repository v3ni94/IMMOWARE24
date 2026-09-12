<?php

declare(strict_types=1);

namespace App\Modules\Gmail\Contracts;

use App\Core\Contracts\Mail\MailboxProviderInterface;

/**
 * Modulinterne Erweiterung des Kernvertrags MailboxProviderInterface um Profil, Thread und History-Filter.
 * Offen: Übernahme dieser Methoden in app/Core/Contracts/Mail (nicht Teil dieses Moduls). GmailProvider (live)
 * und FakeGmailProvider (Tests) implementieren beide Verträge; die Jobs des Moduls hängen von diesem ab.
 */
interface GmailProviderInterface extends MailboxProviderInterface
{
    /**
     * users.getProfile: liefert emailAddress, messagesTotal, threadsTotal, historyId.
     *
     * @return array{email_address: string, history_id: string, messages_total: ?int, threads_total: ?int}
     */
    public function getProfile(int $mailboxId): array;

    /**
     * users.history.list mit Filtern. Normalisierte Records: id, messages_added[], messages_deleted[],
     * labels_added[], labels_removed[] (jeweils id, thread_id, label_ids).
     *
     * @param  array<int, string>  $historyTypes  messageAdded, messageDeleted, labelAdded, labelRemoved
     * @return array{history: array<int, array<string, mixed>>, history_id: ?string, next_page_token: ?string}
     */
    public function listHistoryFiltered(int $mailboxId, string $startHistoryId, array $historyTypes = [], ?string $labelId = null, ?string $pageToken = null, int $maxResults = 100): array;

    /**
     * users.threads.get: Nachrichten eines Threads (metadata).
     *
     * @return array{id: string, history_id: ?string, messages: array<int, array<string, mixed>>}
     */
    public function getThread(int $mailboxId, string $threadId): array;
}
