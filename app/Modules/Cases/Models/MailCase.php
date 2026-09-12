<?php

declare(strict_types=1);

namespace App\Modules\Cases\Models;

use App\Core\Traits\BelongsToOrganization;
use App\Modules\Actions\Enums\ActionStatus;
use App\Modules\Cases\Enums\CaseStatus;
use App\Modules\Cases\Enums\CommunicationStatus;
use App\Modules\Cases\Enums\Priority;
use App\Modules\Connector\Models\ImmowareConnection;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Estate\Models\Contract;
use App\Modules\Estate\Models\Property;
use App\Modules\Estate\Models\Unit;
use App\Modules\Mail\Models\Mailbox;
use App\Modules\Mail\Models\Team;
use App\Modules\Security\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Vorgang mit drei Statusdimensionen (Bearbeitung, Kommunikation, Geschäftsergebnis). Offen nur mit Verantwortlichem, nächstem Schritt und Fälligkeit. Zuordnung zu Spiegeldaten nur über IDs.
 *
 * Tabelle mail_cases (docs/mail/02-datenmodell.md). Massenzuweisung offen ($guarded leer): Eingaben werden in
 * FormRequests und Services validiert, Models erhalten nur geprüfte Werte.
 */
class MailCase extends Model
{
    use BelongsToOrganization, SoftDeletes;

    protected $table = 'mail_cases';

    /** @var array<int, string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'priority' => Priority::class,
            'status_processing' => CaseStatus::class,
            'status_communication' => CommunicationStatus::class,
            'status_business' => ActionStatus::class,
            'due_at' => 'immutable_datetime',
            'opened_at' => 'immutable_datetime',
            'first_response_at' => 'immutable_datetime',
            'acknowledged_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'archived_at' => 'immutable_datetime',
            'reopened_at' => 'immutable_datetime',
            'closed_by_exception' => 'boolean',
            'reopen_count' => 'integer',
            'ai_classification_json' => 'array',
            'tags_json' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Mailbox, $this>
     */
    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class, 'mailbox_id');
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team_id');
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
    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function primaryContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'primary_contact_id');
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class, 'property_id');
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }

    /**
     * @return BelongsTo<Contract, $this>
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'contract_id');
    }

    /**
     * @return BelongsTo<ImmowareConnection, $this>
     */
    public function immowareConnection(): BelongsTo
    {
        return $this->belongsTo(ImmowareConnection::class, 'immoware_connection_id');
    }

    /**
     * @return HasMany<CaseItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(CaseItem::class, 'case_id');
    }

    /**
     * @return HasMany<CaseMessage, $this>
     */
    public function caseMessages(): HasMany
    {
        return $this->hasMany(CaseMessage::class, 'case_id');
    }

    /**
     * @return HasMany<CaseReference, $this>
     */
    public function references(): HasMany
    {
        return $this->hasMany(CaseReference::class, 'case_id');
    }

    /**
     * @return HasMany<AssignmentDecision, $this>
     */
    public function assignmentDecisions(): HasMany
    {
        return $this->hasMany(AssignmentDecision::class, 'case_id');
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'case_id');
    }
}
