<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Models;

use App\Core\Traits\BelongsToOrganization;
use App\Core\Traits\HasExternalIdentity;
use App\Modules\Documents\Models\Document;
use Database\Factories\ContactFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[UseFactory(ContactFactory::class)]
class Contact extends Model
{
    use BelongsToOrganization, HasExternalIdentity, HasFactory;

    protected $table = 'contacts';

    /** @var array<int, string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'birth_date' => 'immutable_date',
            'emails' => 'array',
            'phones' => 'array',
            'addresses' => 'array',
            'categories' => 'array',
            'vcard_extra' => 'array',
            'personal_data_erased_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'merged_into_id');
    }

    /**
     * @return HasMany<ContactIdentifier, $this>
     */
    public function identifiers(): HasMany
    {
        return $this->hasMany(ContactIdentifier::class, 'contact_id');
    }

    /**
     * @return HasMany<ContactRole, $this>
     */
    public function roles(): HasMany
    {
        return $this->hasMany(ContactRole::class, 'contact_id');
    }

    /**
     * @return HasMany<Document, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'contact_id');
    }
}
