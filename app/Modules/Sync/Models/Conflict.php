<?php

declare(strict_types=1);

namespace App\Modules\Sync\Models;

use App\Core\Enums\ConflictState;
use App\Modules\Connector\Models\ImmowareConnection;
use App\Modules\Security\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Conflict extends Model
{
    protected $table = 'conflicts';

    /** @var array<int, string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'conflict_state' => ConflictState::class,
            'local_snapshot_json' => 'array',
            'proposed_change_json' => 'array',
            'resolved_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'occurrences' => 'integer',
            'open_key' => 'boolean',
        ];
    }

    /** @var array<int, string> */
    public const array OPEN_STATUSES = ['open', 'in_progress'];

    protected static function booted(): void
    {
        // Höchstens ein offener Konflikt je Datensatz und Typ (Unique auf open_key).
        static::saving(function (self $conflict): void {
            $conflict->setAttribute('open_key', in_array($conflict->getAttribute('status'), self::OPEN_STATUSES, true) ? true : null);
        });
    }

    /**
     * @return BelongsTo<ImmowareConnection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(ImmowareConnection::class, 'connection_id');
    }

    /**
     * @return BelongsTo<SyncRun, $this>
     */
    public function syncRun(): BelongsTo
    {
        return $this->belongsTo(SyncRun::class, 'sync_run_id');
    }

    /**
     * @return BelongsTo<ExternalPayload, $this>
     */
    public function remotePayload(): BelongsTo
    {
        return $this->belongsTo(ExternalPayload::class, 'remote_payload_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
