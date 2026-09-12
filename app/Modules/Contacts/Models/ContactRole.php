<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Models;

use App\Core\Enums\ContactRoleType;
use App\Core\Traits\BelongsToOrganization;
use App\Core\Traits\HasExternalIdentity;
use App\Modules\Estate\Models\Property;
use App\Modules\Estate\Models\Unit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContactRole extends Model
{
    use BelongsToOrganization, HasExternalIdentity;

    protected $table = 'contact_roles';

    /** @var array<int, string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => ContactRoleType::class,
            'valid_from' => 'immutable_date',
            'valid_to' => 'immutable_date',
        ];
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
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
}
