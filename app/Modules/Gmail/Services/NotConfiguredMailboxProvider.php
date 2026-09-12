<?php

declare(strict_types=1);

namespace App\Modules\Gmail\Services;

use App\Modules\Gmail\Contracts\GmailProviderInterface;
use App\Modules\Mail\Exceptions\MailIntegrationNotConfiguredException;

/**
 * Gebunden, solange kein Gmail-Provider konfiguriert ist. Jede Methode wirft MailIntegrationNotConfiguredException,
 * damit die Oberfläche "Nicht eingerichtet" zeigt und nie ein Erfolg entsteht.
 */
final class NotConfiguredMailboxProvider implements GmailProviderInterface
{
    public function listMessages(int $mailboxId, array $options = []): array
    {
        throw MailIntegrationNotConfiguredException::for('gmail');
    }

    public function getMessage(int $mailboxId, string $messageId, string $format = 'metadata'): array
    {
        throw MailIntegrationNotConfiguredException::for('gmail');
    }

    public function listHistory(int $mailboxId, string $startHistoryId, ?string $pageToken = null): array
    {
        throw MailIntegrationNotConfiguredException::for('gmail');
    }

    public function watch(int $mailboxId, string $topicName, array $labelIds = ['INBOX']): array
    {
        throw MailIntegrationNotConfiguredException::for('gmail');
    }

    public function stopWatch(int $mailboxId): void
    {
        throw MailIntegrationNotConfiguredException::for('gmail');
    }

    public function createDraft(int $mailboxId, array $mime): array
    {
        throw MailIntegrationNotConfiguredException::for('gmail');
    }

    public function updateDraft(int $mailboxId, string $draftId, array $mime): array
    {
        throw MailIntegrationNotConfiguredException::for('gmail');
    }

    public function getDraft(int $mailboxId, string $draftId): array
    {
        throw MailIntegrationNotConfiguredException::for('gmail');
    }

    public function sendDraft(int $mailboxId, string $draftId): array
    {
        throw MailIntegrationNotConfiguredException::for('gmail');
    }

    public function listSent(int $mailboxId, ?string $rfcMessageId = null, ?string $pageToken = null): array
    {
        throw MailIntegrationNotConfiguredException::for('gmail');
    }

    public function listSendAs(int $mailboxId): array
    {
        throw MailIntegrationNotConfiguredException::for('gmail');
    }

    public function getProfile(int $mailboxId): array
    {
        throw MailIntegrationNotConfiguredException::for('gmail');
    }

    public function listHistoryFiltered(int $mailboxId, string $startHistoryId, array $historyTypes = [], ?string $labelId = null, ?string $pageToken = null, int $maxResults = 100): array
    {
        throw MailIntegrationNotConfiguredException::for('gmail');
    }

    public function getThread(int $mailboxId, string $threadId): array
    {
        throw MailIntegrationNotConfiguredException::for('gmail');
    }
}
