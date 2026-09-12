<?php

declare(strict_types=1);

namespace App\Modules\Connector\Models;

use App\Core\Support\HashedIdentifier;
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

    protected static function booted(): void
    {
        // base_url_hash ist HMAC-SHA256 mit Pepper (02-data-model.md, 08-security.md 2.2) und wird immer aus der
        // aktuellen base_url abgeleitet; explizit gesetzte Werte (Factory, Import) werden überschrieben.
        static::saving(function (self $connection): void {
            if (! $connection->isDirty('base_url') && $connection->getAttribute('base_url_hash') !== null) {
                return;
            }

            $baseUrl = $connection->getAttribute('base_url');
            $connection->setAttribute('base_url_hash', is_string($baseUrl) ? app(HashedIdentifier::class)->baseUrl($baseUrl) : null);
        });
    }

    public function isReadPurpose(): bool
    {
        return $this->getAttribute('purpose') === 'read';
    }

    public function isWritePurpose(): bool
    {
        return $this->getAttribute('purpose') === 'write';
    }

    /** @var array<int, string> Felder, deren Änderung die Schreibfreigabe aufhebt (Freigabe gilt nur für den freigegebenen Zustand). */
    public const array WRITE_APPROVAL_SCOPE_FIELDS = ['purpose', 'connector_type', 'base_url', 'allowed_write_prefix'];

    /**
     * Vier-Augen-Prinzip (05-write-capabilities.md 2.2 Nr. 2, 08-security.md Abschnitt 4): write_enabled genügt nicht.
     * Liefert den Grund, warum die Freigabe unvollständig ist, sonst null.
     */
    public function writeApprovalIncompleteReason(): ?string
    {
        if (! (bool) $this->getAttribute('write_enabled')) {
            return 'connection_write_not_enabled';
        }

        $enabledBy = $this->getAttribute('write_enabled_by');
        $confirmedBy = $this->getAttribute('write_confirmed_by');

        if ($enabledBy === null || $confirmedBy === null || $this->getAttribute('write_approval_document_id') === null) {
            return 'write_approval_incomplete';
        }

        if ((int) $enabledBy === (int) $confirmedBy) {
            return 'write_approval_same_person';
        }

        $requester = User::query()->allOrganizations()->find((int) $enabledBy);
        $confirmer = User::query()->allOrganizations()->find((int) $confirmedBy);

        if (! $requester instanceof User || ! $confirmer instanceof User) {
            return 'write_approval_incomplete';
        }

        if (! $requester->role->canRequestWriteEnable() || ! $confirmer->role->canConfirmWriteEnable()) {
            return 'write_approval_roles_invalid';
        }

        return null;
    }

    public function hasCompleteWriteApproval(): bool
    {
        return $this->writeApprovalIncompleteReason() === null;
    }

    /**
     * Hebt die Schreibfreigabe auf (z. B. nach Änderung von Zweck, Typ, URL oder Schreibpräfix).
     */
    public function resetWriteApproval(): void
    {
        $this->forceFill([
            'write_enabled' => false,
            'write_enabled_by' => null,
            'write_confirmed_by' => null,
            'write_approval_document_id' => null,
            'write_enabled_at' => null,
        ]);
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
