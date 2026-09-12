<?php

declare(strict_types=1);

namespace App\Modules\MailIntegration\Services;

use App\Modules\Cases\Enums\CommunicationStatus;
use App\Modules\Cases\Exceptions\InvalidTransitionException;
use App\Modules\Cases\Models\CaseMessage;
use App\Modules\Cases\Models\MailCase;
use App\Modules\Cases\Services\CaseService;
use App\Modules\Gmail\Exceptions\DraftConflictException;
use App\Modules\Gmail\Exceptions\SendRefusedException;
use App\Modules\Gmail\Models\MailDraft;
use App\Modules\Gmail\Models\MailMessage;
use App\Modules\Gmail\Services\DraftService;
use App\Modules\Gmail\Services\SendReconciliationService;
use App\Modules\Gmail\Services\SendService;
use App\Modules\Mail\Exceptions\MailIntegrationNotConfiguredException;
use App\Modules\Mail\Exceptions\MailRemoteException;
use App\Modules\Mail\Models\MailboxAlias;
use App\Modules\Mail\Services\MailFeatureFlags;
use App\Modules\MailUi\Contracts\DraftWorkflowInterface;
use App\Modules\MailUi\DTO\WorkflowResult;
use App\Modules\MailUi\Services\NullDraftWorkflow;
use App\Modules\Security\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Live-Verdrahtung der Oberfläche an das Modul Gmail. Ohne MAIL_GMAIL_DRAFTS_ENABLED bleiben Entwürfe lokal
 * (Verhalten der Null-Implementierung). Mit Flag laufen Anlage und Änderung über DraftService (Gmail-Entwurf mit
 * Threading, Konfliktprüfung). Versand nur über SendService: ok liefert die Oberfläche erst nach verifiziertem
 * Abgleich (Label SENT und Message-ID); ein HTTP-Erfolg allein ist "angefordert, nicht verifiziert".
 */
final class LiveDraftWorkflow implements DraftWorkflowInterface
{
    public function __construct(
        private readonly DraftService $drafts,
        private readonly SendService $send,
        private readonly CaseService $cases,
        private readonly MailFeatureFlags $flags,
        private readonly NullDraftWorkflow $local,
    ) {}

    public function createOrUpdate(MailCase $case, ?MailDraft $draft, array $data, User $actor): WorkflowResult
    {
        if (! $this->flags->gmailDraftsEnabled() || ($draft !== null && in_array((string) $draft->getAttribute('status'), ['local', 'pending_approval'], true))) {
            $result = $this->local->createOrUpdate($case, $draft, $data, $actor);
            $this->markCommunication($case, CommunicationStatus::DraftExists, 'Lokaler Entwurf angelegt oder geändert.');

            return $result;
        }

        $alias = $this->resolveAlias($case, $data['alias_id']);

        if ($alias === null) {
            return WorkflowResult::failed('Kein verifizierter Absender-Alias für das Postfach des Vorgangs. Entwurf nicht angelegt.');
        }

        try {
            if ($draft === null) {
                $message = $this->latestInbound($case);

                if ($message === null) {
                    return WorkflowResult::failed('Der Vorgang hat keine eingehende Nachricht, auf die geantwortet werden kann.');
                }

                $draft = $this->drafts->createReplyDraft($message, $data['body_text'], null, $alias, [], $actor, [
                    'to' => $data['to'],
                    'cc' => $data['cc'],
                    'case_id' => $case->getKey(),
                ]);

                if (trim($data['subject']) !== '' && $data['subject'] !== (string) $draft->getAttribute('subject')) {
                    $draft = $this->drafts->updateDraft($draft, ['subject' => $data['subject']], [], $actor);
                }

                $this->markCommunication($case, CommunicationStatus::DraftExists, 'Gmail-Entwurf angelegt.');

                return WorkflowResult::ok('Entwurf in Gmail angelegt (Status '.$draft->getAttribute('status').', noch nicht gesendet).', (int) $draft->getKey());
            }

            if (in_array((string) $draft->getAttribute('status'), [SendService::STATUS_SENT_REQUESTED, SendReconciliationService::VERIFIED], true)) {
                return WorkflowResult::failed('Der Entwurf wurde bereits zum Versand gegeben und kann nicht geändert werden.');
            }

            $draft = $this->drafts->updateDraft($draft, [
                'body_text' => $data['body_text'],
                'to' => $data['to'],
                'cc' => $data['cc'],
                'subject' => $data['subject'],
            ], [], $actor);

            $this->markCommunication($case, CommunicationStatus::DraftExists, 'Gmail-Entwurf geändert.');

            return WorkflowResult::ok('Entwurf in Gmail aktualisiert (Revision '.$draft->getAttribute('revision').').', (int) $draft->getKey());
        } catch (DraftConflictException $e) {
            return WorkflowResult::failed($e->getMessage());
        } catch (MailIntegrationNotConfiguredException $e) {
            return WorkflowResult::unavailable('Gmail ist nicht eingerichtet: '.$e->getMessage());
        } catch (MailRemoteException $e) {
            Log::warning('MailIntegration: Gmail-Entwurf nicht übertragen.', ['case_id' => $case->getKey(), 'status' => $e->httpStatus]);

            return WorkflowResult::failed('Gmail hat den Entwurf nicht angenommen (HTTP '.$e->httpStatus.'). Kein Entwurf in Gmail, nichts gesendet.');
        } catch (InvalidArgumentException $e) {
            return WorkflowResult::failed($e->getMessage());
        }
    }

    public function submitForReview(MailDraft $draft, User $actor): WorkflowResult
    {
        $status = (string) $draft->getAttribute('status');

        if ($status === 'local') {
            $result = $this->local->submitForReview($draft, $actor);
        } elseif ($status === DraftService::STATUS_PUSHED) {
            $result = WorkflowResult::ok('Gmail-Entwurf zur Prüfung gegeben. Versand erst durch eine berechtigte Person.', (int) $draft->getKey());
        } else {
            return WorkflowResult::failed('Nur lokale oder in Gmail hinterlegte Entwürfe können zur Prüfung gegeben werden (Status '.$status.').');
        }

        $case = $draft->case()->first();

        if ($case instanceof MailCase) {
            $this->markCommunication($case, CommunicationStatus::InReview, 'Entwurf zur Prüfung gegeben.', $actor);
        }

        return $result;
    }

    public function approve(MailDraft $draft, User $approver, ?CarbonImmutable $reauthConfirmedAt = null): WorkflowResult
    {
        if ((string) $draft->getAttribute('status') === 'pending_approval' && ! $this->flags->gmailDraftsEnabled()) {
            return $this->local->approve($draft, $approver, $reauthConfirmedAt);
        }

        try {
            $draft = $this->drafts->approve($draft, $approver, $reauthConfirmedAt);
        } catch (InvalidArgumentException $e) {
            return WorkflowResult::failed($e->getMessage());
        }

        return WorkflowResult::ok('Entwurf freigegeben (Revision '.$draft->getAttribute('revision').'). Versand bleibt ein eigener Schritt mit Reauth.', (int) $draft->getKey());
    }

    public function send(MailDraft $draft, User $actor, ?CarbonImmutable $reauthConfirmedAt = null): WorkflowResult
    {
        if (! $this->flags->gmailSendEnabled()) {
            return WorkflowResult::unavailable('Versand gesperrt: MAIL_GMAIL_SEND_ENABLED=false.');
        }

        if (in_array((string) $draft->getAttribute('status'), ['local', 'pending_approval'], true)) {
            return WorkflowResult::unavailable('Der Entwurf liegt nur lokal vor und ist nicht in Gmail hinterlegt (MAIL_GMAIL_DRAFTS_ENABLED). Kein Versand.');
        }

        try {
            $draft = $this->send->send($draft, $actor, $reauthConfirmedAt);
        } catch (SendRefusedException $e) {
            return WorkflowResult::failed('Versand abgelehnt ('.$e->reason.'): '.$e->getMessage());
        } catch (Throwable $e) {
            Log::error('MailIntegration: Versand mit Fehler.', ['draft_id' => $draft->getKey(), 'reason' => $e::class]);

            return WorkflowResult::failed('Versand technisch fehlgeschlagen ('.class_basename($e).'). Der Entwurf gilt nicht als gesendet.');
        }

        $verification = (string) $draft->getAttribute('send_verification');

        return match ($verification) {
            SendReconciliationService::VERIFIED => WorkflowResult::ok('Versand verifiziert: Nachricht mit passender Message-ID im Ordner Gesendet gefunden.', (int) $draft->getKey()),
            SendReconciliationService::UNCLEAR => WorkflowResult::failed('Versand unklar: keine Bestätigung im Ordner Gesendet. Kein erneuter Versand, Postfach prüfen.'),
            default => WorkflowResult::unavailable('Versand angefordert, noch nicht verifiziert. Der Abgleich mit dem Ordner Gesendet läuft; erst danach gilt die Antwort als gesendet.'),
        };
    }

    private function resolveAlias(MailCase $case, ?int $aliasId): ?MailboxAlias
    {
        $query = MailboxAlias::query()->where('mailbox_id', $case->getAttribute('mailbox_id'))->where('verification_status', 'accepted');

        if ($aliasId !== null) {
            $query->whereKey($aliasId);
        } else {
            $query->orderByDesc('is_default')->orderBy('id');
        }

        $alias = $query->first();

        return $alias instanceof MailboxAlias ? $alias : null;
    }

    private function latestInbound(MailCase $case): ?MailMessage
    {
        $ids = CaseMessage::query()->where('case_id', $case->getKey())->pluck('message_id')->all();

        if ($ids === []) {
            return null;
        }

        $query = MailMessage::query()->withoutGlobalScopes()->whereIn('id', $ids)->where('direction', 'inbound');
        $query->orderByDesc('received_at');
        $latestId = $query->value('id');

        return $latestId === null ? null : MailMessage::query()->withoutGlobalScopes()->find((int) $latestId);
    }

    private function markCommunication(MailCase $case, CommunicationStatus $to, string $reason, ?User $actor = null): void
    {
        if ($this->cases->communicationStatus($case) === $to) {
            return;
        }

        try {
            $this->cases->transitionCommunication($case, $to, $actor, $reason);
        } catch (InvalidTransitionException) {
            // Kommunikationsstatus lässt den Übergang nicht zu (zum Beispiel bereits gesendet); Entwurf bleibt gültig.
        }
    }
}
