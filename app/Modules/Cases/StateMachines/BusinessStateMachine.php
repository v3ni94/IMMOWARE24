<?php

declare(strict_types=1);

namespace App\Modules\Cases\StateMachines;

use App\Modules\Actions\Enums\ActionStatus;
use App\Modules\Cases\Exceptions\InvalidTransitionException;

/**
 * Dimension 3, Geschäftsergebnis. Matrix in ActionStatus::allowedTransitions(). Executed ist kein Geschäftsergebnis,
 * nur Verified.
 */
final class BusinessStateMachine
{
    public const string DIMENSION = 'business';

    public function canTransition(ActionStatus $from, ActionStatus $to): bool
    {
        return $from === $to || $from->canTransitionTo($to);
    }

    public function assertTransition(ActionStatus $from, ActionStatus $to): void
    {
        if (! $this->canTransition($from, $to)) {
            throw new InvalidTransitionException(self::DIMENSION, $from->value, $to->value);
        }
    }

    /**
     * Offener Teilfehler: fehlgeschlagen, Ergebnis unklar oder manuelle Prüfung.
     */
    public function hasOpenPartialFailure(ActionStatus $status): bool
    {
        return in_array($status, [ActionStatus::Failed, ActionStatus::ResultUnclear, ActionStatus::ManualReview], true);
    }

    /**
     * Es wurde eine Aktion begonnen, die noch kein verifiziertes Ergebnis hat.
     */
    public function isInFlight(ActionStatus $status): bool
    {
        return ! in_array($status, [ActionStatus::Proposed, ActionStatus::Verified], true);
    }
}
