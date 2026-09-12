<?php

declare(strict_types=1);

namespace App\Modules\Sla\DTO;

use App\Modules\Sla\Enums\SlaColor;
use Carbon\CarbonImmutable;

/**
 * Ampel B eines Vorgangs oder Teilanliegens: schlimmste fällige Verpflichtung mit Ursache-Text.
 */
final class TrafficLight
{
    public function __construct(
        public readonly SlaColor $color,
        public readonly string $cause,
        public readonly ?string $clockType = null,
        public readonly ?int $caseItemId = null,
        public readonly ?CarbonImmutable $targetAt = null,
    ) {}

    public static function green(string $cause = 'Alle Verpflichtungen im Zielkorridor.'): self
    {
        return new self(SlaColor::Green, $cause);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'color' => $this->color->value,
            'cause' => $this->cause,
            'clock_type' => $this->clockType,
            'case_item_id' => $this->caseItemId,
            'target_at' => $this->targetAt?->toIso8601String(),
        ];
    }
}
