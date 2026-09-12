<?php

declare(strict_types=1);

namespace App\Modules\Cases\Services;

use App\Modules\Cases\Enums\CaseStatus;
use App\Modules\Cases\Models\Absence;
use App\Modules\Cases\Models\CaseItem;
use App\Modules\Cases\Models\MailCase;
use App\Modules\Mail\Models\Team;
use App\Modules\Mail\Models\TeamMember;
use App\Modules\Security\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;

/**
 * Zuweisung, Übergabe, Vertretung (Abwesenheiten mit Stellvertreter) und Teamlast.
 */
final class DelegationService
{
    public function __construct(
        private readonly CaseStatusLogger $log,
        private readonly Repository $config,
    ) {}

    public function recordAbsence(User $user, CarbonImmutable $from, CarbonImmutable $until, ?User $substitute, ?string $reason, ?User $actor = null): Absence
    {
        if ($until->lessThanOrEqualTo($from)) {
            throw new InvalidArgumentException('Abwesenheit: Ende muss nach dem Beginn liegen.');
        }

        if ($substitute !== null && (int) $substitute->getKey() === (int) $user->getKey()) {
            throw new InvalidArgumentException('Stellvertreter darf nicht die abwesende Person sein.');
        }

        return Absence::query()->create([
            'organization_id' => $user->getAttribute('organization_id'),
            'user_id' => $user->getKey(),
            'substitute_user_id' => $substitute?->getKey(),
            'starts_at' => $from->utc(),
            'ends_at' => $until->utc(),
            'reason' => $reason !== null ? mb_substr($reason, 0, 120) : null,
            'created_by' => $actor?->getKey(),
        ]);
    }

    public function isAbsent(int $userId, ?CarbonImmutable $at = null): bool
    {
        return $this->currentAbsence($userId, $at) instanceof Absence;
    }

    public function currentAbsence(int $userId, ?CarbonImmutable $at = null): ?Absence
    {
        $at ??= CarbonImmutable::now();

        return Absence::query()->allOrganizations()
            ->where('user_id', $userId)
            ->where('starts_at', '<=', $at)
            ->where('ends_at', '>', $at)
            ->orderBy('starts_at')
            ->first();
    }

    /**
     * Tatsächlich zuständige Person: folgt der Vertretungskette bis substitution_max_depth. Ohne Vertreter bleibt
     * die abwesende Person zuständig (Anzeige "abwesend").
     */
    public function effectiveAssigneeId(int $userId, ?CarbonImmutable $at = null): int
    {
        $current = $userId;
        $visited = [$userId];
        $depth = max(1, (int) $this->config->get('hub.cases.substitution_max_depth', 3));

        for ($i = 0; $i < $depth; $i++) {
            $absence = $this->currentAbsence($current, $at);

            if (! $absence instanceof Absence || $absence->substitute_user_id === null || in_array((int) $absence->substitute_user_id, $visited, true)) {
                return $current;
            }

            $current = (int) $absence->substitute_user_id;
            $visited[] = $current;
        }

        return $current;
    }

    /**
     * Zuweisung eines Vorgangs oder Teilanliegens. Ist die Zielperson abwesend, wird die Vertretung eingesetzt.
     */
    public function assign(MailCase|CaseItem $subject, User $assignee, ?User $actor, ?string $reason = null, bool $followSubstitution = true): MailCase|CaseItem
    {
        $case = $subject instanceof MailCase ? $subject : $subject->loadMissing('case')->case;
        $item = $subject instanceof CaseItem ? $subject : null;
        $targetId = $followSubstitution ? $this->effectiveAssigneeId((int) $assignee->getKey()) : (int) $assignee->getKey();
        $previous = $subject->assignee_user_id;

        $subject->forceFill(['assignee_user_id' => $targetId])->save();

        $status = $subject->status_processing instanceof CaseStatus ? $subject->status_processing : CaseStatus::from((string) $subject->status_processing);

        if ($status === CaseStatus::New) {
            $subject->forceFill(['status_processing' => CaseStatus::Open->value])->save();
            $this->log->log($case, 'processing', CaseStatus::New->value, CaseStatus::Open->value, 'Durch Zuweisung geöffnet.', $actor?->getKey(), $item);
        }

        $context = ['from_user_id' => $previous, 'to_user_id' => $targetId];

        if ($targetId !== (int) $assignee->getKey()) {
            $context['substituted_for_user_id'] = (int) $assignee->getKey();
            $reason = ($reason ?? 'Zuweisung').' (Vertretung, Zielperson abwesend)';
        }

        $this->log->log($case, 'assignment', $previous !== null ? (string) $previous : null, (string) $targetId, $reason ?? 'Zuweisung.', $actor?->getKey(), $item, $actor !== null ? 'user' : 'system', $context);

        if ($subject instanceof MailCase) {
            // Teilanliegen ohne Zuständigen oder mit dem bisherigen Zuständigen folgen dem Vorgang.
            $case->items()
                ->where(static function ($q) use ($previous): void {
                    $q->whereNull('assignee_user_id');

                    if ($previous !== null) {
                        $q->orWhere('assignee_user_id', $previous);
                    }
                })
                ->update(['assignee_user_id' => $targetId, 'updated_at' => CarbonImmutable::now()]);
        }

        return $subject->refresh();
    }

    /**
     * Übergabe durch die bisher zuständige Person an eine andere.
     */
    public function handover(MailCase|CaseItem $subject, User $from, User $to, string $reason): MailCase|CaseItem
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Übergabe braucht eine Begründung.');
        }

        return $this->assign($subject, $to, $from, 'Übergabe: '.$reason);
    }

    /**
     * Teamlast: offene Vorgänge je Mitglied plus Abwesenheitsstatus.
     *
     * @return array<int, array{user_id: int, team_role: string, open_cases: int, open_items: int, absent: bool, substitute_user_id: ?int}>
     */
    public function teamLoad(Team $team, ?CarbonImmutable $at = null): array
    {
        $at ??= CarbonImmutable::now();
        $openStatuses = array_map(static fn (CaseStatus $s): string => $s->value, array_filter(CaseStatus::cases(), static fn (CaseStatus $s): bool => $s->isOpen()));
        $rows = [];

        foreach (TeamMember::query()->where('team_id', $team->getKey())->get() as $member) {
            $userId = (int) $member->user_id;
            $absence = $this->currentAbsence($userId, $at);

            $rows[] = [
                'user_id' => $userId,
                'team_role' => (string) $member->team_role,
                'open_cases' => MailCase::query()->allOrganizations()->where('team_id', $team->getKey())->where('assignee_user_id', $userId)->whereIn('status_processing', $openStatuses)->count(),
                'open_items' => CaseItem::query()->where('assignee_user_id', $userId)->whereIn('status_processing', $openStatuses)->whereHas('case', static fn ($q) => $q->allOrganizations()->where('team_id', $team->getKey()))->count(),
                'absent' => $absence instanceof Absence,
                'substitute_user_id' => $absence?->substitute_user_id !== null ? (int) $absence->substitute_user_id : null,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => [$a['absent'], $a['open_cases'] + $a['open_items']] <=> [$b['absent'], $b['open_cases'] + $b['open_items']]);

        return $rows;
    }

    /**
     * Mitglied mit geringster Last (nicht abwesend) für automatische Verteilung.
     */
    public function leastLoadedMemberId(Team $team, ?CarbonImmutable $at = null): ?int
    {
        foreach ($this->teamLoad($team, $at) as $row) {
            if (! $row['absent'] && in_array($row['team_role'], ['agent', 'lead', 'admin'], true)) {
                return $row['user_id'];
            }
        }

        return null;
    }
}
