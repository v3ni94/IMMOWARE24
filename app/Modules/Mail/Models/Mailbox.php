<?php

declare(strict_types=1);

namespace App\Modules\Mail\Models;

use App\Core\Traits\BelongsToOrganization;
use App\Modules\Security\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Postfach (Gmail). OAuth-Tokens verschlüsselt, Status "not_configured" ist sichtbar "Nicht eingerichtet". Absender je Gesellschaft (legal_entity_code) werden nie vermischt.
 *
 * Tabelle mail_mailboxes (docs/mail/02-datenmodell.md). Massenzuweisung offen ($guarded leer): Eingaben werden in
 * FormRequests und Services validiert, Models erhalten nur geprüfte Werte.
 */
class Mailbox extends Model
{
    use BelongsToOrganization, SoftDeletes;

    protected $table = 'mail_mailboxes';

    /** @var array<int, string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'oauth_refresh_token' => 'encrypted',
            'oauth_access_token' => 'encrypted',
            'oauth_token_expires_at' => 'immutable_datetime',
            'oauth_scopes_json' => 'array',
            'oauth_granted_at' => 'immutable_datetime',
            'import_enabled' => 'boolean',
            'import_from' => 'immutable_datetime',
            'last_error_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function oauthGrantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'oauth_granted_by');
    }

    /**
     * @return HasMany<MailboxAlias, $this>
     */
    public function aliases(): HasMany
    {
        return $this->hasMany(MailboxAlias::class, 'mailbox_id');
    }

    /**
     * @return HasMany<MailboxPermission, $this>
     */
    public function permissions(): HasMany
    {
        return $this->hasMany(MailboxPermission::class, 'mailbox_id');
    }
}
