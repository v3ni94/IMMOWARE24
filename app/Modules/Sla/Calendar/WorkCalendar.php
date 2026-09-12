<?php

declare(strict_types=1);

namespace App\Modules\Sla\Calendar;

use App\Modules\Sla\Models\WorkCalendar as WorkCalendarModel;
use Carbon\CarbonImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Arbeitskalender: Intervalle je Wochentag in lokaler Zeit (Europe/Berlin), Feiertage, Sommerzeit über DateTimeZone.
 * Alle Ein- und Ausgaben sind CarbonImmutable in UTC; die Rechnung erfolgt intern in der Kalenderzeitzone.
 */
final class WorkCalendar
{
    private const array DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    private readonly DateTimeZone $zone;

    /** @var array<string, array<int, array{0: int, 1: int}>> Minuten seit Mitternacht je Wochentag */
    private array $intervals = [];

    /**
     * @param  array<string, array<int, array{0: string, 1: string}>>  $weeklyHours
     */
    public function __construct(
        array $weeklyHours,
        private readonly HolidayProvider $holidays,
        string $timezone = 'Europe/Berlin',
    ) {
        $this->zone = new DateTimeZone($timezone);

        foreach (self::DAYS as $day) {
            $this->intervals[$day] = [];

            foreach ($weeklyHours[$day] ?? [] as $interval) {
                $start = self::minutesOfDay((string) $interval[0]);
                $end = self::minutesOfDay((string) $interval[1]);

                if ($end <= $start) {
                    throw new InvalidArgumentException(sprintf('Arbeitszeitintervall %s bis %s am %s ist ungültig.', $interval[0], $interval[1], $day));
                }

                $this->intervals[$day][] = [$start, $end];
            }

            usort($this->intervals[$day], static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        }
    }

    /**
     * @param  array<string, mixed>  $config  hub.sla
     */
    public static function fromConfig(array $config): self
    {
        $additional = array_values(array_map('strval', (array) ($config['additional_holidays'] ?? [])));

        return new self(
            (array) ($config['weekly_hours'] ?? []),
            new HolidayProvider((string) ($config['holiday_region'] ?? 'NW'), $additional),
            (string) ($config['timezone'] ?? 'Europe/Berlin'),
        );
    }

    /**
     * Kalender aus mail_work_calendars mit den dort hinterlegten Feiertagen; fehlende Wochenstunden aus Config.
     *
     * @param  array<string, mixed>  $config
     */
    public static function fromModel(WorkCalendarModel $model, array $config): self
    {
        $weekly = $model->weekly_hours_json;
        $dates = $model->holidays()->pluck('holiday_date')->map(static fn (mixed $d): string => CarbonImmutable::parse((string) $d)->toDateString())->all();

        return new self(
            is_array($weekly) && $weekly !== [] ? $weekly : (array) ($config['weekly_hours'] ?? []),
            new HolidayProvider((string) ($config['holiday_region'] ?? 'NW'), array_merge(array_values(array_map('strval', (array) ($config['additional_holidays'] ?? []))), $dates)),
            (string) ($model->timezone ?: ($config['timezone'] ?? 'Europe/Berlin')),
        );
    }

    public function timezone(): DateTimeZone
    {
        return $this->zone;
    }

    public function holidays(): HolidayProvider
    {
        return $this->holidays;
    }

    public function isWorkingDay(CarbonImmutable $utc): bool
    {
        $local = $utc->setTimezone($this->zone);

        return $this->intervals[self::dayKey($local)] !== [] && ! $this->holidays->isHoliday($local);
    }

    /**
     * Arbeitsintervalle eines lokalen Tages als UTC-Paare.
     *
     * @return array<int, array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    public function intervalsOfDay(CarbonImmutable $localDay): array
    {
        $day = $localDay->setTimezone($this->zone)->startOfDay();

        if ($this->holidays->isHoliday($day)) {
            return [];
        }

        $result = [];

        foreach ($this->intervals[self::dayKey($day)] as [$start, $end]) {
            $result[] = [
                $day->addMinutes($start)->setTimezone('UTC'),
                $day->addMinutes($end)->setTimezone('UTC'),
            ];
        }

        return $result;
    }

    public function isWithinWorkingHours(CarbonImmutable $utc): bool
    {
        $local = $utc->setTimezone($this->zone);

        foreach ($this->intervalsOfDay($local) as [$start, $end]) {
            if ($utc->greaterThanOrEqualTo($start) && $utc->lessThan($end)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Nächster Zeitpunkt innerhalb der Arbeitszeit (der Zeitpunkt selbst, falls er bereits darin liegt).
     */
    public function nextWorkingMoment(CarbonImmutable $utc): CarbonImmutable
    {
        $cursor = $utc;

        for ($i = 0; $i < 400; $i++) {
            $local = $cursor->setTimezone($this->zone);

            foreach ($this->intervalsOfDay($local) as [$start, $end]) {
                if ($cursor->lessThan($start)) {
                    return $start;
                }

                if ($cursor->lessThan($end)) {
                    return $cursor;
                }
            }

            $cursor = $local->addDay()->startOfDay()->setTimezone('UTC');
        }

        throw new InvalidArgumentException('Kein Arbeitstag innerhalb von 400 Tagen gefunden; Arbeitskalender prüfen.');
    }

    private static function dayKey(CarbonImmutable $local): string
    {
        return self::DAYS[$local->dayOfWeekIso - 1];
    }

    private static function minutesOfDay(string $time): int
    {
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $time, $m) !== 1) {
            throw new InvalidArgumentException(sprintf('Uhrzeit %s ist ungültig (HH:MM erwartet).', $time));
        }

        return ((int) $m[1]) * 60 + (int) $m[2];
    }
}
