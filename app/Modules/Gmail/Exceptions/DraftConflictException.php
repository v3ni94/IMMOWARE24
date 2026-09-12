<?php

declare(strict_types=1);

namespace App\Modules\Gmail\Exceptions;

use RuntimeException;

/**
 * Der Gmail-Entwurf wurde außerhalb des Hubs geändert oder ist verschwunden. Kein Überschreiben, kein Versand.
 */
final class DraftConflictException extends RuntimeException
{
    public function __construct(public readonly int $draftId, public readonly string $state, string $message)
    {
        parent::__construct($message);
    }
}
