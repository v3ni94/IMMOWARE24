<?php

declare(strict_types=1);

namespace App\Modules\Sla\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Feiertag eines Arbeitskalenders (Region NW konfigurierbar).
 *
 * Tabelle mail_holidays (docs/mail/02-datenmodell.md). Massenzuweisung offen ($guarded leer): Eingaben werden in
 * FormRequests und Services validiert, Models erhalten nur geprüfte Werte.
 */
class Holiday extends Model
{
    protected $table = 'mail_holidays';

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
            'holiday_date' => 'immutable_date',
        ];
    }

    /**
     * @return BelongsTo<WorkCalendar, $this>
     */
    public function workCalendar(): BelongsTo
    {
        return $this->belongsTo(WorkCalendar::class, 'work_calendar_id');
    }
}
