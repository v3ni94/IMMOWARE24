<?php

declare(strict_types=1);

namespace App\Modules\Sla\Models;

use App\Core\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Arbeitskalender (Mo bis Fr 08:00 bis 16:30 als Startvorschlag), Zeitzone Europe/Berlin.
 *
 * Tabelle mail_work_calendars (docs/mail/02-datenmodell.md). Massenzuweisung offen ($guarded leer): Eingaben werden in
 * FormRequests und Services validiert, Models erhalten nur geprüfte Werte.
 */
class WorkCalendar extends Model
{
    use BelongsToOrganization;

    protected $table = 'mail_work_calendars';

    /** @var array<int, string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'weekly_hours_json' => 'array',
            'is_default' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Holiday, $this>
     */
    public function holidays(): HasMany
    {
        return $this->hasMany(Holiday::class, 'work_calendar_id');
    }
}
