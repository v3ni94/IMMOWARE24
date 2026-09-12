<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Models;

use App\Modules\Security\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Logischer Merge zweier Kontakte oder Duplikatvorschlag (status proposed).
 * is_active (1 bei aktivem Merge, sonst NULL) ersetzt die im Datenmodell vorgesehene generierte Spalte
 * und erzwingt über den Unique-Index genau einen aktiven Merge je Quellkontakt.
 * Ein Vorschlag setzt niemals contacts.merged_into_id; das geschieht erst bei einem bestätigten Merge.
 */
class ContactMerge extends Model
{
    public const string STATUS_PROPOSED = 'proposed';

    public const string STATUS_MERGED = 'merged';

    public const string STATUS_REJECTED = 'rejected';

    public const string MATCH_EMAIL = 'email';

    public const string MATCH_PHONE = 'phone';

    protected $table = 'contact_merges';

    /** @var array<int, string> */
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::saving(function (ContactMerge $merge): void {
            $active = $merge->getAttribute('status') === self::STATUS_MERGED && $merge->getAttribute('undone_at') === null;
            $merge->setAttribute('is_active', $active ? true : null);
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'merged_at' => 'immutable_datetime',
            'proposed_at' => 'immutable_datetime',
            'undone_at' => 'immutable_datetime',
            'is_active' => 'boolean',
        ];
    }

    public function isActive(): bool
    {
        return $this->getAttribute('status') === self::STATUS_MERGED && $this->getAttribute('undone_at') === null;
    }

    public function isProposed(): bool
    {
        return $this->getAttribute('status') === self::STATUS_PROPOSED;
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeProposed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PROPOSED);
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function sourceContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'source_contact_id');
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function targetContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'target_contact_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function mergedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'merged_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function undoneBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'undone_by');
    }
}
