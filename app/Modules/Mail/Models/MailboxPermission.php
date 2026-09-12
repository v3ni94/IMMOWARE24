<?php

declare(strict_types=1);

namespace App\Modules\Mail\Models;

use App\Modules\Security\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Postfachrecht je Nutzer. Ohne Zeile kein Zugriff auf Inhalte, auch nicht für Administratoren.
 *
 * Tabelle mail_mailbox_permissions (docs/mail/02-datenmodell.md). Massenzuweisung offen ($guarded leer): Eingaben werden in
 * FormRequests und Services validiert, Models erhalten nur geprüfte Werte.
 */
class MailboxPermission extends Model
{
    protected $table = 'mail_mailbox_permissions';

    /** @var array<int, string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'can_read' => 'boolean',
            'can_draft' => 'boolean',
            'can_send' => 'boolean',
            'can_assign' => 'boolean',
            'can_view_bank_data' => 'boolean',
            'granted_at' => 'immutable_datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }
}
