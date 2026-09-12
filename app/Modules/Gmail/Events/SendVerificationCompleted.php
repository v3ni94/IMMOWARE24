<?php

declare(strict_types=1);

namespace App\Modules\Gmail\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Versandabgleich abgeschlossen. verification: sent_verified, sent_unverified oder unclear. Kein Zustellnachweis.
 */
final class SendVerificationCompleted
{
    use Dispatchable;

    public function __construct(public readonly int $draftId, public readonly string $verification) {}
}
