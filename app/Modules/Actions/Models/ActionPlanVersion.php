<?php

declare(strict_types=1);

namespace App\Modules\Actions\Models;

use App\Modules\Actions\Enums\RiskClass;
use App\Modules\Actions\Enums\TargetSystem;
use App\Modules\Security\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Version eines Aktionsplans mit Schritten (maskiert) und Hash. KI-generierte Versionen bleiben Vorschlag.
 *
 * Tabelle mail_action_plan_versions (docs/mail/02-datenmodell.md). Massenzuweisung offen ($guarded leer): Eingaben werden in
 * FormRequests und Services validiert, Models erhalten nur geprüfte Werte.
 */
class ActionPlanVersion extends Model
{
    protected $table = 'mail_action_plan_versions';

    public $timestamps = false;

    /** @var array<int, string> */
    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(static function (self $model): void {
            if ($model->getAttribute('created_at') === null) {
                $model->setAttribute('created_at', now());
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'version' => 'integer',
            'steps_json' => 'array',
            'target_system' => TargetSystem::class,
            'risk_class' => RiskClass::class,
            'external_ref' => 'array',
            'fields_json' => 'array',
            // Alt/Neu verschlüsselt (Bankdaten, Adressen)
            'old_values' => 'encrypted:array',
            'new_values' => 'encrypted:array',
            'effective_date' => 'immutable_date',
            'preconditions_json' => 'array',
            'required_approvals' => 'integer',
            'requires_identity_check' => 'boolean',
            'superseded_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<ActionPlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(ActionPlan::class, 'action_plan_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    /**
     * @return HasMany<Approval, $this>
     */
    public function approvals(): HasMany
    {
        return $this->hasMany(Approval::class, 'action_plan_version_id');
    }

    /**
     * @return HasMany<Execution, $this>
     */
    public function executions(): HasMany
    {
        return $this->hasMany(Execution::class, 'action_plan_version_id');
    }

    /**
     * @return HasMany<IdentityCheck, $this>
     */
    public function identityChecks(): HasMany
    {
        return $this->hasMany(IdentityCheck::class, 'action_plan_version_id');
    }

    /**
     * @return HasMany<ActionTarget, $this>
     */
    public function targets(): HasMany
    {
        return $this->hasMany(ActionTarget::class, 'action_plan_version_id');
    }

    /**
     * Schritte der Version (steps_json), jeder Schritt mit target_system, action_type, external_ref, fields.
     *
     * @return array<int, array<string, mixed>>
     */
    public function steps(): array
    {
        $steps = $this->getAttribute('steps_json');

        return is_array($steps) ? array_values($steps) : [];
    }

    public function isCurrent(): bool
    {
        return $this->getAttribute('superseded_at') === null
            && (int) $this->plan?->getAttribute('current_version_id') === (int) $this->getKey();
    }
}
