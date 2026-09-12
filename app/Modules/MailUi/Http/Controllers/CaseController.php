<?php

declare(strict_types=1);

namespace App\Modules\MailUi\Http\Controllers;

use App\Modules\Actions\Exceptions\ActionPolicyException;
use App\Modules\Actions\Models\ActionPlan;
use App\Modules\Actions\Services\ManualTaskService;
use App\Modules\Cases\Enums\CaseStatus;
use App\Modules\Cases\Models\CaseLock;
use App\Modules\Cases\Models\CaseStatusLog;
use App\Modules\Cases\Models\MailCase;
use App\Modules\Cases\Models\Task;
use App\Modules\Drive\Models\DocumentReference;
use App\Modules\Gmail\Models\MailAttachment;
use App\Modules\Gmail\Models\MailDraft;
use App\Modules\Gmail\Models\MailMessage;
use App\Modules\Mail\Models\MailboxAlias;
use App\Modules\Mail\Models\TeamMember;
use App\Modules\Mail\Services\MailFeatureFlags;
use App\Modules\MailUi\Contracts\CandidateResolverInterface;
use App\Modules\MailUi\Contracts\CaseCommandInterface;
use App\Modules\MailUi\DTO\WorkflowResult;
use App\Modules\MailUi\Http\Requests\AssignCaseRequest;
use App\Modules\MailUi\Http\Requests\ConfirmCandidateRequest;
use App\Modules\MailUi\Http\Requests\InternalNoteRequest;
use App\Modules\MailUi\Http\Requests\InternalTaskRequest;
use App\Modules\MailUi\Http\Requests\SetCategoryRequest;
use App\Modules\MailUi\Http\Requests\SetStatusRequest;
use App\Modules\MailUi\Support\BankDataMasker;
use App\Modules\MailUi\Support\CaseTypes;
use App\Modules\Security\Models\User;
use App\Modules\Sla\Models\SlaClock;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Vorgangsdetail dreispaltig: links Originalthread (sanitisiertes HTML aus body_html_sanitized, Anhänge mit Prüfstatus,
 * interne Notizen getrennt), mittig Zusammenfassung, Teilanliegen, Aufgaben, Aktionen mit Alt/Neu, Freigaben,
 * Antworteditor, rechts Person- und Objektkontext mit Kandidaten, Quelldokumente, Historie. "Wird bearbeitet von"
 * aus mail_case_locks. Hauptaktion je Bearbeitungsstand hervorgehoben.
 */
final class CaseController extends MailUiController
{
    public function __construct(
        private readonly CaseCommandInterface $commands,
        private readonly CandidateResolverInterface $candidates,
        private readonly MailFeatureFlags $flags,
    ) {}

    public function show(Request $request, MailCase $case): View
    {
        $user = $this->currentUser($request);
        $this->requireCaseVisible($user, $case);
        $case->loadMissing(['mailbox', 'team', 'assignee', 'primaryContact', 'property', 'unit', 'contract', 'items.assignee', 'tasks.assignee', 'references']);

        $messageIds = $case->caseMessages()->pluck('message_id')->all();
        $messageQuery = MailMessage::query()->where(static fn (Builder $q) => $q->whereIn('id', $messageIds === [] ? [0] : $messageIds));
        $messageQuery->orderBy('received_at');
        $messages = $messageQuery->get();
        $attachmentQuery = MailAttachment::query()->where(static fn (Builder $q) => $q->whereIn('message_id', $messageIds === [] ? [0] : $messageIds));
        $attachments = $attachmentQuery->get()->groupBy('message_id');
        $historyQuery = CaseStatusLog::query()->with('changedBy')->where('case_id', $case->getKey());
        $historyQuery->orderByDesc('changed_at')->limit(200);
        $history = $historyQuery->get();
        $notes = $history->filter(static fn (CaseStatusLog $log): bool => (string) $log->getAttribute('dimension') === 'note');
        $planQuery = ActionPlan::query()->with(['currentVersion.approvals', 'currentVersion.identityChecks', 'currentVersion.targets'])->where('case_id', $case->getKey());
        $planQuery->orderByDesc('created_at');
        $plans = $planQuery->get();
        $draftQuery = MailDraft::query()->where('case_id', $case->getKey());
        $draftQuery->orderByDesc('updated_at');
        $drafts = $draftQuery->get();
        $clocks = SlaClock::query()->where('case_id', $case->getKey())->whereIn('state', ['running', 'paused'])->get();
        $documents = DocumentReference::query()->where('case_id', $case->getKey())->orderBy('name')->get();
        $lock = CaseLock::query()->with('user')->where('case_id', $case->getKey())->where('expires_at', '>', now())->first();

        $canDraft = $this->visibility()->canDraft($user, $case);
        $canSend = $canDraft && $this->flags->gmailSendEnabled() && $this->visibility()->canSend($user, $case);
        $canViewBank = $this->visibility()->canViewBankData($user, $case);
        $revealed = (array) $request->session()->get('mail.bank_revealed', []);

        $planViews = [];

        foreach ($plans as $plan) {
            $version = $plan->currentVersion;
            $reveal = $canViewBank && in_array((int) $plan->getKey(), array_map('intval', $revealed), true);
            $old = is_array($version?->getAttribute('old_values')) ? $version->getAttribute('old_values') : [];
            $new = is_array($version?->getAttribute('new_values')) ? $version->getAttribute('new_values') : [];
            $planViews[] = [
                'plan' => $plan,
                'version' => $version,
                'old' => BankDataMasker::maskArray($old, $reveal),
                'new' => BankDataMasker::maskArray($new, $reveal),
                'sensitive' => BankDataMasker::containsSensitive($old + $new),
                'revealed' => $reveal,
                // Zielzustand je Schritt: Teilerfolge bleiben als solche sichtbar, verified nur nach Nachlesen oder Bestätigung.
                'targets' => $version !== null ? $version->targets->sortBy('step_index')->values() : collect(),
            ];
        }

        $aliases = $case->mailbox_id !== null ? MailboxAlias::query()->where('mailbox_id', $case->mailbox_id)->where('verification_status', 'accepted')->orderByDesc('is_default')->get() : collect();
        $activeDraft = $drafts->first(static fn (MailDraft $draft): bool => in_array((string) $draft->getAttribute('status'), ['local', 'pending_approval'], true));

        return view('mail::cases.show', [
            'title' => 'Vorgang '.$case->getAttribute('case_number'),
            'case' => $case,
            'messages' => $messages,
            'attachments' => $attachments,
            'history' => $history,
            'notes' => $notes,
            'plans' => $planViews,
            'drafts' => $drafts,
            'activeDraft' => $activeDraft,
            'clocks' => $clocks,
            'documents' => $documents,
            'lock' => $lock,
            'lockedByOther' => $lock !== null && (int) $lock->getAttribute('user_id') !== (int) $user->getKey(),
            'candidates' => $this->candidates->candidates($case),
            'aliases' => $aliases,
            'caseTypes' => CaseTypes::all(),
            'assignees' => $this->teamUsers($case, $user),
            'transitions' => array_filter($case->status_processing->allowedTransitions(), static fn (CaseStatus $s): bool => ! in_array($s, [CaseStatus::Resolved, CaseStatus::Closed], true)),
            'can' => [
                'assign' => $this->visibility()->canAssign($user, $case),
                'category' => $this->access()->can($user, 'mail.case.assign', $case->team_id === null ? null : (int) $case->team_id),
                'task' => $this->access()->can($user, 'mail.task.manage', $case->team_id === null ? null : (int) $case->team_id),
                'draft' => $canDraft,
                'send' => $canSend,
                'sendFlag' => $this->flags->gmailSendEnabled(),
                'bank' => $canViewBank,
                'approve' => $this->access()->can($user, 'mail.approve.standard'),
            ],
            'primaryAction' => $this->primaryAction($case, $activeDraft, $canDraft, $canSend),
            'missing' => $this->commands->missingMandatoryFields($case),
        ]);
    }

    public function assign(AssignCaseRequest $request, MailCase $case): RedirectResponse
    {
        $user = $this->currentUser($request);
        $this->requireCaseVisible($user, $case);

        if (! $this->visibility()->canAssign($user, $case)) {
            abort(403, 'Kein Zuweisungsrecht für dieses Postfach.');
        }

        $data = $request->validated();
        $assignee = (int) ($data['assignee_user_id'] ?? 0) > 0
            ? User::query()->where('organization_id', $user->getAttribute('organization_id'))->find((int) $data['assignee_user_id'])
            : null;

        $result = $this->commands->assign($case, $assignee, $user, $data['next_step'] ?? null, $data['due_at'] ?? null);
        $this->audit('case.assigned', $case, [], ['assignee_user_id' => $assignee?->getKey()]);

        return $this->redirectWithResult('mail.cases.show', $result, ['case' => $case->getKey()]);
    }

    public function category(SetCategoryRequest $request, MailCase $case): RedirectResponse
    {
        $user = $this->currentUser($request);
        $this->requireCaseVisible($user, $case);
        $this->requirePermission($user, 'mail.case.assign', $case->team_id === null ? null : (int) $case->team_id);

        $result = $this->commands->setCategory($case, (string) $request->validated('case_type'), $user);
        $this->audit('case.category', $case, [], ['case_type' => $request->validated('case_type')]);

        return $this->redirectWithResult('mail.cases.show', $result, ['case' => $case->getKey()]);
    }

    /**
     * Bearbeitungsstatus ändern: Sichtbarkeit reicht nicht, verlangt wird ein Schreibrecht im Team des Vorgangs
     * (mail.task.manage). Team-Rolle auditor und Systemrolle read_only bleiben lesend.
     */
    public function status(SetStatusRequest $request, MailCase $case): RedirectResponse
    {
        $user = $this->currentUser($request);
        $this->requireCaseVisible($user, $case);
        $this->requirePermission($user, 'mail.task.manage', $case->team_id === null ? null : (int) $case->team_id);

        $result = $this->commands->setProcessingStatus($case, (string) $request->validated('status'), $user, $request->validated('reason'));

        if ($result->isOk()) {
            $this->audit('case.status', $case, [], ['status_processing' => $request->validated('status')]);
        }

        return $this->redirectWithResult('mail.cases.show', $result, ['case' => $case->getKey()]);
    }

    public function task(InternalTaskRequest $request, MailCase $case): RedirectResponse
    {
        $user = $this->currentUser($request);
        $this->requireCaseVisible($user, $case);
        $this->requirePermission($user, 'mail.task.manage', $case->team_id === null ? null : (int) $case->team_id);
        $data = $request->validated();

        // Zuständige Person nur aus der eigenen Organisation und nicht deaktiviert; fremde oder unbekannte IDs sind ein Fehler.
        $assigneeId = (int) ($data['assignee_user_id'] ?? 0);

        if ($assigneeId > 0 && ! User::query()->where('organization_id', $user->getAttribute('organization_id'))->whereNull('disabled_at')->whereKey($assigneeId)->exists()) {
            return redirect()->route('mail.cases.show', ['case' => $case->getKey()])->withErrors(['assignee_user_id' => 'Die zuständige Person gehört nicht zur eigenen Organisation oder ist deaktiviert.'])->withInput();
        }

        $result = $this->commands->createInternalTask($case, [
            'title' => (string) $data['title'],
            'instructions' => $data['instructions'] ?? null,
            'assignee_user_id' => $assigneeId > 0 ? $assigneeId : null,
            'due_at' => $data['due_at'] ?? null,
        ], $user);
        $this->audit('task.created', $case, [], ['title' => $data['title']]);

        return $this->redirectWithResult('mail.cases.show', $result, ['case' => $case->getKey()]);
    }

    /**
     * Manuelle Bestätigung einer Aufgabe, die aus einem Aktionsplan entstanden ist (Zielsystem nicht schreibfähig).
     * Ergebnis: Aufgabe done_manual_confirmed, Ausführungsbeleg manually_confirmed. Kein Zahlungsvorgang, keine
     * Schreiboperation; der Nutzer bestätigt nur, dass er die Änderung im Zielsystem vorgenommen hat.
     */
    public function confirmTask(Request $request, MailCase $case, Task $task, ManualTaskService $manual): RedirectResponse
    {
        $user = $this->currentUser($request);
        $this->requireCaseVisible($user, $case);
        $this->requirePermission($user, 'mail.task.manage', $case->team_id === null ? null : (int) $case->team_id);

        if ((int) $task->getAttribute('case_id') !== (int) $case->getKey()) {
            abort(404);
        }

        try {
            $verification = $manual->confirm($task, $user);
            $result = WorkflowResult::ok('Aufgabe manuell bestätigt (Ergebnis "'.$verification->getAttribute('result')->value.'"). Kein Nachlesen im Zielsystem, keine Zahlung ausgelöst.', (int) $task->getKey());
        } catch (ActionPolicyException $e) {
            $result = WorkflowResult::failed('Bestätigung nicht möglich ('.$e->code_key.'): '.$e->getMessage());
        }

        $this->audit('task.manually_confirmed', $task, ['status' => 'open'], ['outcome' => $result->outcome]);

        return $this->redirectWithResult('mail.cases.show', $result, ['case' => $case->getKey()]);
    }

    public function note(InternalNoteRequest $request, MailCase $case): RedirectResponse
    {
        $user = $this->currentUser($request);
        $this->requireCaseVisible($user, $case);
        $this->requirePermission($user, 'mail.task.manage', $case->team_id === null ? null : (int) $case->team_id);

        $result = $this->commands->addInternalNote($case, (string) $request->validated('note'), $user);
        $this->audit('case.note', $case);

        return $this->redirectWithResult('mail.cases.show', $result, ['case' => $case->getKey()]);
    }

    public function confirmCandidate(ConfirmCandidateRequest $request, MailCase $case): RedirectResponse
    {
        $user = $this->currentUser($request);
        $this->requireCaseVisible($user, $case);
        $this->requirePermission($user, 'mail.case.assign', $case->team_id === null ? null : (int) $case->team_id);

        $type = (string) $request->validated('type');
        $localId = (int) $request->validated('local_id');
        $result = $this->candidates->confirm($case, $type, $localId, $user);
        $this->audit('case.candidate_confirmed', $case, [], ['type' => $type, 'local_id' => $localId]);

        return $this->redirectWithResult('mail.cases.show', $result, ['case' => $case->getKey()]);
    }

    /**
     * @return Collection<int, User>
     */
    private function teamUsers(MailCase $case, User $user): Collection
    {
        $query = User::query()->where('organization_id', $user->getAttribute('organization_id'));
        $query->whereNull('disabled_at')->orderBy('name');

        if ($case->team_id !== null) {
            $query->whereIn('id', TeamMember::query()->where('team_id', $case->team_id)->select('user_id'));
        }

        return $query->get();
    }

    /**
     * @return array{key: string, label: string}
     */
    private function primaryAction(MailCase $case, ?MailDraft $draft, bool $canDraft, bool $canSend): array
    {
        if ($case->getAttribute('assignee_user_id') === null) {
            return ['key' => 'assign', 'label' => 'Zuweisen'];
        }

        if ($draft !== null && (string) $draft->getAttribute('status') === 'pending_approval') {
            return ['key' => 'review', 'label' => 'Entwurf prüfen'];
        }

        if ($draft !== null && $canSend) {
            return ['key' => 'send', 'label' => 'Senden'];
        }

        if ($draft !== null) {
            return ['key' => 'review_submit', 'label' => 'Zur Prüfung geben'];
        }

        if ($case->status_communication->requiresAction() && $canDraft) {
            return ['key' => 'draft', 'label' => 'Entwurf anlegen'];
        }

        return ['key' => 'status', 'label' => 'Status setzen'];
    }
}
