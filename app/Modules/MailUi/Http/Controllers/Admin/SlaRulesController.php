<?php

declare(strict_types=1);

namespace App\Modules\MailUi\Http\Controllers\Admin;

use App\Modules\Cases\Enums\Priority;
use App\Modules\Mail\Models\Team;
use App\Modules\MailUi\Http\Requests\Admin\SlaRuleRequest;
use App\Modules\MailUi\Support\CaseTypes;
use App\Modules\Sla\Models\SlaRule;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * SLA-Regeln: Zielzeit je Priorität, Vorgangstyp und Uhr (acknowledge, first_response, resolve, task_due),
 * Warnschwelle in Prozent, Eskalation nach Minuten an eine Team-Rolle. P0 läuft in Kalenderzeit.
 */
final class SlaRulesController extends AdminBaseController
{
    public const array CLOCKS = ['acknowledge' => 'Annahme', 'first_response' => 'Erste Rückmeldung', 'resolve' => 'Lösung', 'task_due' => 'Aufgabe fällig'];

    public function index(Request $request): View
    {
        $this->requireAdmin($request);

        return view('mail::admin.sla.index', [
            'title' => 'SLA-Regeln',
            'rules' => SlaRule::query()->with('team')->orderBy('priority')->orderBy('clock_type')->get(),
            'teams' => Team::query()->orderBy('name')->get(),
            'priorities' => Priority::cases(),
            'clocks' => self::CLOCKS,
            'caseTypes' => CaseTypes::all(),
            'roleLabels' => (array) config('hub.mail.team_role_labels', []),
        ]);
    }

    public function store(SlaRuleRequest $request): RedirectResponse
    {
        $user = $this->requireAdmin($request);
        $data = $request->validated();
        $priority = Priority::from((string) $data['priority']);

        $rule = SlaRule::query()->updateOrCreate(
            [
                'organization_id' => $this->organizationId($user),
                'team_id' => (int) ($data['team_id'] ?? 0) > 0 ? (int) $data['team_id'] : null,
                'priority' => $priority->value,
                'case_type' => ($data['case_type'] ?? '') !== '' ? $data['case_type'] : null,
                'clock_type' => (string) $data['clock_type'],
            ],
            [
                'target_minutes' => (int) $data['target_minutes'],
                'uses_calendar' => ! $priority->usesCalendarTime(),
                'warn_percent' => (int) ($data['warn_percent'] ?? 50),
                'escalate_after_minutes' => (int) ($data['escalate_after_minutes'] ?? 0) > 0 ? (int) $data['escalate_after_minutes'] : null,
                'escalate_to_role' => ($data['escalate_to_role'] ?? '') !== '' ? $data['escalate_to_role'] : null,
                'active' => true,
            ],
        );
        $this->audit('admin.sla_rule_saved', $rule, [], $rule->only(['priority', 'clock_type', 'target_minutes', 'warn_percent']));

        return redirect()->route('mail.admin.sla.index')->with('status', 'SLA-Regel gespeichert. Werte gelten als Orientierung und sind mit der Geschäftsführung zu bestätigen.');
    }

    public function toggle(Request $request, SlaRule $rule): RedirectResponse
    {
        $this->requireAdmin($request);
        $before = (bool) $rule->getAttribute('active');
        $rule->forceFill(['active' => ! $before])->save();
        $this->audit('admin.sla_rule_toggled', $rule, ['active' => $before], ['active' => ! $before]);

        return redirect()->route('mail.admin.sla.index')->with('status', $before ? 'SLA-Regel deaktiviert.' : 'SLA-Regel aktiviert.');
    }
}
