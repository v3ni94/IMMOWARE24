<?php

declare(strict_types=1);

namespace App\Modules\Mail\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Send-as-Adresse eines Postfachs. Signatur kommt aus dem CI-Skill (signature_key), nicht aus Gmail.
 *
 * Tabelle mail_mailbox_aliases (docs/mail/02-datenmodell.md). Massenzuweisung offen ($guarded leer): Eingaben werden in
 * FormRequests und Services validiert, Models erhalten nur geprüfte Werte.
 */
class MailboxAlias extends Model
{
    protected $table = 'mail_mailbox_aliases';

    /** @var array<int, string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_primary' => 'boolean',
            'synced_at' => 'immutable_datetime',
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
