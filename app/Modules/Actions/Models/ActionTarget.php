<?php

declare(strict_types=1);

namespace App\Modules\Actions\Models;

use App\Modules\Cases\Models\Task;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Zustand eines Planschritts je Zielsystem (Mehrsystempläne). Teilfehler bleiben offen; nur fehlende Schritte
 * werden wiederholt.
 *
 * Tabelle mail_action_targets. Massenzuweisung offen ($guarded leer): Eingaben werden in Services validiert.
 */
class ActionTarget extends Model
{
    public const string PENDING = 'pending';

    public const string RUNNING = 'running';

    public const string EXECUTED = 'executed';

    public const string VERIFIED = 'verified';

    public const string FAILED = 'failed';

    public const string RESULT_UNCLEAR = 'result_unclear';

    public const string MANUAL_TASK = 'manual_task';

    public const string BLOCKED = 'blocked';

    protected $table = 'mail_action_targets';

    /** @var array<int, string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'step_index' => 'integer',
        ];
    }

    public function isComplete(): bool
    {
        return $this->getAttribute('status') === self::VERIFIED;
    }

    /**
     * Ein Schritt darf nur erneut ausgeführt werden, wenn er nie ein externes Ergebnis erzeugt hat.
     */
    public function isRetryable(): bool
    {
        return in_array($this->getAttribute('status'), [self::PENDING, self::FAILED, self::BLOCKED], true);
    }

    /**
     * @return BelongsTo<ActionPlanVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(ActionPlanVersion::class, 'action_plan_version_id');
    }

    /**
     * @return BelongsTo<Execution, $this>
     */
    public function execution(): BelongsTo
    {
        return $this->belongsTo(Execution::class, 'execution_id');
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'task_id');
    }
}
