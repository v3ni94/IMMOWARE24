<?php

declare(strict_types=1);

namespace Tests\Unit\Sla;

use App\Modules\Sla\Calendar\BusinessTime;
use App\Modules\Sla\Calendar\HolidayProvider;
use App\Modules\Sla\Calendar\WorkCalendar;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Abnahmefall 13: Fristen über Wochenende, Feiertag, Sommerzeitwechsel und für Altmails.
 */
final class BusinessTimeTest extends TestCase
{
    private BusinessTime $time;

    protected function setUp(): void
    {
        parent::setUp();

        $hours = ['08:00', '16:30'];
        $calendar = new WorkCalendar(
            ['mon' => [$hours], 'tue' => [$hours], 'wed' => [$hours], 'thu' => [$hours], 'fri' => [$hours], 'sat' => [], 'sun' => []],
            new HolidayProvider('NW'),
            'Europe/Berlin',
        );
        $this->time = new BusinessTime($calendar, 510);
    }

    public function test_four_hours_from_friday_afternoon_land_on_monday(): void
    {
        // Freitag 11.09.2026 15:00 Berlin: 1,5 h bleiben, 2,5 h am Montag ab 08:00.
        $start = CarbonImmutable::parse('2026-09-11 15:00', 'Europe/Berlin');

        $this->assertSame('2026-09-14 10:30', $this->time->addHours($start, 4)->setTimezone('Europe/Berlin')->format('Y-m-d H:i'));
    }

    public function test_weekend_start_moves_to_monday_morning(): void
    {
        $start = CarbonImmutable::parse('2026-09-12 22:15', 'Europe/Berlin');

        $this->assertSame('2026-09-14 09:00', $this->time->addMinutes($start, 60)->setTimezone('Europe/Berlin')->format('Y-m-d H:i'));
    }

    public function test_holiday_is_skipped(): void
    {
        // Donnerstag 30.04.2026 16:00, Freitag 01.05. Feiertag, Rest am Montag 04.05.
        $start = CarbonImmutable::parse('2026-04-30 16:00', 'Europe/Berlin');

        $this->assertSame('2026-05-04 09:30', $this->time->addHours($start, 2)->setTimezone('Europe/Berlin')->format('Y-m-d H:i'));
    }

    public function test_two_work_days_over_easter(): void
    {
        // Donnerstag 02.04.2026 08:00, Karfreitag und Ostermontag frei: 2 volle Arbeitstage enden Dienstag 07.04. 16:30.
        $start = CarbonImmutable::parse('2026-04-02 08:00', 'Europe/Berlin');

        $this->assertSame('2026-04-07 16:30', $this->time->addDays($start, 2)->setTimezone('Europe/Berlin')->format('Y-m-d H:i'));
    }

    public function test_daylight_saving_switch_is_handled_in_local_time(): void
    {
        // Freitag 27.03.2026 16:00 MEZ (15:00 UTC); Umstellung 29.03.; Montag 30.03. 08:30 MESZ = 06:30 UTC.
        $start = CarbonImmutable::parse('2026-03-27 16:00', 'Europe/Berlin');
        $result = $this->time->addHours($start, 1);

        $this->assertSame('2026-03-30 08:30', $result->setTimezone('Europe/Berlin')->format('Y-m-d H:i'));
        $this->assertSame('2026-03-30 06:30', $result->utc()->format('Y-m-d H:i'));
        $this->assertSame('2026-03-27 15:00', $start->utc()->format('Y-m-d H:i'));
    }

    public function test_elapsed_business_minutes_and_old_mail(): void
    {
        // Altmail: Empfang Montag 07.09.2026 09:00, Bewertung Mittwoch 09.09. 09:00 = 2 Arbeitstage = 1020 Minuten.
        $received = CarbonImmutable::parse('2026-09-07 09:00', 'Europe/Berlin');
        $now = CarbonImmutable::parse('2026-09-09 09:00', 'Europe/Berlin');

        $this->assertSame(1020, $this->time->elapsedMinutes($received, $now));
        $this->assertSame(0, $this->time->elapsedMinutes($now, $received));
        // Ziel 4 Arbeitsstunden ab Empfang liegt in der Vergangenheit: Frist überschritten, Import verjüngt nicht.
        $this->assertTrue($this->time->addHours($received, 4)->lessThan($now));
    }

    public function test_invalid_interval_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new WorkCalendar(['mon' => [['16:00', '08:00']]], new HolidayProvider('NW'));
    }
}
