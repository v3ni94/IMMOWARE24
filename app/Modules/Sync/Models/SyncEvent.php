<?php

declare(strict_types=1);

namespace App\Modules\Sync\Models;

use App\Modules\Connector\Models\ImmowareConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncEvent extends Model
{
    protected $table = 'sync_events';

    /** @var array<int, string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<SyncRun, $this>
     */
    public function syncRun(): BelongsTo
    {
        return $this->belongsTo(SyncRun::class, 'sync_run_id');
    }

    /**
     * @return BelongsTo<ImmowareConnection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(ImmowareConnection::class, 'connection_id');
    }

    /**
     * @return BelongsTo<ExternalPayload, $this>
     */
    public function payload(): BelongsTo
    {
        return $this->belongsTo(ExternalPayload::class, 'payload_id');
    }
}
