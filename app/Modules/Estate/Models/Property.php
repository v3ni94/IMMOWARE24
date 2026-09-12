<?php

declare(strict_types=1);

namespace App\Modules\Estate\Models;

use App\Core\Traits\BelongsToOrganization;
use App\Core\Traits\HasExternalIdentity;
use App\Modules\Connector\Models\ImmowareConnection;
use App\Modules\Documents\Models\Document;
use Database\Factories\PropertyFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[UseFactory(PropertyFactory::class)]
class Property extends Model
{
    use BelongsToOrganization, HasExternalIdentity, HasFactory;

    protected $table = 'properties';

    /** @var array<int, string> */
    protected $guarded = ['id'];

    /**
     * @return BelongsTo<ImmowareConnection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(ImmowareConnection::class, 'connection_id');
    }

    /**
     * @return HasMany<Building, $this>
     */
    public function buildings(): HasMany
    {
        return $this->hasMany(Building::class, 'property_id');
    }

    /**
     * @return HasMany<Unit, $this>
     */
    public function units(): HasMany
    {
        return $this->hasMany(Unit::class, 'property_id');
    }

    /**
     * @return HasMany<Document, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'property_id');
    }

    /**
     * @return HasMany<CaseFile, $this>
     */
    public function cases(): HasMany
    {
        return $this->hasMany(CaseFile::class, 'property_id');
    }
}
