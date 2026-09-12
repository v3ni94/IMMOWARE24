<?php

declare(strict_types=1);

namespace App\Modules\Actions\Models;

use App\Modules\Security\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Freigabe oder Ablehnung einer Planversion durch eine zweite Person (ungleich Autor), gebunden an steps_hash und Re-Authentifizierung.
 *
 * Tabelle mail_approvals (docs/mail/02-datenmodell.md). Massenzuweisung offen ($guarded leer): Eingaben werden in
 * FormRequests und Services validiert, Models erhalten nur geprüfte Werte.
 */
class Approval extends Model
{
    protected $table = 'mail_approvals';

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
            'reauth_confirmed_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<ActionPlanVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(ActionPlanVersion::class, 'action_plan_version_id');
    }

    public function isExpired(): bool
    {
        $expires = $this->getAttribute('expires_at');

        return $expires instanceof \DateTimeInterface && $expires <= now();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_user_id');
    }
}
