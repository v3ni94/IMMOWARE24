<?php

declare(strict_types=1);

namespace App\Modules\Gmail\Exceptions;

use InvalidArgumentException;

/**
 * Freigabe eines Entwurfs abgelehnt (Status, Vier-Augen, Recht, fehlende Re-Authentifizierung). Trägt einen
 * maschinenlesbaren Grund (reason) analog SendRefusedException. Erbt von InvalidArgumentException, damit bestehende
 * Aufrufer die Ablehnung weiterhin als Fachfehler behandeln.
 */
final class DraftApprovalRefusedException extends InvalidArgumentException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
