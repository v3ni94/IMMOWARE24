<?php

declare(strict_types=1);

namespace App\Modules\MailUi\Models;

use App\Core\Traits\BelongsToOrganization;
use App\Modules\Estate\Models\Property;
use App\Modules\Mail\Models\Team;
use App\Modules\Security\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Objektzuständigkeit: Objekt (Spiegeldaten, nur über ID) zu Team oder Person. Tabelle mail_property_responsibilities.
 * Massenzuweisung offen ($guarded leer): Eingaben werden in FormRequests validiert.
 */
class PropertyResponsibility extends Model
{
    use BelongsToOrganization;

    protected $table = 'mail_property_responsibilities';

    /** @var array<int, string> */
    protected $guarded = [];

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class, 'property_id');
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
