<?php

declare(strict_types=1);

namespace Tests\Unit\Calendar;

use App\Modules\Calendar\ICal\ICalendarParser;
use PHPUnit\Framework\TestCase;

final class ICalendarParserTest extends TestCase
{
    private ICalendarParser $parser;

    protected function setUp(): void
    {
        $this->parser = new ICalendarParser;
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__.'/Fixtures/'.$name);
    }

    public function test_parses_event_with_tzid_attendees_rrule_and_ignores_valarm(): void
    {
        $event = $this->parser->parse($this->fixture('tzid.ics'));

        $this->assertNotNull($event);
        $this->assertSame('ev-1-tzid', $event->uid);
        $this->assertSame('Eigentümerversammlung WEG Hauptstraße 12', $event->summary);
        $this->assertFalse($event->allDay);
        $this->assertSame('Europe/Berlin', $event->timezone);
        $this->assertSame('2026-09-15T12:00:00+00:00', $event->startsAt?->toIso8601String(), 'Sommerzeit: 14:00 Berlin = 12:00 UTC');
        $this->assertSame('2026-09-15T13:30:00+00:00', $event->endsAt?->toIso8601String());
        $this->assertSame('Gemeinschaftsraum, Hauptstraße 12, Hilden', $event->location);
        $this->assertSame("Tagesordnung:\nTOP 1 Begrüßung\nTOP 2 Jahresabrechnung", $event->description);
        $this->assertCount(2, $event->attendees);
        $this->assertSame('timo@muellerhv.de', $event->attendees[0]['value']);
        $this->assertSame('Timo Müller', $event->attendees[0]['cn']);
        $this->assertSame('ACCEPTED', $event->attendees[0]['partstat']);
        $this->assertSame('CONFIRMED', $event->status);
        $this->assertSame(3, $event->sequence);
        $this->assertSame('2026-09-05T12:00:00+00:00', $event->lastModified?->toIso8601String());
        $this->assertSame('FREQ=YEARLY;BYMONTH=9;INTERVAL=1', $event->rrule);
        $this->assertArrayNotHasKey('TRIGGER', $event->extra, 'VALARM-Properties gehören nicht zum Event');
    }

    public function test_parses_all_day_event_without_dtend(): void
    {
        $event = $this->parser->parse($this->fixture('allday.ics'));

        $this->assertNotNull($event);
        $this->assertTrue($event->allDay);
        $this->assertSame('2026-10-03T00:00:00+00:00', $event->startsAt?->toIso8601String());
        $this->assertSame('2026-10-04T00:00:00+00:00', $event->endsAt?->toIso8601String());
        $this->assertSame('TENTATIVE', $event->status);
        $this->assertNull($event->rrule);
    }

    public function test_parses_utc_event_with_duration(): void
    {
        $event = $this->parser->parse($this->fixture('utc-duration.ics'));

        $this->assertNotNull($event);
        $this->assertSame('UTC', $event->timezone);
        $this->assertSame('2026-09-20T10:00:00+00:00', $event->startsAt?->toIso8601String());
        $this->assertSame('2026-09-20T10:45:00+00:00', $event->endsAt?->toIso8601String());
    }

    public function test_multiple_vevents_with_recurrence_id(): void
    {
        $text = "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:s\r\nDTSTART:20260901T090000Z\r\nRRULE:FREQ=WEEKLY\r\nEND:VEVENT\r\nBEGIN:VEVENT\r\nUID:s\r\nRECURRENCE-ID:20260908T090000Z\r\nDTSTART:20260908T100000Z\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

        $events = $this->parser->parseAll($text);

        $this->assertCount(2, $events);
        $this->assertNull($events[0]->recurrenceId);
        $this->assertSame('20260908T090000Z', $events[1]->recurrenceId);
    }
}
