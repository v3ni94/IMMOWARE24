<?php

declare(strict_types=1);

namespace App\Modules\Cases\Models;

use App\Core\Traits\BelongsToOrganization;
use App\Modules\Security\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Abwesenheit mit Stellvertreter (Vertretung bei Zuweisung und Eskalation).
 *
 * Tabelle mail_absences. Massenzuweisung offen ($guarded leer): Eingaben werden in Services validiert.
 */
class Absence extends Model
{
    use BelongsToOrganization;

    protected $table = 'mail_absences';

    /** @var array<int, string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function substitute(): BelongsTo
    {
        return $this->belongsTo(User::class, 'substitute_user_id');
    }
}
