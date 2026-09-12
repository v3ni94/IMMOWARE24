<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Models;

use App\Core\Traits\BelongsToOrganization;
use App\Core\Traits\HasExternalIdentity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    use BelongsToOrganization, HasExternalIdentity;

    protected $table = 'companies';

    /** @var array<int, string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'addresses' => 'array',
        ];
    }

    /**
     * @return HasMany<Contact, $this>
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class, 'company_id');
    }
}
