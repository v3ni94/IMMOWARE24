<?php

declare(strict_types=1);

namespace App\Modules\Sla\Models;

use App\Core\Traits\BelongsToOrganization;
use App\Modules\Cases\Models\MailCase;
use App\Modules\Gmail\Models\MailMessage;
use App\Modules\Security\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Notfallalarm (P0). Annahmefrist 10 Minuten Kalenderzeit, Eskalation nach 5 Minuten (Startwerte).
 *
 * Tabelle mail_emergency_alerts (docs/mail/02-datenmodell.md). Massenzuweisung offen ($guarded leer): Eingaben werden in
 * FormRequests und Services validiert, Models erhalten nur geprüfte Werte.
 */
class EmergencyAlert extends Model
{
    use BelongsToOrganization;

    protected $table = 'mail_emergency_alerts';

    /** @var array<int, string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'detected_at' => 'immutable_datetime',
            'acknowledge_due_at' => 'immutable_datetime',
            'acknowledged_at' => 'immutable_datetime',
            'escalated_at' => 'immutable_datetime',
            'escalation_level' => 'integer',
            'next_escalation_at' => 'immutable_datetime',
            'delivery_log_json' => 'array',
            'on_call_configured' => 'boolean',
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
     * @return BelongsTo<MailMessage, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(MailMessage::class, 'message_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }
}
