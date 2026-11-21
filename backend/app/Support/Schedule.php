<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Helpers for weekly class schedules stored as [{day: "Sun", start: "09:00", end: "10:30"}].
 * Bangladeshi universities usually run Saturday to Thursday.
 */
class Schedule
{
    public const DAYS = ['Sat', 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri'];

    /** Carbon day numbers (Sunday = 0). */
    private const DAY_NUMBERS = ['Sun' => 0, 'Mon' => 1, 'Tue' => 2, 'Wed' => 3, 'Thu' => 4, 'Fri' => 5, 'Sat' => 6];

    /** Do two slots overlap? Touching (10:30 end / 10:30 start) is not a clash. */
    public static function slotsOverlap(array $a, array $b): bool
    {
        return $a['day'] === $b['day'] && $a['start'] < $b['end'] && $b['start'] < $a['end'];
    }

    /** The first overlapping pair between two schedules, or null. */
    public static function firstOverlap(?array $a, ?array $b): ?array
    {
        foreach ($a ?? [] as $x) {
            foreach ($b ?? [] as $y) {
                if (self::slotsOverlap($x, $y)) {
                    return [$x, $y];
                }
            }
        }

        return null;
    }

    public static function dayNumber(string $day): int
    {
        return self::DAY_NUMBERS[$day];
    }

    /** Short day name for a date, e.g. "Sun". */
    public static function dayName(\DateTimeInterface $date): string
    {
        return array_search((int) $date->format('w'), self::DAY_NUMBERS, true);
    }

    /** First date on or after $from that falls on $day. */
    public static function firstOccurrence(string $day, \DateTimeInterface $from): CarbonImmutable
    {
        $date = CarbonImmutable::instance($from)->startOfDay();
        $diff = (self::dayNumber($day) - (int) $date->format('w') + 7) % 7;

        return $date->addDays($diff);
    }
}
