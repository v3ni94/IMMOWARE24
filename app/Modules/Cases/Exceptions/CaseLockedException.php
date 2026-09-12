<?php

declare(strict_types=1);

namespace App\Modules\Cases\Exceptions;

use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Vorgang wird gerade von einer anderen Person bearbeitet.
 */
final class CaseLockedException extends RuntimeException
{
    public function __construct(
        public readonly int $caseId,
        public readonly int $holderUserId,
        public readonly ?string $holderName,
        public readonly CarbonImmutable $expiresAt,
    ) {
        parent::__construct(sprintf('Vorgang %d wird von %s bearbeitet (Sperre bis %s UTC).', $caseId, $holderName ?? ('Nutzer '.$holderUserId), $expiresAt->format('d.m.Y H:i')));
    }
}
