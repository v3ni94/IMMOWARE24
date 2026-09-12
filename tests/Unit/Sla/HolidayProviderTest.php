<?php

declare(strict_types=1);

namespace Tests\Unit\Sla;

use App\Modules\Sla\Calendar\HolidayProvider;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class HolidayProviderTest extends TestCase
{
    public function test_easter_and_dependent_holidays_2026(): void
    {
        $this->assertSame('2026-04-05', HolidayProvider::easterSunday(2026)->toDateString());
        $this->assertSame('2025-04-20', HolidayProvider::easterSunday(2025)->toDateString());

        $provider = new HolidayProvider('NW');
        $holidays = $provider->forYear(2026);

        $this->assertSame('Karfreitag', $holidays['2026-04-03']);
        $this->assertSame('Ostermontag', $holidays['2026-04-06']);
        $this->assertSame('Christi Himmelfahrt', $holidays['2026-05-14']);
        $this->assertSame('Pfingstmontag', $holidays['2026-05-25']);
        $this->assertSame('Fronleichnam', $holidays['2026-06-04']);
        $this->assertSame('Allerheiligen', $holidays['2026-11-01']);
        $this->assertArrayNotHasKey('2026-10-31', $holidays, 'Reformationstag ist in NRW kein Feiertag.');
        $this->assertArrayNotHasKey('2026-01-06', $holidays);
    }

    public function test_additional_days_are_configurable(): void
    {
        $provider = (new HolidayProvider('NW'))->withAdditional(['2026-12-24']);

        $this->assertTrue($provider->isHoliday(CarbonImmutable::parse('2026-12-24', 'Europe/Berlin')));
        $this->assertFalse((new HolidayProvider('NW'))->isHoliday(CarbonImmutable::parse('2026-12-24', 'Europe/Berlin')));
    }
}
