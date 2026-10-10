<?php

declare(strict_types=1);

namespace IlBronza\Timetables\Domain;

final readonly class CivilInterval
{
    public function __construct(public int $startMinute, public int $endMinute)
    {
        if ($startMinute < 0 || $startMinute > 1439 || $endMinute <= $startMinute
            || $endMinute > $startMinute + 1440) {
            throw new InvalidTimetable('Civil intervals must start within their origin day and last between 1 minute and 24 hours.');
        }
    }

    public static function fromTimes(string $start, string $end, bool $endsNextDay = false): self
    {
        $a = self::minute($start, false);
        $b = self::minute($end, true);

        return new self($a, $b + ($endsNextDay ? 1440 : 0));
    }

    private static function minute(string $time, bool $allowEndOfDay): int
    {
        if ($allowEndOfDay && $time === '24:00') {
            return 1440;
        }
        if (!preg_match('/\A([01]\d|2[0-3]):([0-5]\d)\z/D', $time, $parts)) {
            throw new InvalidTimetable('Expected HH:mm; 24:00 is allowed only as the end of a day.');
        }

        return (int) $parts[1] * 60 + (int) $parts[2];
    }
}
