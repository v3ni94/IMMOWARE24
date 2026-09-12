<?php

declare(strict_types=1);

namespace App\Modules\Actions\Models;

use App\Modules\Actions\Enums\VerificationStatus;
use App\Modules\Cases\Models\Task;
use App\Modules\Security\Models\User;
use App\Modules\Sync\Models\ProposedChange;
use App\Modules\Sync\Models\WriteOperation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ausführung eines Planschritts, idempotent über idempotency_key. HTTP 2xx ist http_ok_unverified.
 *
 * Tabelle mail_executions (docs/mail/02-datenmodell.md). Massenzuweisung offen ($guarded leer): Eingaben werden in
 * FormRequests und Services validiert, Models erhalten nur geprüfte Werte.
 */
class Execution extends Model
{
    protected $table = 'mail_executions';

    /** @var array<int, string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'step_index' => 'integer',
            'request_summary_json' => 'array',
            'response_status' => 'integer',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'attempts' => 'integer',
            'approved_by_json' => 'array',
            'result_masked_json' => 'array',
            'verification_status' => VerificationStatus::class,
        ];
    }

    /**
     * @return BelongsTo<ActionPlanVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(ActionPlanVersion::class, 'action_plan_version_id');
    }

    /**
     * @return BelongsTo<WriteOperation, $this>
     */
    public function writeOperation(): BelongsTo
    {
        return $this->belongsTo(WriteOperation::class, 'write_operation_id');
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'task_id');
    }

    /**
     * @return BelongsTo<ProposedChange, $this>
     */
    public function proposedChange(): BelongsTo
    {
        return $this->belongsTo(ProposedChange::class, 'proposed_change_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function executor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executed_by');
    }

    /**
     * @return HasMany<Verification, $this>
     */
    public function verifications(): HasMany
    {
        return $this->hasMany(Verification::class, 'execution_id');
    }
}
