<?php

declare(strict_types=1);

namespace App\Modules\Actions\Models;

use App\Core\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * Outbox-Eintrag, entsteht in derselben Transaktion wie die fachliche Änderung (Muster WebhookDispatcher).
 *
 * Tabelle mail_outbox (docs/mail/02-datenmodell.md). Massenzuweisung offen ($guarded leer): Eingaben werden in
 * FormRequests und Services validiert, Models erhalten nur geprüfte Werte.
 */
class ActionOutbox extends Model
{
    use BelongsToOrganization;

    protected $table = 'mail_outbox';

    public $timestamps = false;

    /** @var array<int, string> */
    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(static function (self $model): void {
            if ($model->getAttribute('created_at') === null) {
                $model->setAttribute('created_at', now());
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'aggregate_id' => 'integer',
            'payload_json' => 'array',
            'dispatched_at' => 'immutable_datetime',
            'attempts' => 'integer',
        ];
    }
}
