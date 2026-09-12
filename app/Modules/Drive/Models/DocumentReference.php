<?php

declare(strict_types=1);

namespace App\Modules\Drive\Models;

use App\Core\Traits\BelongsToOrganization;
use App\Modules\Cases\Models\MailCase;
use App\Modules\Documents\Models\Document;
use App\Modules\Gmail\Models\MailAttachment;
use App\Modules\Security\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Dokumentreferenz eines Vorgangs (Drive, Immoware-Dokument, Anhang) mit nachgelesener Existenz.
 *
 * Tabelle mail_document_references (docs/mail/02-datenmodell.md). Massenzuweisung offen ($guarded leer): Eingaben werden in
 * FormRequests und Services validiert, Models erhalten nur geprüfte Werte.
 */
class DocumentReference extends Model
{
    use BelongsToOrganization, SoftDeletes;

    protected $table = 'mail_document_references';

    /** @var array<int, string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'permissions_summary_json' => 'array',
            'linked_at' => 'immutable_datetime',
            'verified_at' => 'immutable_datetime',
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
     * @return BelongsTo<DriveConnection, $this>
     */
    public function driveConnection(): BelongsTo
    {
        return $this->belongsTo(DriveConnection::class, 'drive_connection_id');
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function immowareDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'immoware_document_id');
    }

    /**
     * @return BelongsTo<MailAttachment, $this>
     */
    public function attachment(): BelongsTo
    {
        return $this->belongsTo(MailAttachment::class, 'attachment_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function linkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_by');
    }
}
