<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Mapping;

use App\Core\Contracts\FieldMapperInterface;
use App\Core\Exceptions\WriteBlockedException;
use App\Modules\Calendar\ICal\ICalEvent;

/**
 * FieldMapping iCalendar VEVENT nach calendar_events, Version 1.
 * external_id = UID, bei Serienausnahme UID#RECURRENCE-ID (07-sync-strategy.md Abschnitt 3), Fallback href.
 */
final class ICalEventMapper implements FieldMapperInterface
{
    public const string SOURCE_FORMAT = 'ical';

    public const int VERSION = 1;

    public function entityType(): string
    {
        return 'calendar_event';
    }

    public function version(): int
    {
        return self::VERSION;
    }

    /**
     * @param  array<string, mixed>  $external
     * @return array<string, mixed>
     */
    public function toLocal(array $external): array
    {
        $event = $this->event($external);

        return [
            'uid' => $event->uid,
            'recurrence_id' => $event->recurrenceId,
            'summary' => $event->summary,
            'starts_at' => $event->startsAt?->toIso8601String(),
            'ends_at' => $event->endsAt?->toIso8601String(),
            'all_day' => $event->allDay,
            'timezone' => $event->timezone,
            'location' => $event->location,
            'description' => $event->description,
            'attendees' => $event->attendees,
            'status' => $event->status,
            'sequence' => $event->sequence,
            'rrule' => $event->rrule,
            'last_modified' => $event->lastModified?->toIso8601String(),
            'external_id' => $this->externalId($external),
            'uid_missing' => ! $event->hasUid(),
            'href' => (string) ($external['href'] ?? ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $local
     * @return array<string, mixed>
     */
    public function toExternal(array $local): array
    {
        throw new WriteBlockedException('CalDAV ist ausschließlich lesend, es gibt keine Abbildung nach iCalendar.', 'caldav.write');
    }

    /**
     * @param  array<string, mixed>  $external
     */
    public function externalId(array $external): string
    {
        $event = $this->event($external);
        $href = (string) ($external['href'] ?? '');

        $base = $event->hasUid() ? (string) $event->uid : ($href !== '' ? 'href:'.$href : null);

        if ($base === null) {
            throw new \InvalidArgumentException('VEVENT ohne UID und ohne href kann nicht zugeordnet werden.');
        }

        return $event->recurrenceId !== null ? $base.'#'.$event->recurrenceId : $base;
    }

    /**
     * @param  array<string, mixed>  $local
     * @return array<string, mixed>
     */
    public static function checksumPayload(array $local): array
    {
        unset($local['href'], $local['last_modified'], $local['sequence']);

        return $local;
    }

    /**
     * @param  array<string, mixed>  $external
     */
    private function event(array $external): ICalEvent
    {
        $event = $external['event'] ?? null;

        if (! $event instanceof ICalEvent) {
            throw new \InvalidArgumentException('Erwartet wird ein ICalEvent-Objekt unter dem Schlüssel event.');
        }

        return $event;
    }
}
