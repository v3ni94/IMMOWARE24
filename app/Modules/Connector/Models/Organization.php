<?php

declare(strict_types=1);

namespace App\Modules\Connector\Models;

use App\Modules\Contacts\Models\Contact;
use App\Modules\Estate\Models\Property;
use App\Modules\Security\Models\User;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[UseFactory(OrganizationFactory::class)]
class Organization extends Model
{
    use HasFactory;

    protected $table = 'organizations';

    /** @var array<int, string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }

    /**
     * @return HasMany<ImmowareTechnicalUser, $this>
     */
    public function technicalUsers(): HasMany
    {
        return $this->hasMany(ImmowareTechnicalUser::class, 'organization_id');
    }

    /**
     * @return HasMany<ImmowareConnection, $this>
     */
    public function connections(): HasMany
    {
        return $this->hasMany(ImmowareConnection::class, 'organization_id');
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'organization_id');
    }

    /**
     * @return HasMany<Property, $this>
     */
    public function properties(): HasMany
    {
        return $this->hasMany(Property::class, 'organization_id');
    }

    /**
     * @return HasMany<Contact, $this>
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class, 'organization_id');
    }
}
