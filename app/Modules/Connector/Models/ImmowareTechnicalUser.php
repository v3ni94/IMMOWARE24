<?php

declare(strict_types=1);

namespace App\Modules\Connector\Models;

use App\Core\Traits\BelongsToOrganization;
use Database\Factories\ImmowareTechnicalUserFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[UseFactory(ImmowareTechnicalUserFactory::class)]
class ImmowareTechnicalUser extends Model
{
    use BelongsToOrganization, HasFactory;

    protected $table = 'immoware_technical_users';

    /** @var array<int, string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'secret_rotated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<ImmowareConnection, $this>
     */
    public function connections(): HasMany
    {
        return $this->hasMany(ImmowareConnection::class, 'technical_user_id');
    }
}
