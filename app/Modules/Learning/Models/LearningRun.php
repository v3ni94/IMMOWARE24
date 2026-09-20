<?php

declare(strict_types=1);

namespace App\Modules\Learning\Models;

use App\Core\Traits\BelongsToOrganization;
use App\Modules\Ai\Models\AiSuggestion;
use App\Modules\Connector\Models\ImmowareConnection;
use App\Modules\Learning\Enums\LearningKind;
use App\Modules\Learning\Enums\LearningRunStatus;
use App\Modules\Security\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ein Lauf der Lernphase Immoware24 (learning_runs). Kein Bezug zu einem Vorgang oder einer Nachricht: die
 * Lernphase erkundet die Struktur der Immoware24-Anbindung, nicht einzelne Vorgänge der Mail-Bearbeitung.
 */
class LearningRun extends Model
{
    use BelongsToOrganization;

    protected $table = 'learning_runs';

    /** @var array<int, string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'facts_json' => 'array',
            'diff_json' => 'array',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    public function kind(): LearningKind
    {
        return LearningKind::from((string) $this->getAttribute('kind'));
    }

    public function status(): LearningRunStatus
    {
        return LearningRunStatus::from((string) $this->getAttribute('status'));
    }

    /**
     * @return BelongsTo<ImmowareConnection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(ImmowareConnection::class, 'connection_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    /**
     * @return BelongsTo<AiSuggestion, $this>
     */
    public function aiSuggestion(): BelongsTo
    {
        return $this->belongsTo(AiSuggestion::class, 'ai_suggestion_id');
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function previousRun(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_run_id');
    }

    /**
     * Ob der Vergleich mit dem vorigen Lauf Veränderungen ergeben hat. Ohne vorigen Lauf gilt der erste Lauf
     * selbst als Veränderung (nichts zum Vergleichen vorhanden).
     */
    public function showsDifferences(): bool
    {
        $diff = $this->getAttribute('diff_json');

        return ! is_array($diff) || (bool) ($diff['changed'] ?? true);
    }
}
