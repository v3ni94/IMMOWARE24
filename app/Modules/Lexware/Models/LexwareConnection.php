<?php

declare(strict_types=1);

namespace App\Modules\Lexware\Models;

use App\Core\Traits\BelongsToOrganization;
use App\Modules\Security\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Lexware-Office-Verbindung je Gesellschaft. API-Key verschlüsselt, nie anzeigbar; status not_configured ist "Nicht eingerichtet".
 *
 * Tabelle mail_lexware_connections (docs/mail/02-datenmodell.md). Massenzuweisung offen ($guarded leer): Eingaben werden in
 * FormRequests und Services validiert, Models erhalten nur geprüfte Werte.
 */
class LexwareConnection extends Model
{
    use BelongsToOrganization, SoftDeletes;

    protected $table = 'mail_lexware_connections';

    /** @var array<int, string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'write_enabled' => 'boolean',
            'last_success_at' => 'immutable_datetime',
            'last_error_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function configuredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'configured_by');
    }

    /**
     * @return HasMany<LexwareContactSnapshot, $this>
     */
    public function snapshots(): HasMany
    {
        return $this->hasMany(LexwareContactSnapshot::class, 'lexware_connection_id');
    }
}
