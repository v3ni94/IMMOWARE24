<?php

declare(strict_types=1);

namespace App\Modules\Gmail\Exceptions;

use RuntimeException;

/**
 * Lokaler Quota-Zähler des Postfachs ist erschöpft (Einheiten je Minute). Vorübergehend, Retry über Backoff.
 */
final class GmailQuotaExceededException extends RuntimeException
{
    public function __construct(public readonly int $mailboxId, public readonly int $used, public readonly int $limit)
    {
        parent::__construct(sprintf('Quota-Budget des Postfachs %d erschöpft (%d von %d Einheiten je Minute).', $mailboxId, $used, $limit));
    }
}
