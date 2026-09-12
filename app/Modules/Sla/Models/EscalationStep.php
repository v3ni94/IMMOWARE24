<?php

declare(strict_types=1);

namespace App\Modules\Sla\Models;

use App\Modules\Cases\Models\MailCase;
use App\Modules\Security\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eskalationsstufe zu Uhr oder Notfallalarm mit Ergebnis je Kanal.
 *
 * Tabelle mail_escalation_steps (docs/mail/02-datenmodell.md). Massenzuweisung offen ($guarded leer): Eingaben werden in
 * FormRequests und Services validiert, Models erhalten nur geprüfte Werte.
 */
class EscalationStep extends Model
{
    protected $table = 'mail_escalation_steps';

    /** @var array<int, string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'triggered_at' => 'immutable_datetime',
            'notified_at' => 'immutable_datetime',
            'acknowledged_at' => 'immutable_datetime',
            'channel_results_json' => 'array',
        ];
    }

    /**
     * @return BelongsTo<MailCase, $this>
     */
    public function case(): BelongsTo
    {
        return $this->belongsTo(MailCase::class, 'case_id');
    }

    /**
     * @return BelongsTo<SlaClock, $this>
     */
    public function clock(): BelongsTo
    {
        return $this->belongsTo(SlaClock::class, 'sla_clock_id');
    }

    /**
     * @return BelongsTo<EmergencyAlert, $this>
     */
    public function emergencyAlert(): BelongsTo
    {
        return $this->belongsTo(EmergencyAlert::class, 'emergency_alert_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function escalatedToUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'escalated_to_user_id');
    }
}
