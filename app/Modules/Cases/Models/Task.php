<?php

declare(strict_types=1);

namespace App\Modules\Cases\Models;

use App\Core\Traits\BelongsToOrganization;
use App\Modules\Security\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Aufgabe, insbesondere manuelle Änderung in Immoware24 oder Lexware mit Alt/Neu (verschlüsselt). "Manuell bestätigt" = done_manual_confirmed, Zweitbestätigung durch andere Person.
 *
 * Tabelle mail_tasks (docs/mail/02-datenmodell.md). Massenzuweisung offen ($guarded leer): Eingaben werden in
 * FormRequests und Services validiert, Models erhalten nur geprüfte Werte.
 */
class Task extends Model
{
    use BelongsToOrganization, SoftDeletes;

    protected $table = 'mail_tasks';

    /** @var array<int, string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'old_value_json' => 'encrypted:array',
            'new_value_json' => 'encrypted:array',
            'due_at' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
