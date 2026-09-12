<?php

declare(strict_types=1);

namespace App\Modules\Sla\Models;

use App\Modules\Cases\Models\CaseItem;
use App\Modules\Cases\Models\MailCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eine der vier Uhren eines Vorgangs (acknowledge, first_response, resolve, task_due) mit Ampel.
 *
 * Tabelle mail_sla_clocks (docs/mail/02-datenmodell.md). Massenzuweisung offen ($guarded leer): Eingaben werden in
 * FormRequests und Services validiert, Models erhalten nur geprüfte Werte.
 */
class SlaClock extends Model
{
    protected $table = 'mail_sla_clocks';

    /** @var array<int, string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'paused_at' => 'immutable_datetime',
            'paused_minutes' => 'integer',
            'target_at' => 'immutable_datetime',
            'warn_at' => 'immutable_datetime',
            'stopped_at' => 'immutable_datetime',
            'last_evaluated_at' => 'immutable_datetime',
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
     * @return BelongsTo<CaseItem, $this>
     */
    public function caseItem(): BelongsTo
    {
        return $this->belongsTo(CaseItem::class, 'case_item_id');
    }

    /**
     * @return BelongsTo<SlaRule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(SlaRule::class, 'sla_rule_id');
    }
}
