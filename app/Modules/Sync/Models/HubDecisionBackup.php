<?php

declare(strict_types=1);

namespace App\Modules\Sync\Models;

use App\Modules\Security\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HubDecisionBackup extends Model
{
    protected $table = 'hub_decision_backups';

    /** @var array<int, string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'taken_at' => 'immutable_datetime',
            'tables_included' => 'array',
            'restore_tested_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function restoreTestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'restore_tested_by');
    }
}
