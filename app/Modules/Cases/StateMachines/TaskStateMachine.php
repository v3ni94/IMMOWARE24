<?php

declare(strict_types=1);

namespace App\Modules\Cases\StateMachines;

use App\Modules\Cases\Enums\TaskStatus;
use App\Modules\Cases\Exceptions\InvalidTransitionException;

final class TaskStateMachine
{
    public const string DIMENSION = 'task';

    public function canTransition(TaskStatus $from, TaskStatus $to): bool
    {
        return $from === $to || $from->canTransitionTo($to);
    }

    public function assertTransition(TaskStatus $from, TaskStatus $to): void
    {
        if (! $this->canTransition($from, $to)) {
            throw new InvalidTransitionException(self::DIMENSION, $from->value, $to->value);
        }
    }
}
