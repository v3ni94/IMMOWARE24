<?php

declare(strict_types=1);

namespace App\Modules\Drive\Models;

use App\Core\Traits\BelongsToOrganization;
use App\Modules\Security\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Google-Drive-Verbindung (nur lesend), OAuth-Tokens verschlüsselt.
 *
 * Tabelle mail_drive_connections (docs/mail/02-datenmodell.md). Massenzuweisung offen ($guarded leer): Eingaben werden in
 * FormRequests und Services validiert, Models erhalten nur geprüfte Werte.
 */
class DriveConnection extends Model
{
    use BelongsToOrganization, SoftDeletes;

    protected $table = 'mail_drive_connections';

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
            'last_success_at' => 'immutable_datetime',
            'last_error_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function oauthGrantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'oauth_granted_by');
    }
}
