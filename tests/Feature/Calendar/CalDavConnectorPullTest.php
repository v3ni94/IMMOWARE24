<?php

declare(strict_types=1);

namespace Tests\Feature\Calendar;

use App\Core\DTO\SyncRequest;
use App\Core\DTO\SyncResult;
use App\Core\Enums\SyncMode;
use App\Core\Exceptions\WriteBlockedException;
use App\Modules\Calendar\Models\CalendarEvent;
use App\Modules\Calendar\Services\CalDavConnector;
use App\Modules\Connector\Models\ImmowareConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Contacts\FakeDavServer;
use Tests\TestCase;

final class CalDavConnectorPullTest extends TestCase
{
    use RefreshDatabase;

    private const string PATH = '/caldav/calendars/hub-read/termine/';

    private FakeDavServer $server;

    private ImmowareConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->server = new FakeDavServer('dav.immoware.test', self::PATH, 'calendar-data');
        $this->connection = $this->createConnection(null, [
            'connector_type' => 'caldav_calendar',
            'base_url' => $this->server->url(),
            'base_url_hash' => hash('sha256', $this->server->url()),
            'status' => 'active',
            'rate_limit_rps' => 50,
            'last_health_ok' => true,
        ]);
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(base_path('tests/Unit/Calendar/Fixtures/'.$name));
    }

    private function pull(SyncMode $mode = SyncMode::Incremental): SyncResult
    {
        return $this->app->make(CalDavConnector::class)->pull(new SyncRequest((int) $this->connection->getKey(), 'calendar_event', $mode));
    }

    public function test_pull_mirrors_events_with_tzid_and_all_day(): void
    {
        $this->server->put(self::PATH.'e1.ics', 'a1', $this->fixture('tzid.ics'));
        $this->server->put(self::PATH.'e2.ics', 'a2', $this->fixture('allday.ics'));
        $this->server->install();

        $result = $this->pull();

        $this->assertSame(2, $result->created);
        $this->assertFalse($result->hasErrors());

        $event = CalendarEvent::query()->where('ical_uid', 'ev-1-tzid')->firstOrFail();
        $this->assertSame('2026-09-15 12:00:00', $event->starts_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-15 13:30:00', $event->ends_at?->format('Y-m-d H:i:s'));
        $this->assertFalse($event->all_day);
        $this->assertSame('Europe/Berlin', $event->timezone);
        $this->assertSame('FREQ=YEARLY;BYMONTH=9;INTERVAL=1', $event->recurrence_rule);
        $this->assertSame('3', $event->sequence);
        $this->assertSame('CONFIRMED', $event->status);
        $this->assertCount(2, $event->attendees);
        $this->assertSame('ev-1-tzid', $event->external_id);
        $this->assertSame('2026-09-05 12:00:00', $event->external_updated_at?->format('Y-m-d H:i:s'));

        $allDay = CalendarEvent::query()->where('ical_uid', 'ev-2-allday')->firstOrFail();
        $this->assertTrue($allDay->all_day);
        $this->assertSame('2026-10-03 00:00:00', $allDay->starts_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-04 00:00:00', $allDay->ends_at?->format('Y-m-d H:i:s'));

        Http::assertSent(fn (Request $r): bool => $r->method() === 'REPORT' && str_contains($r->body(), 'calendar-query') && str_contains($r->body(), 'VEVENT'));
    }

    public function test_unchanged_ctag_skips_and_sweep_soft_deletes(): void
    {
        $this->server->put(self::PATH.'e1.ics', 'a1', $this->fixture('tzid.ics'));
        $this->server->put(self::PATH.'e3.ics', 'a3', $this->fixture('utc-duration.ics'));
        $this->server->install();
        $this->pull();

        Http::fake();
        $this->server->install();
        $this->pull();
        Http::assertSentCount(1);

        // Erstes Fehlen: nur missing_since; Soft Delete erst im zweiten gesunden Lauf (07 Abschnitt 4).
        unset($this->server->resources[self::PATH.'e3.ics']);
        $this->server->ctag = 'ctag-2';
        Http::fake();
        $this->server->install();
        $first = $this->pull();
        $this->assertSame(0, $first->deleted);
        $this->assertSame(2, CalendarEvent::query()->count());
        $this->assertNotNull(CalendarEvent::query()->where('ical_uid', 'ev-3-utc')->firstOrFail()->missing_since);

        $this->server->ctag = 'ctag-3';
        Http::fake();
        $this->server->install();
        $result = $this->pull();

        $this->assertSame(1, $result->deleted);
        $this->assertSame(1, CalendarEvent::query()->count());
        $this->assertSame(2, CalendarEvent::query()->withTrashed()->count());
        $this->assertNotNull(CalendarEvent::query()->withTrashed()->where('ical_uid', 'ev-3-utc')->firstOrFail()->deleted_at);
    }

    public function test_recurrence_exceptions_become_separate_events(): void
    {
        $text = "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:s\r\nDTSTART:20260901T090000Z\r\nSUMMARY:Serie\r\nRRULE:FREQ=WEEKLY\r\nEND:VEVENT\r\nBEGIN:VEVENT\r\nUID:s\r\nRECURRENCE-ID:20260908T090000Z\r\nDTSTART:20260908T100000Z\r\nSUMMARY:Ausnahme\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        $this->server->put(self::PATH.'s.ics', 's1', $text);
        $this->server->install();

        $result = $this->pull();

        $this->assertSame(2, $result->created);
        $this->assertSame(['s', 's#20260908T090000Z'], CalendarEvent::query()->orderBy('id')->pluck('external_id')->all());
    }

    public function test_push_is_blocked(): void
    {
        $this->expectException(WriteBlockedException::class);

        $this->app->make(CalDavConnector::class)->push(new SyncRequest((int) $this->connection->getKey(), 'calendar_event', SyncMode::Incremental));
    }
}
