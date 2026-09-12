<?php

declare(strict_types=1);

namespace App\Modules\Sync\Models;

use App\Modules\Connector\Models\ImmowareConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExternalPayload extends Model
{
    protected $table = 'external_payloads';

    /** @var array<int, string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'import_metadata' => 'array',
            'received_at' => 'immutable_datetime',
            'remote_last_modified' => 'immutable_datetime',
            'contains_personal_data' => 'boolean',
            'pseudonymized_at' => 'immutable_datetime',
            'size_bytes' => 'integer',
        ];
    }

    public const int INLINE_LIMIT_BYTES = 65536;

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
}
