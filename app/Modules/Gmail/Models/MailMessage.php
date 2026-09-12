<?php

declare(strict_types=1);

namespace App\Modules\Gmail\Models;

use App\Core\Traits\BelongsToOrganization;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Mail\Models\Mailbox;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Importierte Nachricht. Unique (mailbox_id, gmail_message_id). is_read_in_gmail ist informativ: gelesen ist nicht bearbeitet. Absenderzuordnung nie über Namen.
 *
 * Tabelle mail_messages (docs/mail/02-datenmodell.md). Massenzuweisung offen ($guarded leer): Eingaben werden in
 * FormRequests und Services validiert, Models erhalten nur geprüfte Werte.
 */
class MailMessage extends Model
{
    use BelongsToOrganization, SoftDeletes;

    protected $table = 'mail_messages';

    /** @var array<int, string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'references_json' => 'array',
            'to_json' => 'array',
            'cc_json' => 'array',
            'bcc_json' => 'array',
            'received_at' => 'immutable_datetime',
            'imported_at' => 'immutable_datetime',
            'label_ids_json' => 'array',
            'is_read_in_gmail' => 'boolean',
            'has_attachments' => 'boolean',
            'size_estimate' => 'integer',
            'body_fetched_at' => 'immutable_datetime',
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
     * @return BelongsTo<MailThread, $this>
     */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(MailThread::class, 'thread_id');
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function senderContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'sender_contact_id');
    }

    /**
     * @return HasMany<MailMessagePart, $this>
     */
    public function parts(): HasMany
    {
        return $this->hasMany(MailMessagePart::class, 'message_id');
    }

    /**
     * @return HasMany<MailAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(MailAttachment::class, 'message_id');
    }
}
