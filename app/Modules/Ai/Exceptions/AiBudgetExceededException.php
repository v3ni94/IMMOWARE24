<?php

declare(strict_types=1);

namespace App\Modules\Ai\Exceptions;

use RuntimeException;

/**
 * Tages- oder Monatsbudget erreicht. Es erfolgt kein Aufruf; die Bearbeitung bleibt manuell möglich.
 */
final class AiBudgetExceededException extends RuntimeException
{
    public function __construct(public readonly string $period, public readonly string $dimension)
    {
        parent::__construct(sprintf('KI-Budget (%s, %s) erreicht, kein Aufruf.', $period, $dimension));
    }
}
