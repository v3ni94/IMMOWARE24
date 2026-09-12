<?php

declare(strict_types=1);

namespace App\Modules\Sla\Models;

use App\Modules\Security\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Protokoll einer Uhr: Fristquelle (config, rule, manual) und jede Änderung (Start, Pause, Fortsetzung, Neuberechnung,
 * Stopp, Überschreitung). Append-only in der Anwendungslogik.
 *
 * Tabelle mail_sla_clock_log. Massenzuweisung offen ($guarded leer): Eingaben werden in Services validiert.
 */
class SlaClockLog extends Model
{
    protected $table = 'mail_sla_clock_log';

    public $timestamps = false;

    /** @var array<int, string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'old_target_at' => 'immutable_datetime',
            'new_target_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<SlaClock, $this>
     */
    public function clock(): BelongsTo
    {
        return $this->belongsTo(SlaClock::class, 'sla_clock_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
