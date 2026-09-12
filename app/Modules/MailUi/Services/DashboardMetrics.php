<?php

declare(strict_types=1);

namespace App\Modules\MailUi\Services;

use App\Modules\Actions\Enums\ActionStatus;
use App\Modules\Actions\Models\ActionPlan;
use App\Modules\Cases\Enums\CaseStatus;
use App\Modules\Cases\Enums\CommunicationStatus;
use App\Modules\Cases\Enums\Priority;
use App\Modules\Cases\Models\MailCase;
use App\Modules\Mail\Models\Team;
use App\Modules\Security\Models\User;
use App\Modules\Sla\Models\EmergencyAlert;
use App\Modules\Sla\Models\SlaClock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Kennzahlen des Dashboards aus den Modellen: Notfälle, unzugeordnete Vorgänge, überfällige Rückmeldungen, offene
 * Freigaben, Teamlast. Nur sichtbare Vorgänge (CaseVisibility). Zählungen per Query, kein Model::all().
 */
final class DashboardMetrics
{
    private const array OPEN = ['new', 'assignment_open', 'open', 'in_progress', 'waiting_customer', 'waiting_external', 'waiting_approval', 'blocked', 'reopened'];

    public function __construct(private readonly CaseVisibility $visibility) {}

    /**
     * @return Builder<MailCase>
     */
    public function visibleOpen(User $user): Builder
    {
        return $this->visibility->scopeVisible(MailCase::query(), $user)
            ->where(static fn (Builder $q) => $q->whereIn('status_processing', self::OPEN));
    }

    /**
     * @return Collection<int, MailCase>
     */
    public function emergencies(User $user): Collection
    {
        $query = $this->visibleOpen($user)->where('priority', Priority::P0->value);
        $query->orderBy('opened_at')->limit(20);

        return $query->get();
    }

    /**
     * @return Collection<int, EmergencyAlert>
     */
    public function openAlerts(User $user): Collection
    {
        $caseIds = $this->visibleOpen($user)->select('id');
        $query = EmergencyAlert::query()->where(static fn (Builder $q) => $q->whereIn('case_id', $caseIds)->whereIn('status', ['open', 'escalated']));
        $query->orderBy('acknowledge_due_at')->limit(20);

        return $query->get();
    }

    /**
     * @return Collection<int, MailCase>
     */
    public function unassigned(User $user): Collection
    {
        $query = $this->visibleOpen($user)->where(static fn (Builder $q) => $q->whereNull('assignee_user_id'));
        $query->orderBy('priority')->orderBy('opened_at')->limit(50);

        return $query->get();
    }

    /**
     * Überfällige Rückmeldungen: Kommunikationsstatus verlangt Handlung und Fälligkeit liegt in der Vergangenheit,
     * oder eine laufende Uhr ist rot.
     *
     * @return Collection<int, MailCase>
     */
    public function overdueReplies(User $user): Collection
    {
        $redClocks = SlaClock::query()->where('color', 'red')->where('state', 'running')->select('case_id');

        $query = $this->visibleOpen($user)
            ->where(static function (Builder $q) use ($redClocks): void {
                $q->where(static fn (Builder $inner) => $inner->whereIn('status_communication', [CommunicationStatus::ReplyNeeded->value, CommunicationStatus::UpdateRequired->value])->where('due_at', '<', now()))
                    ->orWhereIn('id', $redClocks);
            });
        $query->orderBy('due_at')->limit(50);

        return $query->get();
    }

    /**
     * @return Collection<int, ActionPlan>
     */
    public function openApprovals(User $user): Collection
    {
        $query = ActionPlan::query()
            ->with(['case', 'currentVersion'])
            ->where('status', ActionStatus::ApprovalRequired->value)
            ->where(fn (Builder $q) => $q->whereIn('case_id', $this->visibleOpen($user)->select('id')));
        $query->orderBy('created_at')->limit(50);

        return $query->get();
    }

    /**
     * Teamlast: offene Vorgänge je Team und je Verantwortlichem.
     *
     * @return array<int, array{team: string, open: int, unassigned: int, overdue: int, members: array<string, int>}>
     */
    public function teamLoad(User $user): array
    {
        $result = [];
        $teamQuery = Team::query()->where('organization_id', $user->getAttribute('organization_id'));
        $teamQuery->orderBy('name');

        foreach ($teamQuery->get() as $team) {
            $base = $this->visibleOpen($user)->where('team_id', $team->getKey());
            $members = (clone $base)->whereNotNull('assignee_user_id')
                ->selectRaw('assignee_user_id, count(*) as aggregate')
                ->groupBy('assignee_user_id')
                ->pluck('aggregate', 'assignee_user_id');

            $names = $members->isEmpty()
                ? collect()
                : User::query()->where(static fn (Builder $q) => $q->whereIn('id', $members->keys()->all()))->pluck('name', 'id');
            $byMember = [];

            foreach ($members as $userId => $count) {
                $byMember[(string) ($names[$userId] ?? 'Nutzer #'.$userId)] = (int) $count;
            }

            $result[] = [
                'team' => (string) $team->getAttribute('name'),
                'open' => (clone $base)->count(),
                'unassigned' => (clone $base)->whereNull('assignee_user_id')->count(),
                'overdue' => (clone $base)->where('due_at', '<', now())->count(),
                'members' => $byMember,
            ];
        }

        return $result;
    }

    /**
     * Offene Vorgänge ohne Verantwortlichen, nächsten Schritt oder Fälligkeit.
     */
    public function incompleteCount(User $user): int
    {
        return $this->visibleOpen($user)
            ->where(static fn (Builder $q) => $q->whereNotIn('status_processing', [CaseStatus::New->value, CaseStatus::AssignmentOpen->value]))
            ->where(static fn (Builder $q) => $q->whereNull('assignee_user_id')->orWhereNull('next_step')->orWhere('next_step', '')->orWhereNull('due_at'))
            ->count();
    }
}
