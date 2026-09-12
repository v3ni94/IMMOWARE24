<?php

declare(strict_types=1);

namespace App\Modules\Gmail\Models;

use App\Core\Traits\BelongsToOrganization;
use App\Modules\Documents\Models\Document;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Anhang einer Nachricht. Inhalt lokal verschlüsselt oder noch nicht geladen; Dateitypen-Allowlist über scan_status.
 *
 * Tabelle mail_attachments (docs/mail/02-datenmodell.md). Massenzuweisung offen ($guarded leer): Eingaben werden in
 * FormRequests und Services validiert, Models erhalten nur geprüfte Werte.
 */
class MailAttachment extends Model
{
    use BelongsToOrganization, SoftDeletes;

    protected $table = 'mail_attachments';

    /** @var array<int, string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'fetched_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<MailMessage, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(MailMessage::class, 'message_id');
    }

    /**
     * @return BelongsTo<MailMessagePart, $this>
     */
    public function part(): BelongsTo
    {
        return $this->belongsTo(MailMessagePart::class, 'part_id');
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function immowareDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'immoware_document_id');
    }
}
