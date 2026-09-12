<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Support\GermanDate;
use App\Core\Support\Money;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class FormattersTest extends TestCase
{
    public function test_money_formats_cents_in_german_notation(): void
    {
        $this->assertSame('1.234,56 EUR', Money::format(123456));
        $this->assertSame('0,05 EUR', Money::format(5));
        $this->assertSame('-12,00 EUR', Money::format(-1200));
        $this->assertSame('1.000.000,00 EUR', Money::format(100000000));
    }

    public function test_money_parses_german_and_english_inputs(): void
    {
        $this->assertSame(123456, Money::parseToCents('1.234,56 EUR'));
        $this->assertSame(123456, Money::parseToCents('1234.56'));
        $this->assertSame(-1250, Money::parseToCents('-12,5'));
        $this->assertNull(Money::parseToCents('abc'));
    }

    public function test_german_date_formats_and_parses(): void
    {
        $date = CarbonImmutable::create(2026, 9, 12, 15, 30, 0, 'UTC');

        $this->assertSame('12.09.2026', GermanDate::format($date));
        $this->assertSame('12.09.2026 17:30', GermanDate::formatDateTime($date));
        $this->assertSame('2026-09-12', GermanDate::parse('12.09.2026')?->toDateString());
        $this->assertNull(GermanDate::parse('31.02.2026'));
        $this->assertNull(GermanDate::format(null));
    }
}
