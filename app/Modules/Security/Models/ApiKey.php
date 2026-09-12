<?php

declare(strict_types=1);

namespace App\Modules\Security\Models;

use App\Core\Traits\BelongsToOrganization;
use Database\Factories\ApiKeyFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[UseFactory(ApiKeyFactory::class)]
class ApiKey extends Model
{
    use BelongsToOrganization, HasFactory;

    protected $table = 'api_keys';

    /** @var array<int, string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'allowed_ips' => 'array',
            'expires_at' => 'immutable_datetime',
            'last_used_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    public function isUsable(): bool
    {
        if ($this->getAttribute('revoked_at') !== null) {
            return false;
        }

        $expires = $this->getAttribute('expires_at');

        return $expires === null || $expires->isFuture();
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, (array) $this->getAttribute('scopes'), true);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
