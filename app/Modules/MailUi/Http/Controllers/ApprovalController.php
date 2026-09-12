<?php

declare(strict_types=1);

namespace App\Modules\MailUi\Http\Controllers;

use App\Modules\Actions\Enums\ActionStatus;
use App\Modules\Actions\Enums\RiskClass;
use App\Modules\Actions\Models\ActionPlan;
use App\Modules\Actions\Models\IdentityCheck;
use App\Modules\Actions\Services\ApprovalService;
use App\Modules\Actions\Services\ExecutionService;
use App\Modules\Cases\Models\MailCase;
use App\Modules\MailUi\Contracts\ApprovalWorkflowInterface;
use App\Modules\MailUi\DTO\WorkflowResult;
use App\Modules\MailUi\Http\Requests\RejectRequest;
use App\Modules\MailUi\Support\BankDataMasker;
use App\Modules\Security\Http\Middleware\RequireFreshTwoFactor;
use App\Modules\Security\Models\User;
use App\Modules\Security\Services\LoginService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Freigabecenter: offene Aktionspläne mit Zielsystem, Alt/Neu (Bankdaten maskiert), Quelle, Identitätsnachweis, Risiko.
 * Freigabe nur einzeln, mit Re-Authentifizierung (Route in Gruppe 2fa.fresh), Vier-Augen-Hinweis; Ablehnen mit
 * Begründung. Volle IBAN nur mit mail.bank_data.view, Postfachrecht can_view_bank_data und Klick mit Auditeintrag.
 * Eine Sammelfreigabe existiert nicht.
 */
final class ApprovalController extends MailUiController
{
    public function __construct(private readonly ApprovalWorkflowInterface $approvals) {}

    public function index(Request $request): View
    {
        $user = $this->currentUser($request);
        $this->requireApprover($user);

        $plans = ActionPlan::query()
            ->whereIn('case_id', $this->visibility()->scopeVisible(MailCase::query(), $user)->select('id'))
            ->where('status', ActionStatus::ApprovalRequired->value)
            ->with(['case.mailbox', 'currentVersion.author', 'currentVersion.approvals', 'currentVersion.identityChecks'])
            ->orderBy('created_at')
            ->paginate($this->perPage())
            ->withQueryString();

        $rows = [];

        foreach ($plans->items() as $plan) {
            $rows[] = $this->planRow($plan, $user, false);
        }

        return view('mail::approvals.index', [
            'title' => 'Freigabecenter',
            'plans' => $plans,
            'rows' => $rows,
        ]);
    }

    public function show(Request $request, ActionPlan $plan): View
    {
        $user = $this->currentUser($request);
        $this->requireApprover($user);
        $case = $this->caseOf($plan);
        $this->requireCaseVisible($user, $case);
        $plan->loadMissing(['currentVersion.author', 'currentVersion.approvals.approver', 'currentVersion.identityChecks.checker', 'currentVersion.targets']);

        $revealed = in_array((int) $plan->getKey(), array_map('intval', (array) $request->session()->get('mail.bank_revealed', [])), true);

        return view('mail::approvals.show', [
            'title' => 'Freigabe Aktionsplan #'.$plan->getKey(),
            'row' => $this->planRow($plan, $user, $revealed),
            'case' => $case,
            'canApprove' => $this->access()->canApprove($user, $plan),
            'isAuthor' => (int) ($plan->currentVersion?->getAttribute('author_user_id') ?? 0) === (int) $user->getKey(),
            'fourEyes' => $this->access()->fourEyesSatisfied($plan),
        ]);
    }

    public function approve(Request $request, ActionPlan $plan): RedirectResponse
    {
        $user = $this->currentUser($request);
        $case = $this->caseOf($plan);
        $this->requireCaseVisible($user, $case);

        if (! $this->access()->canApprove($user, $plan)) {
            if ((int) ($plan->currentVersion?->getAttribute('author_user_id') ?? 0) === (int) $user->getKey()) {
                $this->audit('approval.rejected_self', $plan, [], ['version_id' => $plan->currentVersion?->getKey()]);
            }

            abort(403, 'Keine Freigabeberechtigung für diesen Plan (Recht, Team oder Autorenregel).');
        }

        // Reauth-Nachweis kommt ausschließlich aus der Sitzung. Fehlt er, gibt es keinen Ersatzwert: die Freigabe
        // wird abgelehnt, unabhängig davon, ob die Route in der Gruppe 2fa.fresh liegt.
        $reauth = $this->reauthTimestamp($request);

        if ($reauth === null || ! RequireFreshTwoFactor::isFresh($request)) {
            $this->audit('approval.reauth_missing', $plan);
            abort(403, 'Freigabe ohne aktuelle Re-Authentifizierung ist nicht zulässig.');
        }

        $comment = trim((string) $request->input('comment', ''));
        $result = $this->approvals->approve($plan, $user, $comment !== '' ? mb_substr($comment, 0, 500) : null, $reauth);
        $this->audit('approval.approved', $plan, [], ['outcome' => $result->outcome, 'steps_hash' => $plan->currentVersion?->getAttribute('steps_hash')]);

        return $this->redirectWithResult('mail.approvals.show', $result, ['plan' => $plan->getKey()]);
    }

    public function reject(RejectRequest $request, ActionPlan $plan): RedirectResponse
    {
        $user = $this->currentUser($request);
        $case = $this->caseOf($plan);
        $this->requireCaseVisible($user, $case);
        $this->requireApprover($user);
        $this->requireDecider($user, $plan);

        $result = $this->approvals->reject($plan, $user, (string) $request->validated('reason'));
        $this->audit('approval.rejected', $plan, [], ['reason' => $request->validated('reason')]);

        return $this->redirectWithResult('mail.approvals.show', $result, ['plan' => $plan->getKey()]);
    }

    /**
     * Identitätsprüfung dokumentieren (Kanal, prüfende Person, Zeitpunkt). Pflicht vor jeder Freigabe einer
     * Bankänderung; die Dokumentation ersetzt keine Freigabe und löst keine Ausführung aus.
     */
    public function identityCheck(Request $request, ActionPlan $plan, ApprovalService $approvals): RedirectResponse
    {
        $user = $this->currentUser($request);
        $case = $this->caseOf($plan);
        $this->requireCaseVisible($user, $case);
        $this->requireApprover($user);

        $data = $request->validate([
            'channel' => ['required', 'string', 'in:'.implode(',', IdentityCheck::CHANNELS)],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $version = $plan->currentVersion;

        if ($version === null) {
            return $this->redirectWithResult('mail.approvals.show', WorkflowResult::failed('Der Aktionsplan hat keine aktuelle Version.'), ['plan' => $plan->getKey()]);
        }

        $approvals->recordIdentityCheck($version, $user, (string) $data['channel'], null, $data['note'] ?? null);
        $this->audit('approval.identity_checked', $plan, [], ['channel' => $data['channel']]);

        return $this->redirectWithResult('mail.approvals.show', WorkflowResult::ok('Identitätsprüfung dokumentiert ('.$data['channel'].'). Die Freigabe bleibt ein eigener Schritt.'), ['plan' => $plan->getKey()]);
    }

    /**
     * Offene Schritte (pending, failed, blocked) erneut einplanen. Verifizierte Schritte bleiben unberührt, ein Retry
     * erzeugt nie einen zweiten Beleg (idempotency_key).
     */
    public function retry(Request $request, ActionPlan $plan, ExecutionService $executions): RedirectResponse
    {
        $user = $this->currentUser($request);
        $case = $this->caseOf($plan);
        $this->requireCaseVisible($user, $case);
        $this->requireApprover($user);

        $version = $plan->currentVersion;

        if ($version === null) {
            return $this->redirectWithResult('mail.approvals.show', WorkflowResult::failed('Der Aktionsplan hat keine aktuelle Version.'), ['plan' => $plan->getKey()]);
        }

        if (! in_array($plan->status, [ActionStatus::Approved, ActionStatus::Executed, ActionStatus::Failed, ActionStatus::ResultUnclear, ActionStatus::ManualReview], true)) {
            return $this->redirectWithResult('mail.approvals.show', WorkflowResult::failed('Wiederholung nur für freigegebene Pläne mit offenen Schritten (Status '.$plan->status->label().').'), ['plan' => $plan->getKey()]);
        }

        $dispatched = $executions->retryOpenSteps($version, $user);
        $this->audit('approval.retry', $plan, [], ['steps' => $dispatched]);

        $result = $dispatched === []
            ? WorkflowResult::unavailable('Keine offenen Schritte zum Wiederholen.')
            : WorkflowResult::ok('Offene Schritte erneut eingeplant: '.implode(', ', array_map(static fn (int $i): string => (string) ($i + 1), $dispatched)).'. Kein Ergebnis ist damit erreicht.');

        return $this->redirectWithResult('mail.approvals.show', $result, ['plan' => $plan->getKey()]);
    }

    /**
     * Volle IBAN einblenden: Recht plus Postfachrecht, Klick wird auditiert, Anzeige gilt nur für die nächste Seite.
     */
    public function reveal(Request $request, ActionPlan $plan): RedirectResponse
    {
        $user = $this->currentUser($request);
        $case = $this->caseOf($plan);
        $this->requireCaseVisible($user, $case);

        if (! $this->visibility()->canViewBankData($user, $case)) {
            abort(403, 'Kein Recht zur Anzeige vollständiger Bankdaten (mail.bank_data.view und Postfachrecht erforderlich).');
        }

        $this->audit('bank_data.revealed', $plan, [], ['case_id' => $case->getKey()]);
        $request->session()->flash('mail.bank_revealed', [(int) $plan->getKey()]);

        $target = (string) $request->input('return', 'approval');

        return $target === 'case'
            ? redirect()->route('mail.cases.show', ['case' => $case->getKey()])->with('info', 'Bankdaten vollständig angezeigt, der Zugriff wurde protokolliert.')
            : redirect()->route('mail.approvals.show', ['plan' => $plan->getKey()])->with('info', 'Bankdaten vollständig angezeigt, der Zugriff wurde protokolliert.');
    }

    private function requireApprover(User $user): void
    {
        if (! $this->access()->can($user, 'mail.approve.standard') && ! $this->access()->can($user, 'mail.approve.bank')) {
            abort(403, 'Für das Freigabecenter fehlt das Recht mail.approve.standard.');
        }
    }

    /**
     * Ablehnen verlangt dieselbe Entscheidungsbefugnis wie die Freigabe: Recht der Risikoklasse im Team des Vorgangs,
     * gleiche Organisation, nicht der Autor der aktuellen Version. Der Autor zieht seinen Plan nicht selbst zurück.
     */
    private function requireDecider(User $user, ActionPlan $plan): void
    {
        $risk = $plan->risk_class instanceof RiskClass ? $plan->risk_class : RiskClass::tryFrom((string) $plan->getAttribute('risk_class'));
        $teamId = $plan->case?->team_id;

        if ($risk === null
            || (int) $user->getAttribute('organization_id') !== (int) $plan->getAttribute('organization_id')
            || ! $this->access()->can($user, $risk->approvalPermission(), $teamId === null ? null : (int) $teamId)) {
            abort(403, 'Keine Entscheidungsbefugnis für diesen Plan (Recht der Risikoklasse im Team des Vorgangs).');
        }

        if ((int) ($plan->currentVersion?->getAttribute('author_user_id') ?? 0) === (int) $user->getKey()) {
            $this->audit('approval.rejected_self', $plan, [], ['decision' => 'reject']);
            abort(403, 'Der Autor entscheidet nicht über die eigene Planversion (Vier-Augen-Prinzip).');
        }
    }

    private function caseOf(ActionPlan $plan): MailCase
    {
        $plan->loadMissing(['case.mailbox', 'case.team', 'currentVersion.approvals', 'currentVersion.identityChecks', 'currentVersion.author']);
        $case = $plan->case;

        if (! $case instanceof MailCase) {
            abort(404);
        }

        return $case;
    }

    /**
     * Zeitpunkt der letzten Re-Authentifizierung aus der Sitzung; null, wenn kein Marker vorliegt. Kein Rückfall auf
     * now(), sonst wäre approvals.reauth_confirmed_at immer gefüllt und reauth_missing nie auslösbar.
     */
    private function reauthTimestamp(Request $request): ?CarbonImmutable
    {
        foreach ([LoginService::SESSION_REAUTHENTICATED_AT, LoginService::SESSION_TWO_FACTOR_VERIFIED] as $key) {
            $value = $request->session()->get($key);

            if (is_string($value)) {
                try {
                    return CarbonImmutable::parse($value)->utc();
                } catch (\Throwable) {
                    continue;
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function planRow(ActionPlan $plan, User $user, bool $reveal): array
    {
        $version = $plan->currentVersion;
        $old = is_array($version?->getAttribute('old_values')) ? $version->getAttribute('old_values') : [];
        $new = is_array($version?->getAttribute('new_values')) ? $version->getAttribute('new_values') : [];
        $case = $plan->case;
        $canBank = $case instanceof MailCase && $this->visibility()->canViewBankData($user, $case);
        $risk = $plan->risk_class instanceof RiskClass ? $plan->risk_class : RiskClass::Medium;

        return [
            'plan' => $plan,
            'version' => $version,
            'case' => $case,
            'old' => BankDataMasker::maskArray($old, $reveal && $canBank),
            'new' => BankDataMasker::maskArray($new, $reveal && $canBank),
            'sensitive' => BankDataMasker::containsSensitive($old + $new),
            'canBank' => $canBank,
            'revealed' => $reveal && $canBank,
            'risk' => $risk,
            'identityChecks' => $version !== null ? $version->identityChecks : collect(),
            'targets' => $version !== null ? $version->targets()->orderBy('step_index')->get() : collect(),
            'approvals' => $version !== null ? $version->approvals : collect(),
            'requiredApprovals' => max(1, (int) ($version?->getAttribute('required_approvals') ?? 1)),
            'source' => $version?->getAttribute('source_message_id') !== null ? 'Nachricht #'.$version->getAttribute('source_message_id') : ((string) ($version?->getAttribute('generated_by') ?? 'user') === 'ai' ? 'KI-Vorschlag (unbestätigt)' : 'Manuell erfasst'),
        ];
    }
}
