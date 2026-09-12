<?php

declare(strict_types=1);

namespace App\Modules\Connector\Models;

use App\Core\Traits\BelongsToOrganization;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentFolder;
use App\Modules\Security\Models\User;
use App\Modules\Sync\Models\SyncRun;
use App\Modules\Sync\Models\WriteOperation;
use Database\Factories\ImmowareConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[UseFactory(ImmowareConnectionFactory::class)]
class ImmowareConnection extends Model
{
    use BelongsToOrganization, HasFactory;

    protected $table = 'immoware_connections';

    /** @var array<int, string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'base_url' => 'encrypted',
            'credentials' => 'encrypted:array',
            'probe_result' => 'array',
            'last_probe_at' => 'immutable_datetime',
            'write_enabled' => 'boolean',
            'write_enabled_at' => 'immutable_datetime',
            'max_upload_bytes' => 'integer',
            'verify_with_hash' => 'boolean',
            'poll_interval_seconds' => 'integer',
            'rate_limit_rps' => 'decimal:2',
            'last_health_at' => 'immutable_datetime',
            'last_health_ok' => 'boolean',
            'last_health_result' => 'array',
        ];
    }

    public function isReadPurpose(): bool
    {
        return $this->getAttribute('purpose') === 'read';
    }

    public function isWritePurpose(): bool
    {
        return $this->getAttribute('purpose') === 'write';
    }

    /**
     * @return BelongsTo<ImmowareTechnicalUser, $this>
     */
    public function technicalUser(): BelongsTo
    {
        return $this->belongsTo(ImmowareTechnicalUser::class, 'technical_user_id');
    }

    /**
     * @return BelongsTo<ImmowareConnection, $this>
     */
    public function pairedReadConnection(): BelongsTo
    {
        return $this->belongsTo(ImmowareConnection::class, 'paired_read_connection_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function writeEnabledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'write_enabled_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function writeConfirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'write_confirmed_by');
    }

    /**
     * @return HasMany<Capability, $this>
     */
    public function capabilities(): HasMany
    {
        return $this->hasMany(Capability::class, 'connection_id');
    }

    /**
     * @return HasMany<SyncRun, $this>
     */
    public function syncRuns(): HasMany
    {
        return $this->hasMany(SyncRun::class, 'connection_id');
    }

    /**
     * @return HasMany<Document, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'connection_id');
    }

    /**
     * @return HasMany<DocumentFolder, $this>
     */
    public function documentFolders(): HasMany
    {
        return $this->hasMany(DocumentFolder::class, 'connection_id');
    }

    /**
     * @return HasMany<WriteOperation, $this>
     */
    public function writeOperations(): HasMany
    {
        return $this->hasMany(WriteOperation::class, 'connection_id');
    }
}
