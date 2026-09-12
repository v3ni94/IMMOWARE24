<?php

declare(strict_types=1);

namespace App\Modules\Sla\Models;

use App\Core\Traits\BelongsToOrganization;
use App\Modules\Cases\Enums\Priority;
use App\Modules\Mail\Models\Team;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SLA-Regel je Priorität, Vorgangstyp und Uhr. P0 in Kalenderzeit.
 *
 * Tabelle mail_sla_rules (docs/mail/02-datenmodell.md). Massenzuweisung offen ($guarded leer): Eingaben werden in
 * FormRequests und Services validiert, Models erhalten nur geprüfte Werte.
 */
class SlaRule extends Model
{
    use BelongsToOrganization;

    protected $table = 'mail_sla_rules';

    /** @var array<int, string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'priority' => Priority::class,
            'target_minutes' => 'integer',
            'uses_calendar' => 'boolean',
            'warn_percent' => 'integer',
            'escalate_after_minutes' => 'integer',
            'active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team_id');
    }
}
