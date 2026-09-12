<?php

declare(strict_types=1);

namespace App\Modules\Actions\Models;

use App\Modules\Security\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Dokumentierte Identitätsprüfung zu einer Planversion (Pflicht bei Bankänderungen): Kanal, prüfende Person, Zeitpunkt.
 *
 * Tabelle mail_identity_checks. Massenzuweisung offen ($guarded leer): Eingaben werden in Services validiert.
 */
class IdentityCheck extends Model
{
    /** @var array<int, string> */
    public const array CHANNELS = ['phone_callback', 'in_person', 'video', 'letter', 'other'];

    protected $table = 'mail_identity_checks';

    public $timestamps = false;

    /** @var array<int, string> */
    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(static function (self $model): void {
            if ($model->getAttribute('created_at') === null) {
                $model->setAttribute('created_at', now());
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'checked_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<ActionPlanVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(ActionPlanVersion::class, 'action_plan_version_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function checker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_by');
    }
}
