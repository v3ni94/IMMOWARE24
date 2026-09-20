<?php

declare(strict_types=1);

namespace App\Modules\Learning\Exceptions;

use App\Modules\Learning\Enums\LearningKind;
use RuntimeException;

/**
 * Keine passende Immoware24-Connection für die gewählte Art der Lernphase gefunden.
 */
final class NoConnectionForKindException extends RuntimeException
{
    public function __construct(public readonly LearningKind $kind)
    {
        parent::__construct(sprintf('Keine Verbindung des Typs %s für die Organisation gefunden.', $kind->label()));
    }
}
