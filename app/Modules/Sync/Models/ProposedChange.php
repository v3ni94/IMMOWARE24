<?php

declare(strict_types=1);

namespace App\Modules\Sync\Models;

use App\Modules\Connector\Models\ImmowareConnection;
use App\Modules\Security\Models\User;
use App\Modules\Sync\Enums\ProposedChangeStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Änderungswunsch an Immoware24-Daten. Wird nie automatisch zurückgeschrieben, sondern manuell im
 * Mastersystem umgesetzt und durch den nächsten Sync bestätigt.
 */
class ProposedChange extends Model
{
    protected $table = 'proposed_changes';

    /** @var array<int, string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ProposedChangeStatus::class,
            'transferred_at' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime',
            'rejected_at' => 'immutable_datetime',
        ];
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', ProposedChangeStatus::Open->value);
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
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function transferredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'transferred_by');
    }

    /**
     * @return BelongsTo<SyncRun, $this>
     */
    public function confirmedBySyncRun(): BelongsTo
    {
        return $this->belongsTo(SyncRun::class, 'confirmed_by_sync_run_id');
    }
}
