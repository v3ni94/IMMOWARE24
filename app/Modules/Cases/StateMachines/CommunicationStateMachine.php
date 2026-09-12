<?php

declare(strict_types=1);

namespace App\Modules\Cases\StateMachines;

use App\Modules\Cases\Enums\CommunicationStatus;
use App\Modules\Cases\Exceptions\InvalidTransitionException;

/**
 * Dimension 2, Kommunikationsstatus. Beantwortet ist nicht erledigt. "sent" setzt einen verifizierten Versand voraus
 * (Gmail-Antwort erkannt oder Versandabgleich), ein nur angeforderter Versand ändert die Dimension nicht.
 * Eine automatische Eingangsbestätigung führt nur nach acknowledged.
 */
final class CommunicationStateMachine
{
    public const string DIMENSION = 'communication';

    /**
     * @return array<int, CommunicationStatus>
     */
    public function targets(CommunicationStatus $from): array
    {
        return match ($from) {
            CommunicationStatus::ReplyNeeded => [CommunicationStatus::Acknowledged, CommunicationStatus::DraftExists, CommunicationStatus::InReview, CommunicationStatus::Sent, CommunicationStatus::NoReplyNeeded],
            CommunicationStatus::Acknowledged => [CommunicationStatus::DraftExists, CommunicationStatus::InReview, CommunicationStatus::Sent, CommunicationStatus::UpdateRequired, CommunicationStatus::NoReplyNeeded],
            CommunicationStatus::UpdateRequired => [CommunicationStatus::DraftExists, CommunicationStatus::InReview, CommunicationStatus::Sent, CommunicationStatus::NoReplyNeeded],
            CommunicationStatus::DraftExists => [CommunicationStatus::InReview, CommunicationStatus::Sent, CommunicationStatus::ReplyNeeded, CommunicationStatus::UpdateRequired],
            CommunicationStatus::InReview => [CommunicationStatus::DraftExists, CommunicationStatus::Sent, CommunicationStatus::ReplyNeeded],
            CommunicationStatus::Sent => [CommunicationStatus::ReplyNeeded, CommunicationStatus::UpdateRequired, CommunicationStatus::NoReplyNeeded],
            CommunicationStatus::NoReplyNeeded => [CommunicationStatus::ReplyNeeded, CommunicationStatus::UpdateRequired],
        };
    }

    public function canTransition(CommunicationStatus $from, CommunicationStatus $to): bool
    {
        return $from === $to || in_array($to, $this->targets($from), true);
    }

    public function assertTransition(CommunicationStatus $from, CommunicationStatus $to): void
    {
        if (! $this->canTransition($from, $to)) {
            throw new InvalidTransitionException(self::DIMENSION, $from->value, $to->value);
        }
    }

    /**
     * Kommunikation gilt als abgeschlossen (Abschlussbedingung), wenn gesendet oder begründet entbehrlich.
     */
    public function isComplete(CommunicationStatus $status): bool
    {
        return in_array($status, [CommunicationStatus::Sent, CommunicationStatus::NoReplyNeeded], true);
    }
}
