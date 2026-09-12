<?php

declare(strict_types=1);

namespace App\Modules\Sync\Models;

use App\Modules\Connector\Models\ImmowareConnection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncState extends Model
{
    public const string SCOPE_COLLECTION = 'collection';

    public const string SCOPE_RESOURCE = 'resource';

    protected $table = 'sync_states';

    /** @var array<int, string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_modified' => 'immutable_datetime',
            'cursor_json' => 'array',
            'consecutive_missing' => 'integer',
            'last_synced_at' => 'immutable_datetime',
            'last_success_at' => 'immutable_datetime',
            'last_failure_at' => 'immutable_datetime',
            'stale_since' => 'immutable_datetime',
            'size_bytes' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ImmowareConnection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(ImmowareConnection::class, 'connection_id');
    }

    /**
     * Zeilen auf Collection-Ebene (Cursor, Token, letzter Erfolg je Entität).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeCollections(Builder $query): Builder
    {
        return $query->where('scope', self::SCOPE_COLLECTION);
    }

    /**
     * @return BelongsTo<SyncRun, $this>
     */
    public function lastRun(): BelongsTo
    {
        return $this->belongsTo(SyncRun::class, 'last_run_id');
    }

    /**
     * @return BelongsTo<SyncRun, $this>
     */
    public function lastSeenRun(): BelongsTo
    {
        return $this->belongsTo(SyncRun::class, 'last_seen_run_id');
    }
}
