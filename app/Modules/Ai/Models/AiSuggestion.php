<?php

declare(strict_types=1);

namespace App\Modules\Ai\Models;

use App\Modules\Cases\Models\MailCase;
use App\Modules\Security\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * KI-Vorschlag, wirkt erst nach menschlicher Entscheidung (accepted).
 *
 * payload_json liegt verschlüsselt in der Datenbank (Cast encrypted:array, Spalte longText seit Migration
 * 2026_09_14_000002). Zeilen aus der Zeit vor der Umstellung wurden geleert (payload_json NULL, Status superseded),
 * weil Klartext-JSON mit dem Cast nicht lesbar wäre; Leser müssen NULL als "kein Inhalt" behandeln.
 *
 * Tabelle mail_ai_suggestions (docs/mail/02-datenmodell.md). Massenzuweisung offen ($guarded leer): Eingaben werden in
 * FormRequests und Services validiert, Models erhalten nur geprüfte Werte.
 */
class AiSuggestion extends Model
{
    protected $table = 'mail_ai_suggestions';

    /** @var array<int, string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload_json' => 'encrypted:array',
            'confidence_percent' => 'integer',
            'decided_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<AiRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(AiRun::class, 'ai_run_id');
    }

    /**
     * @return BelongsTo<MailCase, $this>
     */
    public function case(): BelongsTo
    {
        return $this->belongsTo(MailCase::class, 'case_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
