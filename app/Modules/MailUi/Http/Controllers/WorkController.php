<?php

declare(strict_types=1);

namespace App\Modules\MailUi\Http\Controllers;

use App\Modules\Cases\Enums\CaseStatus;
use App\Modules\Cases\Models\Absence;
use App\Modules\Cases\Models\MailCase;
use App\Modules\Cases\Models\Task;
use App\Modules\Sla\Models\Deadline;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Persönliche Arbeit: meine Aufgaben, heute fällig, Wiedervorlagen (Fristen, Folgetermine), Vertretungen
 * (aktive Abwesenheiten, in denen ich Stellvertretung bin, mit den Vorgängen der vertretenen Person).
 */
final class WorkController extends MailUiController
{
    public function index(Request $request): View
    {
        $user = $this->currentUser($request);
        $tz = (string) config('hub.mail.display_timezone', 'Europe/Berlin');
        $dayStart = CarbonImmutable::now($tz)->startOfDay()->utc();
        $dayEnd = CarbonImmutable::now($tz)->endOfDay()->utc();
        $open = [CaseStatus::Resolved->value, CaseStatus::Closed->value];

        $myCases = $this->visibility()->scopeVisible(MailCase::query(), $user)
            ->where('assignee_user_id', $user->getKey())
            ->whereNotIn('status_processing', $open)
            ->with(['mailbox'])
            ->orderBy('due_at')
            ->limit(200)
            ->get();

        $tasks = Task::query()
            ->where('assignee_user_id', $user->getKey())
            ->whereIn('status', ['open', 'in_progress', 'waiting'])
            ->with('case')
            ->orderBy('due_at')
            ->limit(200)
            ->get();

        $dueToday = $myCases->filter(static fn (MailCase $case): bool => $case->due_at !== null && $case->due_at->between($dayStart, $dayEnd))
            ->merge($myCases->filter(static fn (MailCase $case): bool => $case->due_at !== null && $case->due_at->lessThan($dayStart)));

        $followUps = Deadline::query()
            ->whereIn('case_id', $myCases->pluck('id')->all() === [] ? [0] : $myCases->pluck('id')->all())
            ->where('status', 'open')
            ->orderBy('due_at')
            ->limit(100)
            ->get();

        $substitutions = Absence::query()
            ->where('substitute_user_id', $user->getKey())
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>=', now())
            ->with('user')
            ->get();

        $absentIds = $substitutions->pluck('user_id')->all();
        $substituteCases = $absentIds === [] ? collect() : $this->visibility()->scopeVisible(MailCase::query(), $user)
            ->whereIn('assignee_user_id', $absentIds)
            ->whereNotIn('status_processing', $open)
            ->with(['assignee', 'mailbox'])
            ->orderBy('due_at')
            ->limit(200)
            ->get();

        return view('mail::work.index', [
            'title' => 'Meine Arbeit',
            'myCases' => $myCases,
            'tasks' => $tasks,
            'dueToday' => $dueToday->unique('id')->values(),
            'followUps' => $followUps,
            'substitutions' => $substitutions,
            'substituteCases' => $substituteCases,
        ]);
    }
}
