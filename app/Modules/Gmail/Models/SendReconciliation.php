<?php

declare(strict_types=1);

namespace App\Modules\Gmail\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Versandabgleich eines Entwurfs: Nachlesen der Nachricht mit Label SENT.
 *
 * Tabelle mail_send_reconciliations (docs/mail/02-datenmodell.md). Massenzuweisung offen ($guarded leer): Eingaben werden in
 * FormRequests und Services validiert, Models erhalten nur geprüfte Werte.
 */
class SendReconciliation extends Model
{
    protected $table = 'mail_send_reconciliations';

    /** @var array<int, string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'requested_at' => 'immutable_datetime',
            'found_in_sent_at' => 'immutable_datetime',
            'attempts' => 'integer',
            'next_check_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<MailDraft, $this>
     */
    public function draft(): BelongsTo
    {
        return $this->belongsTo(MailDraft::class, 'draft_id');
    }
}
