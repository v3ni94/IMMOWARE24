<?php

declare(strict_types=1);

namespace App\Modules\Cases\DTO;

use App\Modules\Cases\Enums\Priority;
use Carbon\CarbonImmutable;

/**
 * Vorgabe für ein Teilanliegen beim Anlegen eines Vorgangs. Fehlende Priorität wird regelbasiert bestimmt,
 * fehlende Fälligkeit aus der Lösungsuhr abgeleitet.
 */
final class CaseItemSpec
{
    public function __construct(
        public readonly string $itemType,
        public readonly string $title,
        public readonly ?string $description = null,
        public readonly ?Priority $priority = null,
        public readonly ?int $assigneeUserId = null,
        public readonly ?CarbonImmutable $dueAt = null,
        public readonly ?string $nextStep = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $priority = $data['priority'] ?? null;
        $due = $data['due_at'] ?? null;

        return new self(
            itemType: (string) ($data['item_type'] ?? 'sonstiges'),
            title: (string) ($data['title'] ?? 'Teilanliegen'),
            description: isset($data['description']) ? (string) $data['description'] : null,
            priority: $priority instanceof Priority ? $priority : (is_string($priority) ? Priority::tryFrom($priority) : null),
            assigneeUserId: isset($data['assignee_user_id']) ? (int) $data['assignee_user_id'] : null,
            dueAt: $due instanceof CarbonImmutable ? $due : (is_string($due) ? CarbonImmutable::parse($due) : null),
            nextStep: isset($data['next_step']) ? (string) $data['next_step'] : null,
        );
    }
}
