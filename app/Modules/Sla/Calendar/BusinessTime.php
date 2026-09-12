<?php

declare(strict_types=1);

namespace App\Modules\Sla\Calendar;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Arbeitszeitrechnung auf Basis eines WorkCalendar. Start außerhalb der Arbeitszeit wird auf den nächsten
 * Arbeitsbeginn verschoben, dann werden Arbeitsminuten intervallweise addiert (Wochenende, Feiertag, Sommerzeit).
 */
final class BusinessTime
{
    public function __construct(
        private readonly WorkCalendar $calendar,
        private readonly int $workDayMinutes = 510,
    ) {}

    public function calendar(): WorkCalendar
    {
        return $this->calendar;
    }

    /**
     * Addiert Arbeitszeit. $unit: minutes, hours oder days (Arbeitstage à workDayMinutes).
     */
    public function add(CarbonImmutable $start, int|float $amount, string $unit = 'minutes'): CarbonImmutable
    {
        return $this->addMinutes($start, $this->toMinutes($amount, $unit));
    }

    public function addHours(CarbonImmutable $start, int|float $hours): CarbonImmutable
    {
        return $this->add($start, $hours, 'hours');
    }

    public function addDays(CarbonImmutable $start, int|float $days): CarbonImmutable
    {
        return $this->add($start, $days, 'days');
    }

    public function addMinutes(CarbonImmutable $start, int $minutes): CarbonImmutable
    {
        if ($minutes < 0) {
            throw new InvalidArgumentException('Negative Arbeitsminuten werden nicht unterstützt.');
        }

        $cursor = $this->calendar->nextWorkingMoment($start->utc());
        $remaining = $minutes;

        for ($i = 0; $i < 4000; $i++) {
            $local = $cursor->setTimezone($this->calendar->timezone());

            foreach ($this->calendar->intervalsOfDay($local) as [$intervalStart, $intervalEnd]) {
                if ($cursor->greaterThanOrEqualTo($intervalEnd)) {
                    continue;
                }

                $from = $cursor->greaterThan($intervalStart) ? $cursor : $intervalStart;
                $available = (int) $from->diffInMinutes($intervalEnd);

                if ($remaining <= $available) {
                    return $from->addMinutes($remaining);
                }

                $remaining -= $available;
                $cursor = $intervalEnd;
            }

            $cursor = $this->calendar->nextWorkingMoment($local->addDay()->startOfDay()->setTimezone('UTC'));
        }

        throw new InvalidArgumentException('Arbeitszeitrechnung überschreitet 4000 Tage; Werte prüfen.');
    }

    /**
     * Verstrichene Arbeitsminuten zwischen zwei Zeitpunkten (UTC).
     */
    public function elapsedMinutes(CarbonImmutable $from, CarbonImmutable $to): int
    {
        if ($to->lessThanOrEqualTo($from)) {
            return 0;
        }

        $minutes = 0;
        $cursor = $from->utc()->setTimezone($this->calendar->timezone())->startOfDay();
        $end = $to->utc();

        for ($i = 0; $i < 4000 && $cursor->setTimezone('UTC')->lessThan($end); $i++) {
            foreach ($this->calendar->intervalsOfDay($cursor) as [$intervalStart, $intervalEnd]) {
                $s = $intervalStart->greaterThan($from) ? $intervalStart : $from->utc();
                $e = $intervalEnd->lessThan($end) ? $intervalEnd : $end;

                if ($e->greaterThan($s)) {
                    $minutes += (int) $s->diffInMinutes($e);
                }
            }

            $cursor = $cursor->addDay();
        }

        return $minutes;
    }

    private function toMinutes(int|float $amount, string $unit): int
    {
        return match ($unit) {
            'minutes', 'minute' => (int) round($amount),
            'hours', 'hour' => (int) round($amount * 60),
            'days', 'day' => (int) round($amount * $this->workDayMinutes),
            default => throw new InvalidArgumentException(sprintf('Unbekannte Einheit %s.', $unit)),
        };
    }
}
