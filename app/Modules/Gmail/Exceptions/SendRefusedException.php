<?php

declare(strict_types=1);

namespace App\Modules\Gmail\Exceptions;

use RuntimeException;

/**
 * Versand abgelehnt vor dem Aufruf der Gegenstelle (Flag, Recht, Alias, neue Threadnachricht, geänderter Hash,
 * unklarer vorheriger Versand). Trägt einen maschinenlesbaren Grund (reason).
 */
final class SendRefusedException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
