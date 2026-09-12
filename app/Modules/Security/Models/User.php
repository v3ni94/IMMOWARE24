<?php

declare(strict_types=1);

namespace App\Modules\Security\Models;

use App\Core\Enums\Role;
use App\Core\Traits\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[UseFactory(UserFactory::class)]
class User extends Authenticatable
{
    use BelongsToOrganization, HasFactory, Notifiable;

    protected $table = 'users';

    /** @var array<int, string> */
    protected $fillable = [
        'organization_id', 'name', 'email', 'password', 'role', 'totp_secret', 'totp_confirmed_at',
        'recovery_codes', 'locked_until', 'failed_login_count', 'last_login_at', 'disabled_at',
    ];

    /** @var array<int, string> */
    protected $hidden = ['password', 'remember_token', 'totp_secret', 'recovery_codes'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'immutable_datetime',
            'password' => 'hashed',
            'role' => Role::class,
            'totp_secret' => 'encrypted',
            'totp_confirmed_at' => 'immutable_datetime',
            'recovery_codes' => 'encrypted:array',
            'locked_until' => 'immutable_datetime',
            'failed_login_count' => 'integer',
            'last_login_at' => 'immutable_datetime',
            'disabled_at' => 'immutable_datetime',
        ];
    }

    public function hasRole(Role ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    public function hasConfirmedTotp(): bool
    {
        return $this->totp_confirmed_at !== null;
    }

    public function isLocked(): bool
    {
        $until = $this->locked_until;

        return $until instanceof CarbonImmutable && $until->isFuture();
    }

    public function isDisabled(): bool
    {
        return $this->disabled_at !== null;
    }

    /**
     * @return HasMany<ApiKey, $this>
     */
    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class, 'created_by');
    }
}
