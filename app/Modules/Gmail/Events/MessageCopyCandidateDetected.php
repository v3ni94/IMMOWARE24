<?php

declare(strict_types=1);

namespace App\Modules\Gmail\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Gleiche RFC-Message-ID in einem anderen Postfach gefunden: Kandidat für eine Verknüpfung, nie eine Zusammenführung.
 */
final class MessageCopyCandidateDetected
{
    use Dispatchable;

    public function __construct(public readonly int $messageId, public readonly int $candidateMessageId) {}
}
