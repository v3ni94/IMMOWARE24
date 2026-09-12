<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Zustellung eines Outbox-Eintrags an einen Endpunkt. Status pending, delivered, failed (Retry ausstehend),
 * dead (DLQ nach dem letzten Versuch), skipped (Endpunkt inaktiv).
 */
class WebhookDelivery extends Model
{
    public const string STATUS_PENDING = 'pending';

    public const string STATUS_DELIVERED = 'delivered';

    public const string STATUS_FAILED = 'failed';

    public const string STATUS_DEAD = 'dead';

    public const string STATUS_SKIPPED = 'skipped';

    protected $table = 'webhook_deliveries';

    /** @var array<int, string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'last_response_code' => 'integer',
            'duration_ms' => 'integer',
            'next_attempt_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'last_attempt_at' => 'immutable_datetime',
            'dead_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<WebhookEndpoint, $this>
     */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'endpoint_id');
    }

    /**
     * @return BelongsTo<WebhookOutbox, $this>
     */
    public function outbox(): BelongsTo
    {
        return $this->belongsTo(WebhookOutbox::class, 'outbox_id');
    }
}
