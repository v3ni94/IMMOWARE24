<?php

declare(strict_types=1);

namespace App\Modules\Sync\Models;

use App\Modules\Connector\Models\ImmowareConnection;
use App\Modules\Security\Models\User;
use App\Modules\Sync\Enums\DlqStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DlqItem extends Model
{
    protected $table = 'dlq_items';

    /** @var array<int, string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload_json' => 'array',
            'failed_at' => 'immutable_datetime',
            'replayed_at' => 'immutable_datetime',
            'status' => DlqStatus::class,
            'attempts' => 'integer',
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
    public function replayedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'replayed_by');
    }
}
