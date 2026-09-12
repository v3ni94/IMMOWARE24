<?php

declare(strict_types=1);

namespace App\Modules\Gmail\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Nachricht importiert oder aktualisiert (Metadaten, Körper). Nur IDs, keine Inhalte.
 */
final class MessageImported
{
    use Dispatchable;

    public function __construct(public readonly int $mailboxId, public readonly int $messageId, public readonly bool $created) {}
}
