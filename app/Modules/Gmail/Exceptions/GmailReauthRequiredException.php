<?php

declare(strict_types=1);

namespace App\Modules\Gmail\Exceptions;

use RuntimeException;

/**
 * Token-Refresh ist fehlgeschlagen oder es liegt kein Refresh-Token vor. Das Postfach steht auf reauth_required;
 * eine erneute Autorisierung durch eine berechtigte Person ist nötig. Kein Retry durch Jobs.
 */
final class GmailReauthRequiredException extends RuntimeException
{
    public function __construct(public readonly int $mailboxId, string $message = 'Postfach benötigt eine erneute Autorisierung.')
    {
        parent::__construct($message);
    }
}
