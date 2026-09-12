<?php

declare(strict_types=1);

namespace App\Modules\Connector\Models;

use App\Core\Enums\CapabilityStatus;
use App\Modules\Security\Models\User;
use Database\Factories\CapabilityFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[UseFactory(CapabilityFactory::class)]
class Capability extends Model
{
    use HasFactory;

    protected $table = 'capabilities';

    /** @var array<int, string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'hard_locked' => 'boolean',
            'tested_at' => 'immutable_datetime',
            'evidence_status' => CapabilityStatus::class,
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
    public function testedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tested_by');
    }
}
