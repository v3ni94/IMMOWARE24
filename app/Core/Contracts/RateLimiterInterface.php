<?php

declare(strict_types=1);

namespace App\Core\Contracts;

use App\Core\Exceptions\RateLimitedException;

interface RateLimiterInterface
{
    /**
     * Reserviert einen Request-Slot für den angegebenen Schlüssel (z. B. Connection oder Host).
     * Blockiert bis zum Slot oder wirft nach Ablauf der Wartezeit.
     *
     * @throws RateLimitedException
     */
    public function acquire(string $key, int $maxWaitMs = 5000): void;

    public function tryAcquire(string $key): bool;

    /**
     * Anzahl der aktuell freien Slots für den Schlüssel.
     */
    public function remaining(string $key): int;

    public function reset(string $key): void;
}
