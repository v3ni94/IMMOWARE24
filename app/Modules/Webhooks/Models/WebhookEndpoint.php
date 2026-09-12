<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Models;

use App\Core\Traits\BelongsToOrganization;
use Database\Factories\WebhookEndpointFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Registrierter Webhook-Empfänger. secret und previous_secret liegen verschlüsselt ab und werden nie ausgegeben.
 */
#[UseFactory(WebhookEndpointFactory::class)]
class WebhookEndpoint extends Model
{
    use BelongsToOrganization, HasFactory;

    protected $table = 'webhook_endpoints';

    /** @var array<int, string> */
    protected $guarded = ['id'];

    /** @var array<int, string> */
    protected $hidden = ['secret', 'previous_secret'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'previous_secret' => 'encrypted',
            'events' => 'array',
            'active' => 'boolean',
            'secret_rotated_at' => 'immutable_datetime',
        ];
    }

    public function subscribesTo(string $event): bool
    {
        $events = array_map('strval', (array) $this->getAttribute('events'));

        return in_array('*', $events, true) || in_array($event, $events, true);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /**
     * @return HasMany<WebhookDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class, 'endpoint_id');
    }
}
