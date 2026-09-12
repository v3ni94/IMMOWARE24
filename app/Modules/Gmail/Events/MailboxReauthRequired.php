<?php

declare(strict_types=1);

namespace App\Modules\Gmail\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Postfach benötigt erneute Autorisierung (Refresh fehlgeschlagen, Token widerrufen).
 */
final class MailboxReauthRequired
{
    use Dispatchable;

    public function __construct(public readonly int $mailboxId, public readonly string $reason) {}
}
