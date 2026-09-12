<?php

declare(strict_types=1);

namespace App\Modules\Estate\Models;

use App\Core\Traits\BelongsToOrganization;
use App\Core\Traits\HasExternalIdentity;
use App\Modules\Contacts\Models\ContactRole;
use App\Modules\Documents\Models\Document;
use Database\Factories\UnitFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[UseFactory(UnitFactory::class)]
class Unit extends Model
{
    use BelongsToOrganization, HasExternalIdentity, HasFactory;

    protected $table = 'units';

    /** @var array<int, string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'living_area_sqm' => 'decimal:2',
            'co_ownership_share_numerator' => 'decimal:4',
            'co_ownership_share_denominator' => 'decimal:4',
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
     * @return BelongsTo<Building, $this>
     */
    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class, 'building_id');
    }

    /**
     * @return HasMany<Contract, $this>
     */
    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class, 'unit_id');
    }

    /**
     * @return HasMany<Ownership, $this>
     */
    public function ownerships(): HasMany
    {
        return $this->hasMany(Ownership::class, 'unit_id');
    }

    /**
     * @return HasMany<ContactRole, $this>
     */
    public function contactRoles(): HasMany
    {
        return $this->hasMany(ContactRole::class, 'unit_id');
    }

    /**
     * @return HasMany<Document, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'unit_id');
    }
}
