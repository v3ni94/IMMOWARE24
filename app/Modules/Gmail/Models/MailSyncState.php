<?php

declare(strict_types=1);

namespace App\Modules\Gmail\Models;

use App\Modules\Mail\Models\Mailbox;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sync-Zustand je Postfach. watch_status requested bedeutet nur HTTP 200 auf watch, active erst nach erstem Push oder History-Abgleich.
 *
 * Tabelle mail_sync_states (docs/mail/02-datenmodell.md). Massenzuweisung offen ($guarded leer): Eingaben werden in
 * FormRequests und Services validiert, Models erhalten nur geprüfte Werte.
 */
class MailSyncState extends Model
{
    protected $table = 'mail_sync_states';

    /** @var array<int, string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'history_id_updated_at' => 'immutable_datetime',
            'watch_expiration' => 'immutable_datetime',
            'watch_requested_at' => 'immutable_datetime',
            'watch_confirmed_at' => 'immutable_datetime',
            'full_sync_started_at' => 'immutable_datetime',
            'full_sync_finished_at' => 'immutable_datetime',
            'last_incremental_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Mailbox, $this>
     */
    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class, 'mailbox_id');
    }
}
