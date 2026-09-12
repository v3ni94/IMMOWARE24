<?php

declare(strict_types=1);

namespace App\Modules\Gmail\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * History-ID ungültig (404): kontrollierter Neuabgleich ohne Löschung. gapCount ist die Zahl fehlender Nachrichten.
 */
final class HistoryGapDetected
{
    use Dispatchable;

    public function __construct(public readonly int $mailboxId, public readonly int $gapCount) {}
}
