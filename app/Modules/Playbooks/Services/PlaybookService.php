<?php

declare(strict_types=1);

namespace App\Modules\Playbooks\Services;

use App\Modules\Playbooks\Enums\MatchOutcome;
use App\Modules\Playbooks\Enums\PlaybookStatus;
use App\Modules\Playbooks\Models\Playbook;
use App\Modules\Playbooks\Models\PlaybookMatch;
use App\Modules\Security\Models\User;
use InvalidArgumentException;
use LogicException;

/**
 * Verwaltung der Prozessvorlagen: Aktivierung eines Entwurfs (nur eine aktive Vorlage je Kategorie und
 * Organisation, ältere wird automatisch außer Betrieb gesetzt), Außerbetriebnahme und Entscheidung über einen
 * protokollierten Abgleich (mail_playbook_matches), die die Zähler der Vorlage fortschreibt.
 */
final class PlaybookService
{
    public function activate(Playbook $playbook, User $user): Playbook
    {
        if ($playbook->status() === PlaybookStatus::Active) {
            return $playbook;
        }

        Playbook::query()
            ->where('organization_id', $playbook->getAttribute('organization_id'))
            ->where('case_type', $playbook->getAttribute('case_type'))
            ->where('status', PlaybookStatus::Active->value)
            ->where('id', '!=', $playbook->getKey())
            ->update(['status' => PlaybookStatus::Retired->value]);

        $playbook->forceFill([
            'status' => PlaybookStatus::Active->value,
            'activated_by' => $user->getKey(),
            'activated_at' => now()->toImmutable(),
        ])->save();

        return $playbook;
    }

    public function retire(Playbook $playbook): Playbook
    {
        $playbook->forceFill(['status' => PlaybookStatus::Retired->value])->save();

        return $playbook;
    }

    /**
     * @param  array<int, string>  $deviations
     */
    public function decide(PlaybookMatch $match, MatchOutcome $decision, User $user, array $deviations = []): PlaybookMatch
    {
        if (! $decision->isDecided()) {
            throw new InvalidArgumentException('Entscheidung muss accepted, adjusted oder rejected sein.');
        }

        if ($match->outcome()->isDecided()) {
            throw new LogicException('Dieser Abgleich wurde bereits entschieden.');
        }

        $match->forceFill([
            'outcome' => $decision->value,
            'deviations_json' => $deviations === [] ? $match->getAttribute('deviations_json') : $deviations,
            'decided_by' => $user->getKey(),
            'decided_at' => now()->toImmutable(),
        ])->save();

        $playbook = $match->playbook;

        if ($playbook instanceof Playbook) {
            if ($decision === MatchOutcome::Accepted) {
                Playbook::query()->whereKey($playbook->getKey())->increment('times_accepted');
            } elseif ($decision === MatchOutcome::Adjusted) {
                Playbook::query()->whereKey($playbook->getKey())->increment('times_adjusted');
            }
        }

        return $match;
    }
}
