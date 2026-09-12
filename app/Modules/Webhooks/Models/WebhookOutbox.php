<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Outbox-Eintrag eines Ereignisses. Wird in derselben Transaktion wie die fachliche Änderung geschrieben.
 */
class WebhookOutbox extends Model
{
    protected $table = 'webhook_outbox';

    /** @var array<int, string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload_json' => 'array',
            'occurred_at' => 'immutable_datetime',
            'dispatched_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<WebhookDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class, 'outbox_id');
    }
}
