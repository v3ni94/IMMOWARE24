<?php

declare(strict_types=1);

namespace App\Modules\Ai\Models;

use App\Core\Traits\BelongsToOrganization;
use App\Modules\Cases\Models\MailCase;
use App\Modules\Gmail\Models\MailMessage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * KI-Lauf mit Kosten und Schema-Ergebnis. Keine Prompt-Klartexte mit personenbezogenen Daten, nur Hashes.
 *
 * Tabelle mail_ai_runs (docs/mail/02-datenmodell.md). Massenzuweisung offen ($guarded leer): Eingaben werden in
 * FormRequests und Services validiert, Models erhalten nur geprüfte Werte.
 */
class AiRun extends Model
{
    use BelongsToOrganization;

    protected $table = 'mail_ai_runs';

    /** @var array<int, string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cost_cents' => 'integer',
            'latency_ms' => 'integer',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
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
     * @return HasMany<AiSuggestion, $this>
     */
    public function suggestions(): HasMany
    {
        return $this->hasMany(AiSuggestion::class, 'ai_run_id');
    }
}
