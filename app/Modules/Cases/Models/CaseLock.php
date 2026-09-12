<?php

declare(strict_types=1);

namespace App\Modules\Cases\Models;

use App\Modules\Security\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bearbeitungssperre eines Vorgangs (wer bearbeitet gerade). Läuft ohne Heartbeat ab (expires_at).
 *
 * Tabelle mail_case_locks. Massenzuweisung offen ($guarded leer): nur LockService schreibt.
 */
class CaseLock extends Model
{
    protected $table = 'mail_case_locks';

    /** @var array<int, string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'acquired_at' => 'immutable_datetime',
            'heartbeat_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }

    public function isExpired(?CarbonImmutable $now = null): bool
    {
        return $this->expires_at->lessThanOrEqualTo($now ?? CarbonImmutable::now());
    }

    /**
     * @return BelongsTo<MailCase, $this>
     */
    public function case(): BelongsTo
    {
        return $this->belongsTo(MailCase::class, 'case_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
