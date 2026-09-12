<?php

declare(strict_types=1);

namespace App\Modules\Cases\Exceptions;

use DomainException;

/**
 * Verbotener Statusübergang in einer der drei Dimensionen oder im Aufgabenstatus.
 */
final class InvalidTransitionException extends DomainException
{
    public function __construct(
        public readonly string $dimension,
        public readonly string $from,
        public readonly string $to,
        ?string $detail = null,
    ) {
        parent::__construct(sprintf('Übergang %s: %s nach %s ist nicht erlaubt.%s', $dimension, $from, $to, $detail !== null ? ' '.$detail : ''));
    }
}
