<?php

declare(strict_types=1);

namespace App\Modules\Gmail\Services\Sync;

use App\Modules\Gmail\Models\MailSyncState;
use App\Modules\Mail\Models\Mailbox;
use Carbon\CarbonImmutable;

/**
 * Zugriff auf mail_sync_states je Postfach. Die History-ID wird erst nach erfolgreicher Verarbeitung gespeichert
 * (commitHistoryId), nie vorab. Eine kleinere History-ID überschreibt nie eine größere.
 */
final class SyncStateService
{
    public function for(Mailbox $mailbox): MailSyncState
    {
        return MailSyncState::query()->firstOrCreate(['mailbox_id' => $mailbox->getKey()], ['watch_status' => 'none']);
    }

    public function commitHistoryId(Mailbox $mailbox, string $historyId, bool $incremental = true): MailSyncState
    {
        $state = $this->for($mailbox);
        $current = $state->getAttribute('last_history_id');

        if (! is_string($current) || $current === '' || $this->compare($historyId, $current) > 0) {
            $state->setAttribute('last_history_id', $historyId);
            $state->setAttribute('history_id_updated_at', CarbonImmutable::now());
        }

        if ($incremental) {
            $state->setAttribute('last_incremental_at', CarbonImmutable::now());
        }

        // Erster erfolgreicher History-Abgleich nach einem Watch bestätigt den Watch (requested → active).
        if ($state->getAttribute('watch_status') === 'requested' && $incremental) {
            $state->setAttribute('watch_status', 'active');
            $state->setAttribute('watch_confirmed_at', CarbonImmutable::now());
        }

        $state->save();

        return $state;
    }

    /**
     * uint64-Vergleich als Strings ohne Überlauf.
     */
    public function compare(string $a, string $b): int
    {
        $a = ltrim($a, '0');
        $b = ltrim($b, '0');

        if (strlen($a) !== strlen($b)) {
            return strlen($a) <=> strlen($b);
        }

        return strcmp($a, $b) <=> 0;
    }
}
