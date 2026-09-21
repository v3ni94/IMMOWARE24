<?php

declare(strict_types=1);

namespace App\Modules\Playbooks\Models;

use App\Core\Traits\BelongsToOrganization;
use App\Modules\Cases\Models\MailCase;
use App\Modules\Playbooks\Enums\PlaybookSource;
use App\Modules\Playbooks\Enums\PlaybookStatus;
use App\Modules\Security\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Prozessvorlage (mail_playbooks): fasst zusammen, wie Vorgänge einer Kategorie (case_type) bearbeitet werden.
 * Nur status=active wird beim Vergleich neuer Vorgänge herangezogen. Kein Bezug zu Immoware24, reine
 * Hub-eigene Wissensbasis über die eigene Vorgangsbearbeitung.
 */
class Playbook extends Model
{
    use BelongsToOrganization, SoftDeletes;

    protected $table = 'mail_playbooks';

    /** @var array<int, string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'match_signature_json' => 'array',
            'steps_json' => 'array',
            'activated_at' => 'immutable_datetime',
        ];
    }

    public function status(): PlaybookStatus
    {
        return PlaybookStatus::from((string) $this->getAttribute('status'));
    }

    public function source(): PlaybookSource
    {
        return PlaybookSource::from((string) $this->getAttribute('source'));
    }

    /**
     * @return array<int, string>
     */
    public function keywords(): array
    {
        $signature = $this->getAttribute('match_signature_json');

        return is_array($signature) ? array_values(array_map('strval', (array) ($signature['keywords'] ?? []))) : [];
    }

    /**
     * @return BelongsTo<MailCase, $this>
     */
    public function createdFromCase(): BelongsTo
    {
        return $this->belongsTo(MailCase::class, 'created_from_case_id');
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function previousVersion(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_version_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function activatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by');
    }

    /**
     * @return HasMany<PlaybookMatch, $this>
     */
    public function matches(): HasMany
    {
        return $this->hasMany(PlaybookMatch::class, 'playbook_id');
    }
}
