<?php

declare(strict_types=1);

namespace App\Modules\Sla\Calendar;

use Carbon\CarbonImmutable;

/**
 * Gesetzliche Feiertage Nordrhein-Westfalen, berechnet aus dem Osterdatum (Gauß-Algorithmus, keine Erweiterung nötig).
 * Zusätzliche Tage (Betriebsferien) kommen aus hub.sla.additional_holidays oder mail_holidays.
 * Kein Feiertag: Heilige Drei Könige, Reformationstag, Buß- und Bettag, Mariä Himmelfahrt (nicht in NRW).
 */
final class HolidayProvider
{
    /** @var array<int, array<string, string>> */
    private array $cache = [];

    /**
     * @param  array<int, string>  $additional  JJJJ-MM-TT
     */
    public function __construct(
        private readonly string $region = 'NW',
        private array $additional = [],
    ) {}

    /**
     * @param  array<int, string>  $dates
     */
    public function withAdditional(array $dates): self
    {
        $clone = clone $this;
        $clone->additional = array_values(array_unique(array_merge($this->additional, $dates)));
        $clone->cache = [];

        return $clone;
    }

    public function isHoliday(CarbonImmutable $day): bool
    {
        return isset($this->forYear($day->year)[$day->toDateString()]);
    }

    public function label(CarbonImmutable $day): ?string
    {
        return $this->forYear($day->year)[$day->toDateString()] ?? null;
    }

    /**
     * @return array<string, string> Datum JJJJ-MM-TT => Bezeichnung
     */
    public function forYear(int $year): array
    {
        if (isset($this->cache[$year])) {
            return $this->cache[$year];
        }

        $easter = self::easterSunday($year);
        $fixed = [
            sprintf('%04d-01-01', $year) => 'Neujahr',
            sprintf('%04d-05-01', $year) => 'Tag der Arbeit',
            sprintf('%04d-10-03', $year) => 'Tag der Deutschen Einheit',
            sprintf('%04d-12-25', $year) => '1. Weihnachtstag',
            sprintf('%04d-12-26', $year) => '2. Weihnachtstag',
        ];
        $movable = [
            $easter->subDays(2)->toDateString() => 'Karfreitag',
            $easter->addDay()->toDateString() => 'Ostermontag',
            $easter->addDays(39)->toDateString() => 'Christi Himmelfahrt',
            $easter->addDays(50)->toDateString() => 'Pfingstmontag',
        ];

        if ($this->region === 'NW') {
            $movable[$easter->addDays(60)->toDateString()] = 'Fronleichnam';
            $fixed[sprintf('%04d-11-01', $year)] = 'Allerheiligen';
        }

        $all = $fixed + $movable;

        foreach ($this->additional as $date) {
            if (str_starts_with($date, sprintf('%04d-', $year))) {
                $all[$date] = 'Zusätzlicher freier Tag';
            }
        }

        ksort($all);

        return $this->cache[$year] = $all;
    }

    /**
     * Ostersonntag nach Gauß (gregorianisch).
     */
    public static function easterSunday(int $year): CarbonImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return CarbonImmutable::create($year, $month, $day, 0, 0, 0, 'UTC');
    }
}
