<?php

declare(strict_types=1);

namespace App\Modules\MailUi\Http\Controllers;

use App\Modules\Cases\Enums\CaseStatus;
use App\Modules\Cases\Enums\Priority;
use App\Modules\Cases\Models\MailCase;
use App\Modules\Mail\Models\Mailbox;
use App\Modules\Mail\Models\TeamMember;
use App\Modules\MailUi\Contracts\CaseCommandInterface;
use App\Modules\MailUi\Http\Requests\BulkActionRequest;
use App\Modules\MailUi\Support\CaseTypes;
use App\Modules\Security\Models\User;
use App\Modules\Sla\Models\SlaClock;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Team-Inbox: Tabelle aller sichtbaren Vorgänge mit Filter, Sortierung und Sammelaktionen. Sammelaktionen sind
 * bewusst auf Zuordnung, Kategorie und interne Aufgabe beschränkt: keine Massenfreigabe, kein Massenversand.
 */
final class InboxController extends MailUiController
{
    private const array SORTABLE = ['due_at', 'opened_at', 'priority', 'status_processing', 'title', 'case_type'];

    public function __construct(private readonly CaseCommandInterface $commands) {}

    public function index(Request $request): View
    {
        $user = $this->currentUser($request);
        $filter = [
            'mailbox' => (int) $request->query('mailbox', '0'),
            'status' => (string) $request->query('status', 'open'),
            'priority' => (string) $request->query('priority', ''),
            'type' => (string) $request->query('type', ''),
            'assignee' => (string) $request->query('assignee', ''),
            'q' => trim((string) $request->query('q', '')),
            'sort' => in_array((string) $request->query('sort'), self::SORTABLE, true) ? (string) $request->query('sort') : 'due_at',
            'dir' => (string) $request->query('dir') === 'desc' ? 'desc' : 'asc',
        ];

        $query = $this->visibility()->scopeVisible(MailCase::query(), $user)
            ->with(['mailbox', 'assignee', 'property', 'unit', 'primaryContact'])
            ->when($filter['mailbox'] > 0, static fn (Builder $q) => $q->where('mailbox_id', $filter['mailbox']))
            ->when($filter['status'] === 'open', static fn (Builder $q) => $q->whereNotIn('status_processing', [CaseStatus::Resolved->value, CaseStatus::Closed->value]))
            ->when($filter['status'] !== 'open' && $filter['status'] !== '' && CaseStatus::tryFrom($filter['status']) !== null, static fn (Builder $q) => $q->where('status_processing', $filter['status']))
            ->when(Priority::tryFrom($filter['priority']) !== null, static fn (Builder $q) => $q->where('priority', $filter['priority']))
            ->when($filter['type'] !== '' && CaseTypes::isValid($filter['type']), static fn (Builder $q) => $q->where('case_type', $filter['type']))
            ->when($filter['assignee'] === 'none', static fn (Builder $q) => $q->whereNull('assignee_user_id'))
            ->when($filter['assignee'] === 'me', static fn (Builder $q) => $q->where('assignee_user_id', $user->getKey()))
            ->when($filter['q'] !== '', static function (Builder $q) use ($filter): void {
                $needle = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filter['q']).'%';
                $q->where(static fn (Builder $inner) => $inner->whereRaw("title like ? escape '!'", [$needle])->orWhereRaw("case_number like ? escape '!'", [$needle]));
            })
            ->orderBy($filter['sort'], $filter['dir'])
            ->orderBy('id');

        $cases = $query->paginate($this->perPage())->withQueryString();
        $caseIds = array_map(static fn (MailCase $case): int => (int) $case->getKey(), $cases->items());
        $clockQuery = SlaClock::query()->where(static fn (Builder $q) => $q->whereIn('case_id', $caseIds === [] ? [0] : $caseIds)->whereIn('state', ['running', 'paused'])->whereNull('case_item_id'));
        $clocks = $clockQuery->get()->keyBy('case_id');

        $mailboxIds = $this->visibility()->readableMailboxIds($user);

        return view('mail::inbox.index', [
            'title' => 'Posteingang (Team)',
            'cases' => $cases,
            'clocks' => $clocks,
            'filter' => $filter,
            'mailboxes' => $mailboxIds === [] ? collect() : Mailbox::query()->whereIn('id', $mailboxIds)->orderBy('label')->get(),
            'caseTypes' => CaseTypes::all(),
            'statuses' => CaseStatus::cases(),
            'priorities' => Priority::cases(),
            'assignees' => $this->assignableUsers($user),
            'canAssign' => $this->access()->can($user, 'mail.case.assign'),
            'canTask' => $this->access()->can($user, 'mail.task.manage'),
        ]);
    }

    /**
     * Sammelaktion: assign, category, task. Jeder Vorgang einzeln geprüft (Sichtbarkeit, Zuweisungsrecht).
     */
    public function bulk(BulkActionRequest $request): RedirectResponse
    {
        $user = $this->currentUser($request);
        $data = $request->validated();
        $action = (string) $data['action'];
        $ids = array_map('intval', (array) $data['case_ids']);
        $cases = $this->visibility()->scopeVisible(MailCase::query(), $user)->whereIn('id', $ids)->with('mailbox')->get();
        $done = 0;
        $skipped = 0;

        foreach ($cases as $case) {
            $teamId = $case->team_id === null ? null : (int) $case->team_id;

            if ($action === 'assign') {
                if (! $this->visibility()->canAssign($user, $case)) {
                    $skipped++;

                    continue;
                }

                $assignee = isset($data['assignee_user_id']) && (int) $data['assignee_user_id'] > 0
                    ? User::query()->where('organization_id', $user->getAttribute('organization_id'))->find((int) $data['assignee_user_id'])
                    : null;
                $result = $this->commands->assign($case, $assignee, $user, $data['next_step'] ?? null, $data['due_at'] ?? null);
            } elseif ($action === 'category') {
                if (! $this->access()->can($user, 'mail.case.assign', $teamId)) {
                    $skipped++;

                    continue;
                }

                $result = $this->commands->setCategory($case, (string) $data['case_type'], $user);
            } else {
                if (! $this->access()->can($user, 'mail.task.manage', $teamId)) {
                    $skipped++;

                    continue;
                }

                $result = $this->commands->createInternalTask($case, [
                    'title' => (string) $data['task_title'],
                    'instructions' => null,
                    'assignee_user_id' => isset($data['assignee_user_id']) && (int) $data['assignee_user_id'] > 0 ? (int) $data['assignee_user_id'] : null,
                    'due_at' => $data['due_at'] ?? null,
                ], $user);
            }

            if ($result->isOk()) {
                $done++;
                $this->audit('inbox.bulk_'.$action, $case, [], ['action' => $action]);
            } else {
                $skipped++;
            }
        }

        $message = 'Sammelaktion "'.$action.'": '.$done.' Vorgänge geändert, '.$skipped.' übersprungen (fehlendes Recht oder nicht sichtbar).';

        return redirect()->route('mail.inbox.index')->with($skipped > 0 ? 'warning' : 'status', $message);
    }

    /**
     * @return Collection<int, User>
     */
    private function assignableUsers(User $user): Collection
    {
        $query = User::query()->where('organization_id', $user->getAttribute('organization_id'));
        $query->whereNull('disabled_at')->whereIn('id', TeamMember::query()->select('user_id'))->orderBy('name');

        return $query->get();
    }
}
