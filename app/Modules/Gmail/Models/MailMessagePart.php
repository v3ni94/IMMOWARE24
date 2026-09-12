<?php

declare(strict_types=1);

namespace App\Modules\Gmail\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MIME-Teil einer Nachricht.
 *
 * Tabelle mail_message_parts (docs/mail/02-datenmodell.md). Massenzuweisung offen ($guarded leer): Eingaben werden in
 * FormRequests und Services validiert, Models erhalten nur geprüfte Werte.
 */
class MailMessagePart extends Model
{
    protected $table = 'mail_message_parts';

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
            'headers_json' => 'array',
            'size_bytes' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<MailMessage, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(MailMessage::class, 'message_id');
    }
}
