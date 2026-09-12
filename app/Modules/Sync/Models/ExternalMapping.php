<?php

declare(strict_types=1);

namespace App\Modules\Sync\Models;

use App\Core\Traits\BelongsToOrganization;
use App\Modules\Connector\Models\ImmowareConnection;
use App\Modules\Security\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExternalMapping extends Model
{
    use BelongsToOrganization;

    protected $table = 'external_mappings';

    /** @var array<int, string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'confirmed_at' => 'immutable_datetime',
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
    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
