<?php

declare(strict_types=1);

namespace App\Core\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Datumsformate für UI und Dokumente: TT.MM.JJJJ, Zeitstempel TT.MM.JJJJ HH:MM.
 */
final class GermanDate
{
    public const string FORMAT_DATE = 'd.m.Y';

    public const string FORMAT_DATETIME = 'd.m.Y H:i';

    public const string TIMEZONE = 'Europe/Berlin';

    public static function format(DateTimeInterface|string|null $value): ?string
    {
        $date = self::toCarbon($value);

        return $date?->format(self::FORMAT_DATE);
    }

    public static function formatDateTime(DateTimeInterface|string|null $value, string $timezone = self::TIMEZONE): ?string
    {
        $date = self::toCarbon($value);

        return $date?->setTimezone($timezone)->format(self::FORMAT_DATETIME);
    }

    /**
     * Parst TT.MM.JJJJ zu einem UTC-Datum; null bei ungültiger Eingabe.
     */
    public static function parse(string $value): ?CarbonImmutable
    {
        $value = trim($value);

        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $value, $m) !== 1) {
            return null;
        }

        if (! checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return null;
        }

        return CarbonImmutable::create((int) $m[3], (int) $m[2], (int) $m[1], 0, 0, 0, 'UTC');
    }

    private static function toCarbon(DateTimeInterface|string|null $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }

        return self::parse($value) ?? CarbonImmutable::parse($value);
    }
}
