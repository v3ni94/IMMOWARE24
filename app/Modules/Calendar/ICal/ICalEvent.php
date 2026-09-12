<?php

declare(strict_types=1);

namespace App\Modules\Calendar\ICal;

use Carbon\CarbonImmutable;

/**
 * Normalisiertes VEVENT aus einer iCalendar-Datei. Zeiten in UTC, RRULE nur als Rohstring.
 */
final readonly class ICalEvent
{
    /**
     * @param  array<int, array{value: string, cn: string|null, partstat: string|null, role: string|null}>  $attendees
     * @param  array<string, array<int, string>>  $extra
     */
    public function __construct(
        public ?string $uid,
        public ?string $recurrenceId,
        public ?string $summary,
        public ?CarbonImmutable $startsAt,
        public ?CarbonImmutable $endsAt,
        public bool $allDay,
        public ?string $timezone,
        public ?string $description,
        public ?string $location,
        public array $attendees,
        public ?string $status,
        public ?CarbonImmutable $lastModified,
        public ?int $sequence,
        public ?string $rrule,
        public array $extra = [],
    ) {}

    public function hasUid(): bool
    {
        return $this->uid !== null && $this->uid !== '';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'uid' => $this->uid,
            'recurrence_id' => $this->recurrenceId,
            'summary' => $this->summary,
            'starts_at' => $this->startsAt?->toIso8601String(),
            'ends_at' => $this->endsAt?->toIso8601String(),
            'all_day' => $this->allDay,
            'timezone' => $this->timezone,
            'description' => $this->description,
            'location' => $this->location,
            'attendees' => $this->attendees,
            'status' => $this->status,
            'last_modified' => $this->lastModified?->toIso8601String(),
            'sequence' => $this->sequence,
            'rrule' => $this->rrule,
            'extra' => $this->extra,
        ];
    }
}
