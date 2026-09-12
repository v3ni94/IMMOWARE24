<?php

declare(strict_types=1);

namespace App\Modules\Sync\Services\Replay;

/**
 * Ergebnis der Wiederverarbeitung einer archivierten Nutzlast.
 */
final readonly class ReplayOutcome
{
    /**
     * @param  array<int, string>  $externalIds  Externe IDs der aus der Nutzlast abgeleiteten Datensätze
     */
    public function __construct(
        public int $created = 0,
        public int $updated = 0,
        public int $unchanged = 0,
        public array $externalIds = [],
    ) {}

    public function merge(self $other): self
    {
        return new self(
            created: $this->created + $other->created,
            updated: $this->updated + $other->updated,
            unchanged: $this->unchanged + $other->unchanged,
            externalIds: array_values(array_unique([...$this->externalIds, ...$other->externalIds])),
        );
    }
}
