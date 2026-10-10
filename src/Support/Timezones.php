<?php

namespace IlBronza\Timetables\Support;

use IlBronza\Timetables\Domain\InvalidTimetable;
use IlBronza\Timetables\Domain\Timezone;

final class Timezones
{
    /** @return list<string> */
    public static function configured(): array
    {
        $names = config('timetables.timezones');
        if (! is_array($names) || $names === []) {
            throw new InvalidTimetable('Configure at least one timetable timezone.');
        }

        foreach ($names as $name) {
            if (! is_string($name)) {
                throw new InvalidTimetable('Timetable timezones must be named IANA identifiers.');
            }
            Timezone::parse($name);
        }

        return array_values(array_unique($names));
    }

    public static function assertAllowed(string $timezone): void
    {
        if (! in_array($timezone, self::configured(), true)) {
            throw new InvalidTimetable('Timezone ['.$timezone.'] is not configured for timetables.');
        }
    }
}
