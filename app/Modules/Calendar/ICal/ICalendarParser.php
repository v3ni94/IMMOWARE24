<?php

declare(strict_types=1);

namespace App\Modules\Calendar\ICal;

use App\Modules\Contacts\VCard\ContentLine;
use App\Modules\Contacts\VCard\ContentLineReader;
use Carbon\CarbonImmutable;
use DateInterval;
use DateTimeZone;
use Throwable;

/**
 * Eigener iCalendar-Parser (RFC 5545) für VCALENDAR/VEVENT. Nur Lesen.
 * DTSTART/DTEND als DATE (ganztägig) oder DATE-TIME (lokal mit TZID, UTC mit Z, floating als UTC).
 * VTIMEZONE-Definitionen werden nicht ausgewertet; TZID wird gegen die PHP-Zeitzonendatenbank aufgelöst.
 */
final class ICalendarParser
{
    private const array KNOWN = ['BEGIN', 'END', 'UID', 'SUMMARY', 'DTSTART', 'DTEND', 'DURATION', 'DESCRIPTION', 'LOCATION', 'ATTENDEE', 'STATUS', 'LAST-MODIFIED', 'SEQUENCE', 'RRULE', 'RECURRENCE-ID', 'DTSTAMP', 'CREATED', 'ORGANIZER', 'CLASS', 'TRANSP', 'PRIORITY', 'URL', 'CATEGORIES', 'EXDATE', 'RDATE', 'GEO'];

    public function __construct(private readonly ContentLineReader $reader = new ContentLineReader) {}

    /**
     * Alle VEVENT-Komponenten des Textes (auch mehrere je VCALENDAR, z. B. Ausnahmen einer Serie).
     *
     * @return array<int, ICalEvent>
     */
    public function parseAll(string $text): array
    {
        $events = [];
        $depth = 0;
        $current = null;

        foreach ($this->reader->read($text) as $line) {
            if ($line->name === 'BEGIN') {
                $component = strtoupper(trim($line->rawValue));

                if ($component === 'VEVENT' && $current === null) {
                    $current = [];
                    $depth = 0;

                    continue;
                }

                if ($current !== null) {
                    $depth++; // z. B. VALARM innerhalb des VEVENT: ignorieren
                }

                continue;
            }

            if ($line->name === 'END') {
                $component = strtoupper(trim($line->rawValue));

                if ($current !== null && $depth > 0) {
                    $depth--;

                    continue;
                }

                if ($component === 'VEVENT' && $current !== null) {
                    $events[] = $this->build($current);
                    $current = null;
                }

                continue;
            }

            if ($current !== null && $depth === 0) {
                $current[] = $line;
            }
        }

        return $events;
    }

    public function parse(string $text): ?ICalEvent
    {
        return $this->parseAll($text)[0] ?? null;
    }

    /**
     * @param  array<int, ContentLine>  $lines
     */
    private function build(array $lines): ICalEvent
    {
        $uid = null;
        $recurrenceId = null;
        $summary = null;
        $start = null;
        $end = null;
        $duration = null;
        $allDay = false;
        $timezone = null;
        $description = null;
        $location = null;
        $attendees = [];
        $status = null;
        $lastModified = null;
        $sequence = null;
        $rrule = null;
        $extra = [];

        foreach ($lines as $line) {
            switch ($line->name) {
                case 'UID':
                    $uid = $this->nullIfEmpty(trim($line->value()));
                    break;
                case 'RECURRENCE-ID':
                    $recurrenceId = $this->nullIfEmpty(trim($line->rawValue));
                    break;
                case 'SUMMARY':
                    $summary = $this->nullIfEmpty(trim($line->value()));
                    break;
                case 'DTSTART':
                    [$start, $isDate, $tz] = $this->parseDateTime($line);
                    $allDay = $isDate;
                    $timezone = $tz ?? $timezone;
                    break;
                case 'DTEND':
                    [$end, , $tz] = $this->parseDateTime($line);
                    $timezone ??= $tz;
                    break;
                case 'DURATION':
                    $duration = trim($line->rawValue);
                    break;
                case 'DESCRIPTION':
                    $description = $this->nullIfEmpty(trim($line->value()));
                    break;
                case 'LOCATION':
                    $location = $this->nullIfEmpty(trim($line->value()));
                    break;
                case 'ATTENDEE':
                    $value = trim($line->value());
                    if (str_starts_with(strtolower($value), 'mailto:')) {
                        $value = substr($value, 7);
                    }
                    $attendees[] = [
                        'value' => $value,
                        'cn' => $line->firstParameter('CN'),
                        'partstat' => $line->firstParameter('PARTSTAT'),
                        'role' => $line->firstParameter('ROLE'),
                    ];
                    break;
                case 'STATUS':
                    $status = $this->nullIfEmpty(strtoupper(trim($line->value())));
                    break;
                case 'LAST-MODIFIED':
                    [$lastModified] = $this->parseDateTime($line);
                    break;
                case 'SEQUENCE':
                    $sequence = is_numeric(trim($line->rawValue)) ? (int) trim($line->rawValue) : null;
                    break;
                case 'RRULE':
                    $rrule = $this->nullIfEmpty(trim($line->rawValue));
                    break;
                default:
                    if (! in_array($line->name, self::KNOWN, true)) {
                        $extra[$line->name][] = $line->value();
                    }
            }
        }

        if ($end === null && $start !== null) {
            $end = $this->endFromDuration($start, $duration, $allDay);
        }

        return new ICalEvent(
            uid: $uid,
            recurrenceId: $recurrenceId,
            summary: $summary,
            startsAt: $start,
            endsAt: $end,
            allDay: $allDay,
            timezone: $timezone,
            description: $description,
            location: $location,
            attendees: $attendees,
            status: $status,
            lastModified: $lastModified,
            sequence: $sequence,
            rrule: $rrule,
            extra: $extra,
        );
    }

    /**
     * @return array{0: CarbonImmutable|null, 1: bool, 2: string|null}
     */
    private function parseDateTime(ContentLine $line): array
    {
        $raw = trim($line->rawValue);
        $isDate = strtoupper($line->firstParameter('VALUE') ?? '') === 'DATE' || preg_match('/^\d{8}$/', $raw) === 1;
        $tzid = $line->firstParameter('TZID');
        $zone = $this->resolveTimezone($tzid);

        try {
            if ($isDate) {
                if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $raw, $m) !== 1) {
                    return [null, true, $zone?->getName()];
                }

                return [CarbonImmutable::create((int) $m[1], (int) $m[2], (int) $m[3], 0, 0, 0, 'UTC'), true, $zone?->getName()];
            }

            if (preg_match('/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})?(Z?)$/', $raw, $m) !== 1) {
                return [null, false, $zone?->getName()];
            }

            $utc = $m[7] === 'Z';
            $tz = $utc ? new DateTimeZone('UTC') : ($zone ?? new DateTimeZone('UTC'));
            $date = CarbonImmutable::create((int) $m[1], (int) $m[2], (int) $m[3], (int) $m[4], (int) $m[5], (int) ($m[6] ?: 0), $tz);

            return [$date->utc(), false, $utc ? 'UTC' : $zone?->getName()];
        } catch (Throwable) {
            return [null, $isDate, $zone?->getName()];
        }
    }

    private function resolveTimezone(?string $tzid): ?DateTimeZone
    {
        if ($tzid === null || $tzid === '') {
            return null;
        }

        $tzid = ltrim($tzid, '/');

        try {
            return new DateTimeZone($tzid);
        } catch (Throwable) {
            return null;
        }
    }

    private function endFromDuration(CarbonImmutable $start, ?string $duration, bool $allDay): CarbonImmutable
    {
        if ($duration !== null) {
            try {
                $negative = str_starts_with($duration, '-');
                $interval = new DateInterval(ltrim($duration, '+-'));

                return $negative ? $start->sub($interval) : $start->add($interval);
            } catch (Throwable) {
                // ungültige Dauer: unten Standard verwenden
            }
        }

        return $allDay ? $start->addDay() : $start;
    }

    private function nullIfEmpty(string $value): ?string
    {
        return $value === '' ? null : $value;
    }
}
