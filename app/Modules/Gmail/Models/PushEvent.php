<?php

declare(strict_types=1);

namespace App\Modules\Gmail\Models;

use App\Modules\Mail\Models\Mailbox;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pub/Sub-Push-Ereignis, Dedup über pubsub_message_id. Retention 30 Tage.
 *
 * Tabelle mail_push_events (docs/mail/02-datenmodell.md). Massenzuweisung offen ($guarded leer): Eingaben werden in
 * FormRequests und Services validiert, Models erhalten nur geprüfte Werte.
 */
class PushEvent extends Model
{
    protected $table = 'mail_push_events';

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
            'publish_time' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Mailbox, $this>
     */
    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class, 'mailbox_id');
    }
}
