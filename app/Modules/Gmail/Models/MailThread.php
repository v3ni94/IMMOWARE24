<?php

declare(strict_types=1);

namespace App\Modules\Gmail\Models;

use App\Core\Traits\BelongsToOrganization;
use App\Modules\Mail\Models\Mailbox;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Gmail-Thread eines Postfachs.
 *
 * Tabelle mail_threads (docs/mail/02-datenmodell.md). Massenzuweisung offen ($guarded leer): Eingaben werden in
 * FormRequests und Services validiert, Models erhalten nur geprüfte Werte.
 */
class MailThread extends Model
{
    use BelongsToOrganization;

    protected $table = 'mail_threads';

    /** @var array<int, string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'first_message_at' => 'immutable_datetime',
            'last_message_at' => 'immutable_datetime',
            'message_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Mailbox, $this>
     */
    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class, 'mailbox_id');
    }

    /**
     * @return HasMany<MailMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(MailMessage::class, 'thread_id');
    }
}
