<?php

declare(strict_types=1);

namespace App\Modules\Cases\DTO;

/**
 * Zuordnungskandidat mit Quelle, Grund und Konfidenz (0 bis 100). Nie über Namen ermittelt.
 */
final class AssignmentCandidate
{
    public function __construct(
        public readonly string $type,
        public readonly int $localId,
        public readonly string $source,
        public readonly string $reason,
        public readonly int $confidence,
        public readonly ?string $label = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'local_id' => $this->localId,
            'source' => $this->source,
            'reason' => $this->reason,
            'confidence' => $this->confidence,
            'label' => $this->label,
        ];
    }
}
