<?php

declare(strict_types=1);

namespace App\Modules\Lexware\Models;

use App\Core\Traits\BelongsToOrganization;
use App\Modules\Cases\Models\MailCase;
use App\Modules\Contacts\Models\Contact;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Snapshot eines Lexware-Kontakts (maskiert) für Alt/Neu und Versionsvergleich.
 *
 * Tabelle mail_lexware_contact_snapshots (docs/mail/02-datenmodell.md). Massenzuweisung offen ($guarded leer): Eingaben werden in
 * FormRequests und Services validiert, Models erhalten nur geprüfte Werte.
 */
class LexwareContactSnapshot extends Model
{
    use BelongsToOrganization;

    protected $table = 'mail_lexware_contact_snapshots';

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
            'lexware_version' => 'integer',
            'snapshot_json' => 'array',
            'fetched_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<LexwareConnection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(LexwareConnection::class, 'lexware_connection_id');
    }

    /**
     * @return BelongsTo<MailCase, $this>
     */
    public function case(): BelongsTo
    {
        return $this->belongsTo(MailCase::class, 'case_id');
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }
}
