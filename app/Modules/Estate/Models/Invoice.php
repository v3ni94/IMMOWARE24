<?php

declare(strict_types=1);

namespace App\Modules\Estate\Models;

use App\Core\Traits\BelongsToOrganization;
use App\Core\Traits\HasExternalIdentity;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Documents\Models\Document;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invoice extends Model
{
    use BelongsToOrganization, HasExternalIdentity;

    protected $table = 'invoices';

    /** @var array<int, string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'invoice_date' => 'immutable_date',
            'due_date' => 'immutable_date',
            'gross_cents' => 'integer',
            'net_cents' => 'integer',
            'vat_cents' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class, 'property_id');
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function creditor(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'creditor_contact_id');
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }
}
