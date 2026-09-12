<?php

declare(strict_types=1);

namespace App\Modules\Gmail\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Watch-Ablauf unterschreitet die konfigurierte Alarmgrenze oder der Watch konnte nicht erneuert werden.
 */
final class WatchExpiringSoon
{
    use Dispatchable;

    public function __construct(public readonly int $mailboxId, public readonly ?string $expiresAt, public readonly string $reason) {}
}
