<?php

declare(strict_types=1);

namespace App\Modules\Imports\Services;

use App\Modules\Imports\Exceptions\NotImplementedException;

/**
 * MT940 (SWIFT-Kontoauszug) ist NICHT implementiert. Kontoauszüge werden ausschließlich als camt.053 verarbeitet.
 * Der Stub existiert, damit ein versehentlicher Aufruf mit klarer Meldung scheitert statt stillschweigend zu raten.
 */
final class Mt940Parser
{
    /**
     * @return never
     */
    public function parse(string $content): array
    {
        throw new NotImplementedException('MT940 wird nicht unterstützt. Bitte den Kontoauszug als camt.053 (ISO 20022) exportieren.');
    }
}
