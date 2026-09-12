<?php

declare(strict_types=1);

namespace App\Modules\Api\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Gespeicherte Antwort eines schreibenden Requests je Idempotency-Key (24 Stunden).
 */
class IdempotencyKey extends Model
{
    protected $table = 'api_idempotency_keys';

    /** @var array<int, string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'response_headers' => 'array',
            'response_status' => 'integer',
            'expires_at' => 'immutable_datetime',
        ];
    }
}
