<?php

declare(strict_types=1);

namespace App\Modules\Connector\Models;

use Database\Factories\RemoteRequestFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[UseFactory(RemoteRequestFactory::class)]
class RemoteRequest extends Model
{
    use HasFactory;

    protected $table = 'remote_requests';

    /** @var array<int, string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'request_headers_masked' => 'array',
            'response_headers' => 'array',
            'requested_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<ImmowareConnection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(ImmowareConnection::class, 'connection_id');
    }
}
