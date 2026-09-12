<?php

declare(strict_types=1);

namespace App\Modules\Gmail\Models;

use App\Core\Traits\BelongsToOrganization;
use App\Modules\Cases\Models\MailCase;
use App\Modules\Mail\Models\Mailbox;
use App\Modules\Mail\Models\MailboxAlias;
use App\Modules\Security\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Entwurf. Status sent_verified erst nach Abgleich mit Label SENT und Message-ID, nie nach HTTP 200.
 *
 * Tabelle mail_drafts (docs/mail/02-datenmodell.md). Massenzuweisung offen ($guarded leer): Eingaben werden in
 * FormRequests und Services validiert, Models erhalten nur geprüfte Werte.
 */
class MailDraft extends Model
{
    use BelongsToOrganization, SoftDeletes;

    protected $table = 'mail_drafts';

    /** @var array<int, string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'to_json' => 'array',
            'cc_json' => 'array',
            'bcc_json' => 'array',
            'attachments_json' => 'array',
            'approved_at' => 'immutable_datetime',
            'approval_reauth_confirmed_at' => 'immutable_datetime',
            'sent_requested_at' => 'immutable_datetime',
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
     * @return BelongsTo<MailboxAlias, $this>
     */
    public function alias(): BelongsTo
    {
        return $this->belongsTo(MailboxAlias::class, 'alias_id');
    }

    /**
     * @return BelongsTo<MailCase, $this>
     */
    public function case(): BelongsTo
    {
        return $this->belongsTo(MailCase::class, 'case_id');
    }

    /**
     * @return BelongsTo<MailMessage, $this>
     */
    public function replyToMessage(): BelongsTo
    {
        return $this->belongsTo(MailMessage::class, 'reply_to_message_id');
    }

    /**
     * @return BelongsTo<MailMessage, $this>
     */
    public function sentMessage(): BelongsTo
    {
        return $this->belongsTo(MailMessage::class, 'sent_message_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function sentRequestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_requested_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<SendReconciliation, $this>
     */
    public function reconciliations(): HasMany
    {
        return $this->hasMany(SendReconciliation::class, 'draft_id');
    }
}
