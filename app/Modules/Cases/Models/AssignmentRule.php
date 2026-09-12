<?php

declare(strict_types=1);

namespace App\Modules\Cases\Models;

use App\Core\Traits\BelongsToOrganization;
use App\Modules\Security\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Regelbasierte Zuordnungsregel (bestätigter Absender zu Kontakt), entsteht aus manuellen Bestätigungen.
 * Kein Modelltraining; jede Regel ist einsehbar, korrigierbar und deaktivierbar.
 *
 * Tabelle mail_assignment_rules. Massenzuweisung offen ($guarded leer): nur AssignmentService schreibt.
 */
class AssignmentRule extends Model
{
    use BelongsToOrganization;

    protected $table = 'mail_assignment_rules';

    /** @var array<int, string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'target_local_id' => 'integer',
            'confidence' => 'integer',
            'confirmed_count' => 'integer',
            'active' => 'boolean',
            'last_confirmed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
