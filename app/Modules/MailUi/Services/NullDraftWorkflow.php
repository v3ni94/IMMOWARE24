<?php

declare(strict_types=1);

namespace App\Modules\MailUi\Services;

use App\Modules\Cases\Models\MailCase;
use App\Modules\Gmail\Models\MailDraft;
use App\Modules\MailUi\Contracts\DraftWorkflowInterface;
use App\Modules\MailUi\DTO\WorkflowResult;
use App\Modules\Security\Models\User;

/**
 * Null-Implementierung bis zur Verdrahtung des Moduls Gmail: Entwürfe werden nur lokal gespeichert (status local,
 * pending_approval), nie an Gmail übertragen. Versand liefert immer "nicht verfügbar", nie einen Erfolg.
 */
final class NullDraftWorkflow implements DraftWorkflowInterface
{
    public function createOrUpdate(MailCase $case, ?MailDraft $draft, array $data, User $actor): WorkflowResult
    {
        $attributes = [
            'organization_id' => $case->getAttribute('organization_id'),
            'case_id' => $case->getKey(),
            'mailbox_id' => $case->getAttribute('mailbox_id'),
            'alias_id' => $data['alias_id'],
            'to_json' => $data['to'],
            'cc_json' => $data['cc'],
            'subject' => $data['subject'],
            'body_text' => $data['body_text'],
            'generated_by' => 'user',
        ];

        if ($draft === null) {
            $attributes['status'] = 'local';
            $attributes['created_by'] = $actor->getKey();
            $draft = MailDraft::query()->create($attributes);

            return WorkflowResult::ok('Entwurf lokal angelegt (nicht an Gmail übertragen).', (int) $draft->getKey());
        }

        if (! in_array((string) $draft->getAttribute('status'), ['local', 'pending_approval'], true)) {
            return WorkflowResult::failed('Der Entwurf ist bereits in Prüfung abgeschlossen oder versendet und kann nicht geändert werden.');
        }

        $draft->fill($attributes + ['status' => 'local'])->save();

        return WorkflowResult::ok('Entwurf aktualisiert (lokal, nicht an Gmail übertragen).', (int) $draft->getKey());
    }

    public function submitForReview(MailDraft $draft, User $actor): WorkflowResult
    {
        if ((string) $draft->getAttribute('status') !== 'local') {
            return WorkflowResult::failed('Nur lokale Entwürfe können zur Prüfung gegeben werden.');
        }

        $draft->forceFill(['status' => 'pending_approval'])->save();

        return WorkflowResult::ok('Entwurf zur Prüfung gegeben.', (int) $draft->getKey());
    }

    public function send(MailDraft $draft, User $actor): WorkflowResult
    {
        return WorkflowResult::unavailable('Versand nicht verfügbar: Gmail-Versand ist nicht verdrahtet. Der Entwurf bleibt unverändert.');
    }
}
