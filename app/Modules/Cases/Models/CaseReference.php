<?php

declare(strict_types=1);

namespace App\Modules\Cases\Models;

use App\Modules\Connector\Models\ImmowareConnection;
use App\Modules\Security\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Externe Referenz eines Vorgangs (Kontakt, Objekt, Einheit, Vertrag, Dokument, Lexware-Kontakt, Drive-Datei), nur über IDs.
 *
 * Tabelle mail_case_references (docs/mail/02-datenmodell.md). Massenzuweisung offen ($guarded leer): Eingaben werden in
 * FormRequests und Services validiert, Models erhalten nur geprüfte Werte.
 */
class CaseReference extends Model
{
    protected $table = 'mail_case_references';

    /** @var array<int, string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'local_id' => 'integer',
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
     * @return BelongsTo<ImmowareConnection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(ImmowareConnection::class, 'connection_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
