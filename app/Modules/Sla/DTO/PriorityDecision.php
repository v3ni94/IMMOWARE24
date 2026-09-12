<?php

declare(strict_types=1);

namespace App\Modules\Sla\DTO;

use App\Modules\Cases\Enums\Priority;

/**
 * Ergebnis der regelbasierten Prioritätsvorstufe. emergency = Notfallverdacht (P0), reviewHint = Prüfhinweis an die
 * Teamleitung (verneinte oder historische Formulierung, Altmail mit Notfallmerkmalen).
 */
final class PriorityDecision
{
    public function __construct(
        public readonly Priority $priority,
        public readonly string $reason,
        public readonly ?string $matchedRule = null,
        public readonly bool $emergency = false,
        public readonly ?string $reviewHint = null,
        public readonly string $source = 'rule',
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'priority' => $this->priority->value,
            'reason' => $this->reason,
            'matched_rule' => $this->matchedRule,
            'emergency' => $this->emergency,
            'review_hint' => $this->reviewHint,
            'source' => $this->source,
        ];
    }
}
