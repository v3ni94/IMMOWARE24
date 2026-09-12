<?php

declare(strict_types=1);

namespace App\Modules\Gmail\Services\Sync;

use App\Modules\Gmail\Contracts\GmailProviderInterface;
use App\Modules\Gmail\Models\MailMessage;
use App\Modules\Mail\Models\Mailbox;

/**
 * Abgleich der Labels INBOX, SENT, DRAFT gegen die Datenbank: listet Nachrichten je Label seitenweise und importiert
 * fehlende (Lückenerkennung). Löscht nie; Nachrichten, die in Gmail fehlen, bleiben mit deleted_at unberührt bis ein
 * History-Eintrag messagesDeleted eintrifft. Wird von FullResyncJob und ReconcileJob genutzt.
 */
final class LabelReconciler
{
    public function __construct(
        private readonly GmailProviderInterface $provider,
        private readonly MessageImporter $importer,
    ) {}

    /**
     * @return array{imported: int, checked: int, next_page_token: ?string}
     */
    public function reconcileLabel(Mailbox $mailbox, string $labelId, ?string $pageToken, int $pageSize, int $maxImports): array
    {
        $list = $this->provider->listMessages((int) $mailbox->getKey(), [
            'label_ids' => [$labelId],
            'max_results' => max(1, $pageSize),
            'page_token' => $pageToken,
        ]);

        $remoteIds = array_map(static fn (array $m): string => (string) $m['id'], $list['messages']);

        if ($remoteIds === []) {
            return ['imported' => 0, 'checked' => 0, 'next_page_token' => $list['next_page_token']];
        }

        $known = MailMessage::query()->withoutGlobalScopes()->withTrashed()
            ->where('mailbox_id', $mailbox->getKey())
            ->whereIn('gmail_message_id', $remoteIds)
            ->pluck('gmail_message_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        $missing = array_values(array_diff($remoteIds, $known));
        $imported = 0;

        foreach ($missing as $gmailMessageId) {
            if ($imported >= $maxImports) {
                break;
            }

            if ($this->importer->import($mailbox, $gmailMessageId) !== null) {
                $imported++;
            }
        }

        return ['imported' => $imported, 'checked' => count($remoteIds), 'next_page_token' => $list['next_page_token']];
    }
}
