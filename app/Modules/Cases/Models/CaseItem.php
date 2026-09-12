<?php

declare(strict_types=1);

namespace App\Modules\Cases\Models;

use App\Modules\Actions\Enums\ActionStatus;
use App\Modules\Cases\Enums\CaseStatus;
use App\Modules\Cases\Enums\CommunicationStatus;
use App\Modules\Cases\Enums\Priority;
use App\Modules\Gmail\Models\MailMessage;
use App\Modules\Security\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Teilanliegen eines Vorgangs mit eigenen Statusdimensionen.
 *
 * Tabelle mail_case_items (docs/mail/02-datenmodell.md). Massenzuweisung offen ($guarded leer): Eingaben werden in
 * FormRequests und Services validiert, Models erhalten nur geprüfte Werte.
 */
class CaseItem extends Model
{
    use SoftDeletes;

    protected $table = 'mail_case_items';

    /** @var array<int, string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'status_processing' => CaseStatus::class,
            'status_communication' => CommunicationStatus::class,
            'status_business' => ActionStatus::class,
            'priority' => Priority::class,
            'follow_up_at' => 'immutable_datetime',
            'next_customer_update_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
            'due_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_user_id');
    }

    /**
     * @return BelongsTo<MailMessage, $this>
     */
    public function sourceMessage(): BelongsTo
    {
        return $this->belongsTo(MailMessage::class, 'source_message_id');
    }
}
