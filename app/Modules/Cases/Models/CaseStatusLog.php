<?php

declare(strict_types=1);

namespace App\Modules\Cases\Models;

use App\Modules\Security\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Protokoll jedes Zwischenzustands der drei Statusdimensionen sowie von Zuordnung, Sperre, Priorität und Archivierung.
 *
 * Tabelle mail_case_status_log. Massenzuweisung offen ($guarded leer): nur Services schreiben.
 */
class CaseStatusLog extends Model
{
    protected $table = 'mail_case_status_log';

    public $timestamps = false;

    /** @var array<int, string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'context_json' => 'array',
            'changed_at' => 'immutable_datetime',
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
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
