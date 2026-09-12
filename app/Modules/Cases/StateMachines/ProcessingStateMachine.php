<?php

declare(strict_types=1);

namespace App\Modules\Cases\StateMachines;

use App\Modules\Cases\Enums\CaseStatus;
use App\Modules\Cases\Exceptions\InvalidTransitionException;

/**
 * Dimension 1, Bearbeitungsstatus. Übergangsmatrix in CaseStatus::allowedTransitions(); verbotene Übergänge werfen.
 * Gelesen ist nicht bearbeitet: kein Übergang ohne Handlung eines Verantwortlichen oder Systemereignis.
 */
final class ProcessingStateMachine
{
    public const string DIMENSION = 'processing';

    public function canTransition(CaseStatus $from, CaseStatus $to): bool
    {
        return $from === $to || $from->canTransitionTo($to);
    }

    public function assertTransition(CaseStatus $from, CaseStatus $to): void
    {
        if (! $this->canTransition($from, $to)) {
            throw new InvalidTransitionException(self::DIMENSION, $from->value, $to->value);
        }
    }

    /**
     * @return array<int, CaseStatus>
     */
    public function targets(CaseStatus $from): array
    {
        return $from->allowedTransitions();
    }
}
