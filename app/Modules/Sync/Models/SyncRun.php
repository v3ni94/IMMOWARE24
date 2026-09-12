<?php

declare(strict_types=1);

namespace App\Modules\Sync\Models;

use App\Core\Enums\SyncMode;
use App\Core\Enums\SyncStatus;
use App\Modules\Connector\Models\ImmowareConnection;
use App\Modules\Security\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SyncRun extends Model
{
    public const string TYPE_INCREMENTAL = 'incremental';

    public const string TYPE_FULL = 'full_reconcile';

    public const string TYPE_BOOTSTRAP = 'bootstrap_accept';

    public const string TYPE_REPLAY = 'replay';

    public const string PHASE_FETCH = 'fetch';

    public const string PHASE_FINALIZE = 'finalize';

    public const string PHASE_DONE = 'done';

    public const string PHASE_FAILED = 'failed';

    public const string PHASE_ABORTED = 'aborted';

    protected $table = 'sync_runs';

    /** @var array<int, string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mode' => SyncMode::class,
            'status' => SyncStatus::class,
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'health_ok_before' => 'boolean',
            'counters' => 'array',
            'cursor_before' => 'array',
            'cursor_after' => 'array',
            'duration_ms' => 'integer',
            'chunks' => 'integer',
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
     * @return BelongsTo<User, $this>
     */
    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    /**
     * @return HasMany<SyncEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(SyncEvent::class, 'sync_run_id');
    }

    /**
     * @return HasMany<ExternalPayload, $this>
     */
    public function payloads(): HasMany
    {
        return $this->hasMany(ExternalPayload::class, 'sync_run_id');
    }
}
