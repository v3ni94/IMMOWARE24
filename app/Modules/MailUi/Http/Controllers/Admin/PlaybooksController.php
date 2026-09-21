<?php

declare(strict_types=1);

namespace App\Modules\MailUi\Http\Controllers\Admin;

use App\Modules\MailUi\Http\Requests\Admin\DecidePlaybookMatchRequest;
use App\Modules\Playbooks\Enums\MatchOutcome;
use App\Modules\Playbooks\Enums\PlaybookStatus;
use App\Modules\Playbooks\Models\Playbook;
use App\Modules\Playbooks\Models\PlaybookMatch;
use App\Modules\Playbooks\Services\PlaybookService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use LogicException;

/**
 * Prozessdatenbank (Modul Playbooks): Prozessvorlagen prüfen, aktivieren, außer Betrieb nehmen, und offene
 * Abgleiche entscheiden. Vorschläge wirken erst nach dieser Entscheidung; die KI-Vorschläge selbst ändern
 * nichts automatisch. Recht mail.admin, wie die übrigen Administrationsbereiche.
 */
final class PlaybooksController extends AdminBaseController
{
    public function __construct(private readonly PlaybookService $playbooks) {}

    public function index(Request $request): View
    {
        $user = $this->requireAdmin($request);

        return view('mail::admin.playbooks.index', [
            'title' => 'Prozessdatenbank',
            'playbooks' => Playbook::query()
                ->where('organization_id', $this->organizationId($user))
                ->orderBy('case_type')
                ->orderByDesc('version')
                ->get(),
            'statuses' => PlaybookStatus::cases(),
        ]);
    }

    public function show(Request $request, Playbook $playbook): View
    {
        $this->requireAdmin($request);
        $playbook->load(['createdFromCase:id,case_number,title', 'previousVersion:id,version', 'createdBy:id,name', 'activatedBy:id,name']);

        return view('mail::admin.playbooks.show', [
            'title' => $playbook->getAttribute('title'),
            'playbook' => $playbook,
            'recentMatches' => PlaybookMatch::query()->where('playbook_id', $playbook->getKey())->latest('id')->limit(20)->get(),
        ]);
    }

    public function activate(Request $request, Playbook $playbook): RedirectResponse
    {
        $user = $this->requireAdmin($request);
        $this->playbooks->activate($playbook, $user);
        $this->audit('admin.playbook_activated', $playbook, [], ['case_type' => $playbook->getAttribute('case_type'), 'version' => $playbook->getAttribute('version')]);

        return redirect()->route('mail.admin.playbooks.show', ['playbook' => $playbook->getKey()])->with('status', 'Prozessvorlage aktiviert.');
    }

    public function retire(Request $request, Playbook $playbook): RedirectResponse
    {
        $this->requireAdmin($request);
        $this->playbooks->retire($playbook);
        $this->audit('admin.playbook_retired', $playbook);

        return redirect()->route('mail.admin.playbooks.show', ['playbook' => $playbook->getKey()])->with('status', 'Prozessvorlage außer Betrieb genommen.');
    }

    public function matches(Request $request): View
    {
        $user = $this->requireAdmin($request);

        return view('mail::admin.playbooks.matches', [
            'title' => 'Offene Abgleiche',
            'matches' => PlaybookMatch::query()
                ->where('organization_id', $this->organizationId($user))
                ->where('outcome', MatchOutcome::Suggested->value)
                ->with(['case:id,case_number,title', 'playbook:id,title,case_type'])
                ->orderByDesc('id')
                ->paginate($this->perPage()),
        ]);
    }

    public function decide(DecidePlaybookMatchRequest $request, PlaybookMatch $match): RedirectResponse
    {
        $user = $this->requireAdmin($request);
        $decision = MatchOutcome::from((string) $request->validated('decision'));
        $deviations = array_values(array_filter(array_map('trim', explode("\n", (string) $request->validated('deviations', '')))));

        try {
            $this->playbooks->decide($match, $decision, $user, $deviations);
        } catch (LogicException $e) {
            return redirect()->route('mail.admin.playbooks.matches')->with('warning', $e->getMessage());
        }

        $this->audit('admin.playbook_match_decided', $match, [], ['decision' => $decision->value]);

        return redirect()->route('mail.admin.playbooks.matches')->with('status', 'Abgleich entschieden: '.$decision->label().'.');
    }
}
