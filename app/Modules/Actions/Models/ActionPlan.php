<?php

declare(strict_types=1);

namespace App\Modules\Actions\Models;

use App\Core\Traits\BelongsToOrganization;
use App\Modules\Actions\Enums\ActionStatus;
use App\Modules\Actions\Enums\RiskClass;
use App\Modules\Actions\Enums\TargetSystem;
use App\Modules\Cases\Models\CaseItem;
use App\Modules\Cases\Models\MailCase;
use App\Modules\Security\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Aktionsplan eines Vorgangs. Freigabe bindet an steps_hash der aktuellen Version, Ausführung nur nach Vier-Augen-Freigabe, Ergebnis erst nach Verifikation.
 *
 * Tabelle mail_action_plans (docs/mail/02-datenmodell.md). Massenzuweisung offen ($guarded leer): Eingaben werden in
 * FormRequests und Services validiert, Models erhalten nur geprüfte Werte.
 */
class ActionPlan extends Model
{
    use BelongsToOrganization;

    protected $table = 'mail_action_plans';

    /** @var array<int, string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ActionStatus::class,
            'risk_class' => RiskClass::class,
            'target_system' => TargetSystem::class,
            'current_version_id' => 'integer',
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
     * @return BelongsTo<ActionPlanVersion, $this>
     */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(ActionPlanVersion::class, 'current_version_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<ActionPlanVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(ActionPlanVersion::class, 'action_plan_id');
    }
}
