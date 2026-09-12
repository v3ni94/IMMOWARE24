<?php

declare(strict_types=1);

namespace App\Modules\Cases\Services;

use App\Modules\Actions\Enums\ActionStatus;
use App\Modules\Cases\DTO\CaseItemSpec;
use App\Modules\Cases\Enums\CaseStatus;
use App\Modules\Cases\Enums\CommunicationStatus;
use App\Modules\Cases\Enums\Priority;
use App\Modules\Cases\Events\CaseOpened;
use App\Modules\Cases\Events\CaseReopened;
use App\Modules\Cases\Events\CaseStatusChanged;
use App\Modules\Cases\Exceptions\AssignmentOpenException;
use App\Modules\Cases\Exceptions\CaseNotClosableException;
use App\Modules\Cases\Exceptions\InvalidTransitionException;
use App\Modules\Cases\Exceptions\PermissionDeniedException;
use App\Modules\Cases\Models\CaseItem;
use App\Modules\Cases\Models\CaseMessage;
use App\Modules\Cases\Models\MailCase;
use App\Modules\Cases\StateMachines\BusinessStateMachine;
use App\Modules\Cases\StateMachines\CommunicationStateMachine;
use App\Modules\Cases\StateMachines\ProcessingStateMachine;
use App\Modules\Gmail\Models\MailMessage;
use App\Modules\Mail\Services\MailAccess;
use App\Modules\Security\Models\User;
use App\Modules\Sla\Enums\ClockState;
use App\Modules\Sla\Enums\ClockType;
use App\Modules\Sla\Services\EmergencyQueue;
use App\Modules\Sla\Services\PriorityClassifier;
use App\Modules\Sla\Services\SlaClockService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Vorgänge, Teilanliegen und die drei Statusdimensionen. Aus einer Nachricht entstehen ein Vorgang und ein oder
 * mehrere Teilanliegen mit eigenem Typ, Zuständigen, Priorität und Fälligkeit; Thread und Vorgang sind n:m über
 * mail_case_messages verknüpft. Gelesen ist nicht bearbeitet, beantwortet ist nicht erledigt, Archivieren ist keine
 * Erledigung. Empfangszeit ist die Gmail internalDate (received_at), ein Import verjüngt keine Frist.
 */
final class CaseService
{
    public function __construct(
        private readonly CaseNumberGenerator $numbers,
        private readonly ProcessingStateMachine $processing,
        private readonly CommunicationStateMachine $communication,
        private readonly BusinessStateMachine $business,
        private readonly CloseConditionChecker $closeConditions,
        private readonly CaseStatusLogger $log,
        private readonly SlaClockService $clocks,
        private readonly PriorityClassifier $priorities,
        private readonly EmergencyQueue $emergencies,
        private readonly AssignmentService $assignment,
        private readonly MailAccess $access,
        private readonly Repository $config,
        private readonly Dispatcher $events,
    ) {}

    /**
     * Legt aus einer Nachricht einen Vorgang mit Teilanliegen an.
     *
     * @param  array<int, CaseItemSpec|array<string, mixed>>  $items
     * @param  array<string, mixed>  $attributes  case_type, title, team_id, assignee_user_id, next_step, due_at, legal_entity_code
     */
    public function openFromMessage(MailMessage $message, array $items, ?User $actor = null, array $attributes = [], bool $autoAssign = true): MailCase
    {
        if ($items === []) {
            throw new InvalidArgumentException('Ein Vorgang braucht mindestens ein Teilanliegen.');
        }

        $specs = array_map(static fn (CaseItemSpec|array $s): CaseItemSpec => $s instanceof CaseItemSpec ? $s : CaseItemSpec::fromArray($s), array_values($items));
        $receivedAt = $message->received_at instanceof CarbonImmutable ? $message->received_at : CarbonImmutable::parse((string) $message->received_at);
        $importedAt = $message->imported_at instanceof CarbonImmutable ? $message->imported_at : CarbonImmutable::now();
        $decision = $this->priorities->classify((string) $message->subject, (string) ($message->body_text ?? $message->snippet ?? ''), $receivedAt, $importedAt);

        return DB::transaction(function () use ($message, $specs, $actor, $attributes, $autoAssign, $receivedAt, $decision): MailCase {
            $mailbox = $message->loadMissing('mailbox')->mailbox;
            $priority = $decision->priority;
            $assigneeId = isset($attributes['assignee_user_id']) ? (int) $attributes['assignee_user_id'] : null;

            foreach ($specs as $spec) {
                if ($spec->priority !== null && $spec->priority->isHigherThan($priority)) {
                    $priority = $spec->priority;
                }

                $assigneeId ??= $spec->assigneeUserId;
            }

            $case = MailCase::query()->create([
                'organization_id' => $message->organization_id,
                'case_number' => $this->numbers->next(),
                'mailbox_id' => $message->mailbox_id,
                'team_id' => $attributes['team_id'] ?? $mailbox?->team_id,
                'parent_case_id' => $attributes['parent_case_id'] ?? null,
                'case_type' => (string) ($attributes['case_type'] ?? $specs[0]->itemType),
                'title' => mb_substr((string) ($attributes['title'] ?? $message->subject ?? $specs[0]->title), 0, 300),
                'priority' => $priority->value,
                'priority_reason' => mb_substr($decision->reason, 0, 200),
                'status_processing' => CaseStatus::New->value,
                'status_communication' => CommunicationStatus::ReplyNeeded->value,
                'status_business' => ActionStatus::Proposed->value,
                'assignee_user_id' => $assigneeId,
                'next_step' => $attributes['next_step'] ?? $specs[0]->nextStep,
                'due_at' => $attributes['due_at'] ?? null,
                'opened_at' => $receivedAt->utc(),
                'legal_entity_code' => $attributes['legal_entity_code'] ?? $mailbox?->legal_entity_code,
                'ai_classification_json' => ['priority_rule' => $decision->toArray()],
                'created_by' => $actor?->getKey(),
            ]);

            $this->log->log($case, 'processing', null, CaseStatus::New->value, 'Vorgang aus Nachricht angelegt.', $actor?->getKey(), null, $actor !== null ? 'user' : 'system', ['message_id' => $message->getKey(), 'received_at' => $receivedAt->toIso8601String()]);
            $this->log->log($case, 'priority', null, $priority->value, $decision->reason, $actor?->getKey(), null, 'system', $decision->toArray());
            $this->linkMessage($case, $message, 'origin', $actor?->getKey());

            $position = 1;
            $earliestDue = $case->due_at;

            foreach ($specs as $spec) {
                $item = $this->createItem($case, $spec, $message, $receivedAt, $decision->priority, $position++, $actor);

                if ($item->due_at !== null && ($earliestDue === null || $item->due_at->lessThan($earliestDue))) {
                    $earliestDue = $item->due_at;
                }
            }

            $case->forceFill(['due_at' => $earliestDue])->save();

            if ($autoAssign) {
                $this->assignment->apply($case, $message, $actor?->getKey());
                $case->refresh();
            }

            if ($case->assignee_user_id !== null && $this->processingStatus($case) === CaseStatus::New) {
                $this->transitionProcessing($case, CaseStatus::Open, $actor, 'Verantwortlicher beim Anlegen gesetzt.');
            }

            if ($priority === Priority::P0 && $decision->emergency) {
                $this->emergencies->raise($case->refresh(), $message, $decision->matchedRule ?? 'rule', $actor?->getKey());
            } elseif ($decision->reviewHint !== null) {
                $this->log->log($case, 'priority', $priority->value, $priority->value, $decision->reviewHint, null, null, 'system', ['review_hint' => true]);
            }

            $case->refresh();
            $this->events->dispatch(new CaseOpened($case));

            return $case;
        });
    }

    /**
     * Folgevorgang zu einem bestehenden Vorgang; Uhren starten mit dem Zeitpunkt der Anlage (neue Verpflichtung).
     *
     * @param  array<int, CaseItemSpec|array<string, mixed>>  $items
     * @param  array<string, mixed>  $attributes
     */
    public function createFollowUp(MailCase $parent, array $items, User $actor, array $attributes = []): MailCase
    {
        $origin = $parent->caseMessages()->where('link_type', 'origin')->first()?->loadMissing('message')->message;

        if (! $origin instanceof MailMessage) {
            throw new InvalidArgumentException('Folgevorgang braucht die Ursprungsnachricht des Vorgangs.');
        }

        $now = CarbonImmutable::now();
        $clone = $origin->replicate();
        $clone->setAttribute('received_at', $now);
        $clone->setAttribute('imported_at', $now);
        $clone->exists = true;
        $clone->setAttribute($origin->getKeyName(), $origin->getKey());

        $follow = $this->openFromMessage($clone, $items, $actor, $attributes + [
            'parent_case_id' => $parent->getKey(),
            'team_id' => $parent->team_id,
            'assignee_user_id' => $parent->assignee_user_id,
            'title' => 'Folgevorgang zu '.$parent->case_number.': '.(string) ($attributes['title'] ?? $parent->title),
        ], false);

        $this->linkMessage($follow, $origin, 'followup', $actor->getKey());
        $this->log->log($parent, 'processing', null, 'followup_created', 'Folgevorgang '.$follow->case_number.' angelegt.', $actor->getKey(), null, 'user', ['follow_up_case_id' => $follow->getKey()]);

        return $follow;
    }

    public function linkMessage(MailCase $case, MailMessage $message, string $linkType = 'manual', ?int $actorId = null): CaseMessage
    {
        if (! in_array($linkType, ['origin', 'followup', 'reply', 'forwarded', 'manual'], true)) {
            throw new InvalidArgumentException(sprintf('Unbekannter Verknüpfungstyp %s.', $linkType));
        }

        return CaseMessage::query()->firstOrCreate(
            ['case_id' => $case->getKey(), 'message_id' => $message->getKey()],
            ['link_type' => $linkType, 'linked_by' => $actorId, 'linked_at' => CarbonImmutable::now()],
        );
    }

    /**
     * Vorgänge, die mit dem Thread der Nachricht verknüpft sind (über Thread-ID oder In-Reply-To).
     *
     * @return array<int, MailCase>
     */
    public function casesForThread(MailMessage $message): array
    {
        $query = MailMessage::query()->allOrganizations()
            ->where('mailbox_id', $message->mailbox_id)
            ->where('id', '!=', $message->getKey());

        $query->where(static function ($q) use ($message): void {
            $matched = false;

            if ($message->thread_id !== null) {
                $q->orWhere('thread_id', $message->thread_id);
                $matched = true;
            }

            $inReplyTo = trim((string) $message->in_reply_to);

            if ($inReplyTo !== '') {
                $q->orWhere('rfc_message_id_hash', hash('sha256', $inReplyTo));
                $matched = true;
            }

            if (! $matched) {
                $q->whereRaw('1 = 0');
            }
        });

        $messageIds = $query->pluck('id')->all();

        if ($messageIds === []) {
            return [];
        }

        $caseIds = CaseMessage::query()->whereIn('message_id', $messageIds)->pluck('case_id')->unique()->all();

        return MailCase::query()->allOrganizations()->whereIn('id', $caseIds)->get()->all();
    }

    /**
     * Neue eingehende Nachricht (Gmail MessageSynced): verknüpfen, Kommunikationsbedarf setzen, gelöste oder
     * geschlossene Vorgänge wiedereröffnen. Aufgaben bleiben unverändert.
     *
     * @return array<int, MailCase>
     */
    public function handleInboundMessage(MailMessage $message): array
    {
        $affected = [];

        foreach ($this->casesForThread($message) as $case) {
            $this->linkMessage($case, $message, 'followup');
            $status = $this->processingStatus($case);
            $receivedAt = $message->received_at instanceof CarbonImmutable ? $message->received_at : CarbonImmutable::parse((string) $message->received_at);

            if (in_array($status, [CaseStatus::Resolved, CaseStatus::Closed], true)) {
                $this->transitionProcessing($case, CaseStatus::Reopened, null, 'Neue relevante Nachricht im Thread.', [], 'gmail');
                $case->forceFill(['reopened_at' => CarbonImmutable::now(), 'reopen_count' => (int) $case->reopen_count + 1, 'resolved_at' => null, 'closed_at' => null, 'closed_by' => null])->save();

                foreach ($case->items()->get() as $item) {
                    if (! $this->processingStatus($item)->isOpen()) {
                        $this->setStatus($case, $item, 'processing', $this->processingStatus($item)->value, CaseStatus::Reopened->value, 'Vorgang wiedereröffnet.', null, 'gmail');
                        $item->forceFill(['status_processing' => CaseStatus::Reopened->value, 'completed_at' => null])->save();
                        $this->clocks->restartNextUpdate($item, $receivedAt, 'Wiedereröffnung durch neue Nachricht.');
                    }
                }

                $this->events->dispatch(new CaseReopened($case, $message));
            }

            if ($this->communicationStatus($case) !== CommunicationStatus::ReplyNeeded) {
                $this->transitionCommunication($case, CommunicationStatus::ReplyNeeded, null, 'Neue eingehende Nachricht.', 'gmail');
            }

            $affected[] = $case->refresh();
        }

        return $affected;
    }

    /**
     * Gesendete Antwort in Gmail erkannt (GmailReplyDetected): Kommunikationsstatus sent, Uhren Annahme und erste
     * qualifizierte Antwort stoppen, Zwischenstandsuhr neu starten. Aufgaben und Bearbeitungsstatus bleiben. Gewertet
     * wird nur eine Nachricht, deren Empfänger (to, cc) einen eingehenden Absender des Vorgangs enthält.
     *
     * @return array<int, MailCase>
     */
    public function handleReplyDetected(MailMessage $message): array
    {
        $affected = [];
        $sentAt = $message->received_at instanceof CarbonImmutable ? $message->received_at : CarbonImmutable::parse((string) $message->received_at);
        $recipients = $this->recipientAddresses($message);

        foreach ($this->casesForThread($message) as $case) {
            // Nur eine Nachricht an den Absender des Vorgangs (oder einen anderen eingehenden Absender des Vorgangs) ist
            // eine Antwort. Interne Weiterleitungen im selben Thread (Handwerker, Kollegen) stoppen keine Uhr.
            if ($recipients === [] || array_intersect($recipients, $this->counterpartAddresses($case)) === []) {
                $this->linkMessage($case, $message, 'forwarded');
                $communication = $this->communicationStatus($case)->value;
                $this->log->log($case, 'communication', $communication, $communication, 'Gesendete Nachricht im Thread ohne Empfänger des Vorgangsabsenders erkannt, nicht als Antwort gewertet (Uhren laufen weiter).', null, null, 'gmail', ['message_id' => $message->getKey(), 'recipients' => count($recipients)]);
                $affected[] = $case->refresh();

                continue;
            }

            $this->linkMessage($case, $message, 'reply');

            if ($this->communicationStatus($case) !== CommunicationStatus::NoReplyNeeded) {
                $this->transitionCommunication($case, CommunicationStatus::Sent, null, 'Antwort in Gmail erkannt (Versandabgleich).', 'gmail');
            }

            $updates = ['first_response_at' => $case->first_response_at ?? $sentAt->utc()];

            if ($case->acknowledged_at === null) {
                $updates['acknowledged_at'] = $sentAt->utc();
            }

            $case->forceFill($updates)->save();

            foreach ($case->items()->get() as $item) {
                if ($this->processingStatus($item)->isOpen()) {
                    $this->clocks->stop($item, ClockType::Acknowledge, $sentAt, 'Antwort gesendet.', null, 'system');
                    $this->clocks->stop($item, ClockType::FirstQualifiedReply, $sentAt, 'Qualifizierte Antwort gesendet.', null, 'system');
                    $this->clocks->restartNextUpdate($item, $sentAt, 'Zwischenstand gesendet, nächste Frist läuft.');
                }
            }

            $affected[] = $case->refresh();
        }

        return $affected;
    }

    /**
     * Automatische Eingangsbestätigung: nur acknowledged, stoppt ausschließlich die Annahmeuhr, nie
     * first_qualified_reply.
     */
    public function acknowledgeAutomatically(MailCase $case, CarbonImmutable $sentAt): MailCase
    {
        if ($this->communicationStatus($case) === CommunicationStatus::ReplyNeeded) {
            $this->transitionCommunication($case, CommunicationStatus::Acknowledged, null, 'Automatische Eingangsbestätigung versandt.', 'system');
        }

        foreach ($case->items()->get() as $item) {
            $this->clocks->stop($item, ClockType::Acknowledge, $sentAt, 'Automatische Eingangsbestätigung.', null, 'system');
        }

        return $case->refresh();
    }

    /**
     * Menschliche Annahme des Vorgangs (P0: Bestätigung im Hub, sonst Zuweisung).
     */
    public function acknowledge(MailCase $case, User $actor, ?CarbonImmutable $at = null): MailCase
    {
        $at ??= CarbonImmutable::now();
        $case->forceFill(['acknowledged_at' => $case->acknowledged_at ?? $at->utc(), 'acknowledged_by' => $case->acknowledged_by ?? $actor->getKey()])->save();

        foreach ($case->items()->get() as $item) {
            $this->clocks->stop($item, ClockType::Acknowledge, $at, 'Annahme durch '.$actor->getAttribute('name'), $actor->getKey(), 'manual');
        }

        $this->log->log($case, 'processing', $this->processingStatus($case)->value, $this->processingStatus($case)->value, 'Vorgang angenommen.', $actor->getKey(), null, 'user', ['acknowledged' => true]);

        return $case->refresh();
    }

    /**
     * Übergang der Bearbeitungsdimension für Vorgang oder Teilanliegen. waiting_external verlangt externen
     * Verantwortlichen, Nachfassdatum und nächsten Kundenzwischenstand ($data). Resolved und Closed prüfen die
     * Abschlussbedingungen; ein Abschluss trotz offener Bedingungen ist nur über closeWithException möglich.
     *
     * @param  array<string, mixed>  $data
     */
    public function transitionProcessing(MailCase|CaseItem $subject, CaseStatus $to, ?User $actor = null, ?string $reason = null, array $data = [], string $source = 'user'): MailCase|CaseItem
    {
        $case = $subject instanceof MailCase ? $subject : $subject->loadMissing('case')->case;
        $item = $subject instanceof CaseItem ? $subject : null;
        $from = $this->processingStatus($subject);
        $this->processing->assertTransition($from, $to);

        if ($to === CaseStatus::WaitingExternal) {
            $this->assertWaitingExternalData($data);
        }

        if ($to === CaseStatus::Closed && ! in_array($from, [CaseStatus::Resolved, CaseStatus::New, CaseStatus::AssignmentOpen], true)) {
            throw new InvalidTransitionException(ProcessingStateMachine::DIMENSION, $from->value, $to->value, 'Abschluss nur aus resolved, sonst Ausnahmeabschluss mit Begründung.');
        }

        // Resolved und Closed prüfen dieselben Abschlussbedingungen. Auch new und assignment_open dürfen nur ohne
        // offene Bedingungen direkt geschlossen werden (Spam, Fehlzuordnung), sonst nur closeWithException mit Recht
        // und Begründung. Beantwortet ist nicht erledigt, unbeantwortet erst recht nicht.
        if ($to === CaseStatus::Resolved || $to === CaseStatus::Closed) {
            $unmet = $item !== null ? $this->closeConditions->unmetForItem($item) : $this->closeConditions->unmetForCase($case);

            if ($unmet !== []) {
                throw new CaseNotClosableException($unmet);
            }
        }

        if ($item !== null) {
            $this->applyItemTransition($item, $from, $to, $data, $actor, $reason);
        } else {
            $this->applyCaseTransition($case, $from, $to, $actor, $reason);
        }

        $this->setStatus($case, $item, 'processing', $from->value, $to->value, $reason, $actor?->getKey(), $source, $data === [] ? [] : ['data' => array_map(static fn (mixed $v): mixed => $v instanceof CarbonImmutable ? $v->toIso8601String() : $v, $data)]);

        return $subject->refresh();
    }

    public function transitionCommunication(MailCase $case, CommunicationStatus $to, ?User $actor = null, ?string $reason = null, string $source = 'user', ?string $waivedReason = null): MailCase
    {
        $from = $this->communicationStatus($case);
        $this->communication->assertTransition($from, $to);

        if ($to === CommunicationStatus::NoReplyNeeded && trim((string) ($waivedReason ?? $reason)) === '') {
            throw new InvalidTransitionException(CommunicationStateMachine::DIMENSION, $from->value, $to->value, 'Entbehrliche Kommunikation braucht eine Begründung.');
        }

        $case->forceFill([
            'status_communication' => $to->value,
            'communication_waived_reason' => $to === CommunicationStatus::NoReplyNeeded ? mb_substr((string) ($waivedReason ?? $reason), 0, 300) : $case->communication_waived_reason,
        ])->save();

        foreach ($case->items()->get() as $item) {
            if ($this->processingStatus($item)->isOpen()) {
                $item->forceFill(['status_communication' => $to->value])->save();
            }
        }

        $this->setStatus($case, null, 'communication', $from->value, $to->value, $reason, $actor?->getKey(), $source);

        return $case;
    }

    public function transitionBusiness(CaseItem $item, ActionStatus $to, ?User $actor = null, ?string $reason = null, string $source = 'user'): CaseItem
    {
        $case = $item->loadMissing('case')->case;
        $from = $item->status_business instanceof ActionStatus ? $item->status_business : ActionStatus::from((string) $item->status_business);
        $this->business->assertTransition($from, $to);

        if ($from === ActionStatus::Proposed && $to !== ActionStatus::Proposed) {
            $this->assertSensitiveChangeAllowed($case, (string) $item->item_type);
        }

        $item->forceFill(['status_business' => $to->value])->save();
        $this->setStatus($case, $item, 'business', $from->value, $to->value, $reason, $actor?->getKey(), $source);
        $this->syncCaseBusiness($case);

        return $item->refresh();
    }

    /**
     * Ausnahmeabschluss trotz offener Bedingungen: nur mit Recht mail.case.close_exception und Begründung.
     */
    public function closeWithException(MailCase $case, User $actor, string $reason): MailCase
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Ausnahmeabschluss braucht eine Begründung.');
        }

        if (! $this->access->can($actor, 'mail.case.close_exception', $case->team_id !== null ? (int) $case->team_id : null)) {
            throw new PermissionDeniedException('Kein Recht mail.case.close_exception für diesen Vorgang.');
        }

        $from = $this->processingStatus($case);
        $unmet = $this->closeConditions->unmetForCase($case);
        $now = CarbonImmutable::now();

        foreach ($case->items()->get() as $item) {
            $itemFrom = $this->processingStatus($item);

            if ($itemFrom->isOpen()) {
                $item->forceFill(['status_processing' => CaseStatus::Closed->value, 'completed_at' => $now])->save();
                $this->stopItemClocks($item, $now, 'Ausnahmeabschluss.', $actor->getKey());
                $this->setStatus($case, $item, 'processing', $itemFrom->value, CaseStatus::Closed->value, 'Ausnahmeabschluss: '.$reason, $actor->getKey(), 'user', ['exception' => true]);
            }
        }

        $case->forceFill([
            'status_processing' => CaseStatus::Closed->value,
            'closed_at' => $now,
            'closed_by' => $actor->getKey(),
            'close_reason' => mb_substr($reason, 0, 200),
            'closed_by_exception' => true,
            'resolved_at' => $case->resolved_at ?? $now,
        ])->save();

        $this->setStatus($case, null, 'processing', $from->value, CaseStatus::Closed->value, 'Ausnahmeabschluss: '.$reason, $actor->getKey(), 'user', ['exception' => true, 'unmet_conditions' => $unmet]);

        return $case->refresh();
    }

    /**
     * Archivieren ist keine Erledigung: setzt nur archived_at, Status und Ampel bleiben unverändert.
     */
    public function archive(MailCase $case, User $actor, ?string $reason = null): MailCase
    {
        $case->forceFill(['archived_at' => CarbonImmutable::now()])->save();
        $this->log->log($case, 'archive', null, 'archived', $reason ?? 'Archiviert (keine Erledigung).', $actor->getKey());

        return $case->refresh();
    }

    public function unarchive(MailCase $case, User $actor): MailCase
    {
        $case->forceFill(['archived_at' => null])->save();
        $this->log->log($case, 'archive', 'archived', 'active', 'Archivierung aufgehoben.', $actor->getKey());

        return $case->refresh();
    }

    /**
     * Sensible Änderungen (Stammdaten, Bankdaten) sind bei offener Zuordnung gesperrt.
     */
    public function assertSensitiveChangeAllowed(MailCase $case, string $type): void
    {
        if ($this->processingStatus($case) === CaseStatus::AssignmentOpen && in_array($type, (array) $this->config->get('hub.cases.sensitive_case_types', []), true)) {
            throw new AssignmentOpenException(sprintf('Zuordnung des Vorgangs %s ist offen, sensible Änderung (%s) gesperrt.', (string) $case->case_number, $type));
        }
    }

    /**
     * Empfängeradressen (to, cc) einer Nachricht, kleingeschrieben.
     *
     * @return array<int, string>
     */
    private function recipientAddresses(MailMessage $message): array
    {
        $addresses = [];

        foreach ([(array) ($message->to_json ?? []), (array) ($message->cc_json ?? [])] as $list) {
            foreach ($list as $entry) {
                $email = is_array($entry) ? ($entry['email'] ?? null) : $entry;

                if (is_string($email) && trim($email) !== '') {
                    $addresses[] = mb_strtolower(trim($email));
                }
            }
        }

        return array_values(array_unique($addresses));
    }

    /**
     * Absender (from, reply_to) aller eingehenden Nachrichten des Vorgangs, kleingeschrieben.
     *
     * @return array<int, string>
     */
    private function counterpartAddresses(MailCase $case): array
    {
        $messageIds = CaseMessage::query()->where('case_id', $case->getKey())->pluck('message_id')->all();

        if ($messageIds === []) {
            return [];
        }

        $addresses = [];

        foreach (MailMessage::query()->allOrganizations()->whereIn('id', $messageIds)->where('direction', 'inbound')->get(['id', 'from_address', 'reply_to']) as $inbound) {
            foreach ([$inbound->from_address, $inbound->reply_to] as $email) {
                if (is_string($email) && trim($email) !== '') {
                    $addresses[] = mb_strtolower(trim($email));
                }
            }
        }

        return array_values(array_unique($addresses));
    }

    public function processingStatus(MailCase|CaseItem $subject): CaseStatus
    {
        $value = $subject->status_processing;

        return $value instanceof CaseStatus ? $value : CaseStatus::from((string) $value);
    }

    public function communicationStatus(MailCase|CaseItem $subject): CommunicationStatus
    {
        $value = $subject->status_communication;

        return $value instanceof CommunicationStatus ? $value : CommunicationStatus::from((string) $value);
    }

    private function createItem(MailCase $case, CaseItemSpec $spec, MailMessage $message, CarbonImmutable $receivedAt, Priority $casePriority, int $position, ?User $actor): CaseItem
    {
        $types = (array) $this->config->get('hub.cases.case_type_groups', []);

        if (! array_key_exists($spec->itemType, $types)) {
            throw new InvalidArgumentException(sprintf('Unbekannter Vorgangstyp %s.', $spec->itemType));
        }

        $priority = $spec->priority ?? $casePriority;

        $item = CaseItem::query()->create([
            'case_id' => $case->getKey(),
            'position' => $position,
            'item_type' => $spec->itemType,
            'title' => mb_substr($spec->title, 0, 300),
            'description' => $spec->description,
            'status_processing' => CaseStatus::New->value,
            'status_communication' => CommunicationStatus::ReplyNeeded->value,
            'status_business' => ActionStatus::Proposed->value,
            'priority' => $priority->value,
            'assignee_user_id' => $spec->assigneeUserId ?? $case->assignee_user_id,
            'next_step' => $spec->nextStep,
            'due_at' => $spec->dueAt?->utc(),
            'source_message_id' => $message->getKey(),
            'received_at' => $receivedAt->utc(),
        ]);

        $this->log->log($case, 'processing', null, CaseStatus::New->value, 'Teilanliegen angelegt: '.$spec->title, $actor?->getKey(), $item, $actor !== null ? 'user' : 'system', ['item_type' => $spec->itemType, 'priority' => $priority->value]);

        $clocks = $this->clocks->startForItem($item, $receivedAt, $actor?->getKey());

        if ($item->due_at === null) {
            foreach ($clocks as $clock) {
                // Altbestand: Uhren sind cancelled, die Fälligkeit setzt ein Mensch (Pflichtfeld bleibt sichtbar offen).
                if ((string) $clock->clock_type === ClockType::Resolution->value && (string) $clock->state === ClockState::Running->value) {
                    $item->forceFill(['due_at' => $clock->target_at])->save();
                }
            }
        }

        if ($item->assignee_user_id !== null) {
            $item->forceFill(['status_processing' => CaseStatus::Open->value])->save();
            $this->setStatus($case, $item, 'processing', CaseStatus::New->value, CaseStatus::Open->value, 'Zuständiger gesetzt.', $actor?->getKey(), 'system');
        }

        return $item->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function applyItemTransition(CaseItem $item, CaseStatus $from, CaseStatus $to, array $data, ?User $actor, ?string $reason): void
    {
        $now = CarbonImmutable::now();
        $fill = ['status_processing' => $to->value];

        if ($to === CaseStatus::WaitingExternal) {
            $fill['waiting_external_party'] = mb_substr((string) $data['external_party'], 0, 200);
            $fill['follow_up_at'] = $this->toCarbon($data['follow_up_at'])->utc();
            $fill['next_customer_update_at'] = $this->toCarbon($data['next_customer_update_at'])->utc();
            $fill['next_step'] = 'Nachfassen bei '.$fill['waiting_external_party'];
            $this->clocks->pause($item, ClockType::Resolution, 'Wartet auf '.$fill['waiting_external_party'], $actor?->getKey());
        }

        if ($from->pausesClocks() && ! $to->pausesClocks()) {
            $this->clocks->resume($item, ClockType::Resolution, 'Wartezustand beendet.', $actor?->getKey());
        }

        if (in_array($to, [CaseStatus::Resolved, CaseStatus::Closed], true)) {
            $fill['completed_at'] = $now;
            $this->stopItemClocks($item, $now, $reason ?? $to->label(), $actor?->getKey());
        }

        if ($to === CaseStatus::Reopened) {
            $fill['completed_at'] = null;
        }

        if (in_array($to, [CaseStatus::Open, CaseStatus::InProgress], true) && $item->assignee_user_id === null && $actor !== null) {
            $fill['assignee_user_id'] = $actor->getKey();
        }

        $item->forceFill($fill)->save();
        $this->syncCaseFromItems($item->loadMissing('case')->case);
    }

    private function applyCaseTransition(MailCase $case, CaseStatus $from, CaseStatus $to, ?User $actor, ?string $reason): void
    {
        $now = CarbonImmutable::now();
        $fill = ['status_processing' => $to->value];

        if ($to === CaseStatus::Resolved) {
            $fill['resolved_at'] = $now;
        }

        if ($to === CaseStatus::Closed) {
            $fill['closed_at'] = $now;
            $fill['closed_by'] = $actor?->getKey();
            $fill['close_reason'] = $reason !== null ? mb_substr($reason, 0, 200) : null;
            $fill['resolved_at'] = $case->resolved_at ?? $now;

            foreach ($case->items()->get() as $item) {
                $itemFrom = $this->processingStatus($item);

                if ($itemFrom->isOpen()) {
                    $item->forceFill(['status_processing' => CaseStatus::Closed->value, 'completed_at' => $now])->save();
                    $this->stopItemClocks($item, $now, 'Vorgang geschlossen.', $actor?->getKey());
                    $this->setStatus($case, $item, 'processing', $itemFrom->value, CaseStatus::Closed->value, $reason, $actor?->getKey(), 'user');
                }
            }
        }

        if ($to === CaseStatus::Reopened) {
            $fill['resolved_at'] = null;
            $fill['closed_at'] = null;
            $fill['closed_by'] = null;
        }

        if (in_array($to, [CaseStatus::Open, CaseStatus::InProgress], true) && $case->assignee_user_id === null && $actor !== null) {
            $fill['assignee_user_id'] = $actor->getKey();
        }

        $case->forceFill($fill)->save();
    }

    /**
     * Vorgangsstatus folgt den Teilanliegen: alle gelöst oder geschlossen ergibt resolved, sonst bleibt der Vorgang offen.
     */
    private function syncCaseFromItems(MailCase $case): void
    {
        $items = $case->items()->get();

        if ($items->isEmpty()) {
            return;
        }

        $allDone = $items->every(fn (CaseItem $i): bool => ! $this->processingStatus($i)->isOpen());
        $caseStatus = $this->processingStatus($case);

        if ($allDone && $caseStatus->isOpen() && $caseStatus->canTransitionTo(CaseStatus::Resolved)) {
            $case->forceFill(['status_processing' => CaseStatus::Resolved->value, 'resolved_at' => CarbonImmutable::now()])->save();
            $this->setStatus($case, null, 'processing', $caseStatus->value, CaseStatus::Resolved->value, 'Alle Teilanliegen gelöst.', null, 'system');
        } elseif (! $allDone && ! $caseStatus->isOpen()) {
            $case->forceFill(['status_processing' => CaseStatus::Reopened->value, 'resolved_at' => null, 'closed_at' => null])->save();
            $this->setStatus($case, null, 'processing', $caseStatus->value, CaseStatus::Reopened->value, 'Teilanliegen wieder offen.', null, 'system');
        }
    }

    private function syncCaseBusiness(MailCase $case): void
    {
        $statuses = $case->items()->get()->map(fn (CaseItem $i): ActionStatus => $i->status_business instanceof ActionStatus ? $i->status_business : ActionStatus::from((string) $i->status_business));

        if ($statuses->isEmpty()) {
            return;
        }

        $worst = $statuses->first(fn (ActionStatus $s): bool => $this->business->hasOpenPartialFailure($s))
            ?? $statuses->first(fn (ActionStatus $s): bool => $this->business->isInFlight($s))
            ?? ($statuses->every(static fn (ActionStatus $s): bool => $s === ActionStatus::Verified) ? ActionStatus::Verified : ActionStatus::Proposed);

        $case->forceFill(['status_business' => $worst->value])->save();
    }

    private function stopItemClocks(CaseItem $item, CarbonImmutable $at, string $reason, ?int $actorId): void
    {
        foreach (ClockType::cases() as $type) {
            $this->clocks->stop($item, $type, $at, $reason, $actorId, $actorId !== null ? 'manual' : 'system');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertWaitingExternalData(array $data): void
    {
        $missing = [];

        if (trim((string) ($data['external_party'] ?? '')) === '') {
            $missing[] = 'externer Verantwortlicher';
        }

        if (empty($data['follow_up_at'])) {
            $missing[] = 'Nachfassdatum';
        }

        if (empty($data['next_customer_update_at'])) {
            $missing[] = 'nächster Kundenzwischenstand';
        }

        if ($missing !== []) {
            throw new InvalidTransitionException(ProcessingStateMachine::DIMENSION, 'open', CaseStatus::WaitingExternal->value, 'waiting_external verlangt: '.implode(', ', $missing).'.');
        }
    }

    private function toCarbon(mixed $value): CarbonImmutable
    {
        return $value instanceof CarbonImmutable ? $value : CarbonImmutable::parse((string) $value);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function setStatus(MailCase $case, ?CaseItem $item, string $dimension, ?string $from, string $to, ?string $reason, ?int $actorId, string $source, array $context = []): void
    {
        $this->log->log($case, $dimension, $from, $to, $reason, $actorId, $item, $source, $context);
        $this->events->dispatch(new CaseStatusChanged($case, $dimension, $from, $to, $item));
    }
}
