<?php

declare(strict_types=1);

namespace App\Modules\MailUi\Http\Controllers;

use App\Modules\Cases\Models\MailCase;
use App\Modules\Gmail\Models\MailDraft;
use App\Modules\Mail\Models\MailboxAlias;
use App\Modules\Mail\Services\MailFeatureFlags;
use App\Modules\MailUi\Contracts\DraftWorkflowInterface;
use App\Modules\MailUi\DTO\WorkflowResult;
use App\Modules\MailUi\Http\Requests\DraftRequest;
use App\Modules\Security\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Antworteditor: Entwurf anlegen oder aktualisieren (Postfachrecht can_draft), zur Prüfung geben, Freigabe durch eine
 * zweite Person (mail.approve.standard, nie der Autor), Senden nur mit Flag gmail_send, Recht mail.send, Postfachrecht
 * can_send, vorhandener Fremdfreigabe (403 sonst) und Re-Authentifizierung (Route in Gruppe 2fa.fresh).
 * Der Alias muss zum Postfach des Vorgangs gehören (Gesellschaften werden nie vermischt).
 */
final class DraftController extends MailUiController
{
    public function __construct(
        private readonly DraftWorkflowInterface $drafts,
        private readonly MailFeatureFlags $flags,
    ) {}

    public function store(DraftRequest $request, MailCase $case): RedirectResponse
    {
        return $this->save($request, $case, null);
    }

    public function update(DraftRequest $request, MailCase $case, MailDraft $draft): RedirectResponse
    {
        $this->assertDraftBelongsToCase($draft, $case);

        return $this->save($request, $case, $draft);
    }

    public function review(Request $request, MailCase $case, MailDraft $draft): RedirectResponse
    {
        $user = $this->currentUser($request);
        $this->requireCaseVisible($user, $case);
        $this->assertDraftBelongsToCase($draft, $case);

        if (! $this->visibility()->canDraft($user, $case)) {
            abort(403, 'Kein Entwurfsrecht für dieses Postfach.');
        }

        $result = $this->drafts->submitForReview($draft, $user);
        $this->audit('draft.review_requested', $draft);

        return $this->redirectWithResult('mail.cases.show', $result, ['case' => $case->getKey()]);
    }

    /**
     * Freigabe durch eine zweite Person (mail.approve.standard im Team des Postfachs, nicht der Autor). Die Freigabe
     * löst keinen Versand aus.
     */
    public function approve(Request $request, MailCase $case, MailDraft $draft): RedirectResponse
    {
        $user = $this->currentUser($request);
        $this->requireCaseVisible($user, $case);
        $this->assertDraftBelongsToCase($draft, $case);
        $this->requirePermission($user, 'mail.approve.standard', $case->team_id === null ? null : (int) $case->team_id);

        if ((int) $draft->getAttribute('created_by') === (int) $user->getKey()) {
            $this->audit('draft.approval_rejected_self', $draft);
            abort(403, 'Der Autor kann den eigenen Entwurf nicht freigeben (Vier-Augen-Prinzip).');
        }

        $result = $this->drafts->approve($draft, $user);
        $this->audit('draft.approved', $draft, [], ['outcome' => $result->outcome, 'revision' => $draft->getAttribute('revision')]);

        return $this->redirectWithResult('mail.cases.show', $result, ['case' => $case->getKey()]);
    }

    public function send(Request $request, MailCase $case, MailDraft $draft): RedirectResponse
    {
        $user = $this->currentUser($request);
        $this->requireCaseVisible($user, $case);
        $this->assertDraftBelongsToCase($draft, $case);

        if (! $this->flags->gmailSendEnabled()) {
            return $this->redirectWithResult('mail.cases.show', WorkflowResult::unavailable('Versand gesperrt: MAIL_GMAIL_SEND_ENABLED=false.'), ['case' => $case->getKey()]);
        }

        if (! $this->visibility()->canSend($user, $case)) {
            abort(403, 'Kein Versandrecht für dieses Postfach.');
        }

        $this->assertFourEyes($draft, $user);

        $result = $this->drafts->send($draft, $user);
        $this->audit('draft.send_requested', $draft, [], ['outcome' => $result->outcome]);

        return $this->redirectWithResult('mail.cases.show', $result, ['case' => $case->getKey()]);
    }

    private function save(DraftRequest $request, MailCase $case, ?MailDraft $draft): RedirectResponse
    {
        $user = $this->currentUser($request);
        $this->requireCaseVisible($user, $case);

        if (! $this->visibility()->canDraft($user, $case)) {
            abort(403, 'Kein Entwurfsrecht für dieses Postfach.');
        }

        if ($case->mailbox_id === null) {
            return $this->redirectWithResult('mail.cases.show', WorkflowResult::failed('Der Vorgang hat kein Postfach, ein Entwurf ist nicht möglich.'), ['case' => $case->getKey()]);
        }

        $aliasId = (int) ($request->validated('alias_id') ?? 0);

        if ($aliasId > 0 && ! MailboxAlias::query()->where('id', $aliasId)->where('mailbox_id', $case->mailbox_id)->exists()) {
            return $this->redirectWithResult('mail.cases.show', WorkflowResult::failed('Der Absender-Alias gehört nicht zum Postfach des Vorgangs.'), ['case' => $case->getKey()]);
        }

        $to = $request->addresses('to');

        if ($to === []) {
            return redirect()->route('mail.cases.show', ['case' => $case->getKey()])->withErrors(['to' => 'Mindestens eine gültige Empfängeradresse ist erforderlich.'])->withInput();
        }

        $result = $this->drafts->createOrUpdate($case, $draft, [
            'alias_id' => $aliasId > 0 ? $aliasId : null,
            'to' => $to,
            'cc' => $request->addresses('cc'),
            'subject' => (string) $request->validated('subject'),
            'body_text' => (string) $request->validated('body_text'),
        ], $user);

        $this->audit($draft === null ? 'draft.created' : 'draft.updated', $draft ?? $case, [], ['draft_id' => $result->entityId]);

        return $this->redirectWithResult('mail.cases.show', $result, ['case' => $case->getKey()]);
    }

    /**
     * Vier-Augen vor jedem Versand an Externe: Freigabe vorhanden, Freigebende ungleich Autor und ungleich Sendende.
     * Lokale Entwürfe (ohne Gmail-Verdrahtung) liefert der Workflow als nicht verfügbar, dort greift kein 403.
     */
    private function assertFourEyes(MailDraft $draft, User $sender): void
    {
        if (in_array((string) $draft->getAttribute('status'), ['local', 'pending_approval'], true)) {
            return;
        }

        $approvedBy = $draft->getAttribute('approved_by');
        $author = $draft->getAttribute('created_by');

        if ($approvedBy === null || $draft->getAttribute('approved_at') === null) {
            $this->audit('draft.send_refused', $draft, [], ['reason' => 'approval_missing']);
            abort(403, 'Der Entwurf ist nicht freigegeben. Versand erst nach Freigabe durch eine zweite Person.');
        }

        if ((int) $approvedBy === (int) $sender->getKey() || ($author !== null && (int) $approvedBy === (int) $author)) {
            $this->audit('draft.send_refused', $draft, [], ['reason' => 'approval_self']);
            abort(403, 'Vier-Augen-Prinzip: Freigebende Person darf weder Autor noch Sendende sein.');
        }
    }

    private function assertDraftBelongsToCase(MailDraft $draft, MailCase $case): void
    {
        if ((int) $draft->getAttribute('case_id') !== (int) $case->getKey()) {
            abort(404);
        }
    }
}
