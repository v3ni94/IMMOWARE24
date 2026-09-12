<?php

declare(strict_types=1);

namespace App\Modules\Lexware\Exceptions;

use RuntimeException;

/**
 * Lexware Office nicht erreichbar oder Antwort nicht verwertbar (Verbindung, 5xx, 401/403). Fachlich immer
 * "Ungeklärt", nie "kein Kunde" und nie Erfolg.
 */
final class LexwareUnavailableException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $httpStatus = null)
    {
        parent::__construct($message);
    }
}
