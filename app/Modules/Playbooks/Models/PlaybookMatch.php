<?php

declare(strict_types=1);

namespace App\Modules\Playbooks\Models;

use App\Core\Traits\BelongsToOrganization;
use App\Modules\Ai\Models\AiRun;
use App\Modules\Cases\Models\MailCase;
use App\Modules\Playbooks\Enums\MatchMethod;
use App\Modules\Playbooks\Enums\MatchOutcome;
use App\Modules\Security\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Protokoll eines Abgleichs eines Vorgangs gegen die Prozessvorlagen (mail_playbook_matches). Grundlage für die
 * Verbesserung der Vorlagen: welche Vorlage wurde vorgeschlagen, wie sicher, wurde sie übernommen, angepasst
 * oder abgelehnt.
 */
class PlaybookMatch extends Model
{
    use BelongsToOrganization;

    protected $table = 'mail_playbook_matches';

    /** @var array<int, string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'deviations_json' => 'array',
            'similarity_score' => 'integer',
            'decided_at' => 'immutable_datetime',
        ];
    }

    public function method(): MatchMethod
    {
        return MatchMethod::from((string) $this->getAttribute('method'));
    }

    public function outcome(): MatchOutcome
    {
        return MatchOutcome::from((string) $this->getAttribute('outcome'));
    }

    /**
     * @return BelongsTo<MailCase, $this>
     */
    public function case(): BelongsTo
    {
        return $this->belongsTo(MailCase::class, 'case_id');
    }

    /**
     * @return BelongsTo<Playbook, $this>
     */
    public function playbook(): BelongsTo
    {
        return $this->belongsTo(Playbook::class, 'playbook_id');
    }

    /**
     * @return BelongsTo<AiRun, $this>
     */
    public function aiRun(): BelongsTo
    {
        return $this->belongsTo(AiRun::class, 'ai_run_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
