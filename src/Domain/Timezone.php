<?php

declare(strict_types=1);

namespace IlBronza\Timetables\Domain;

use DateTimeZone;

final class Timezone
{
    public static function parse(string $name): DateTimeZone
    {
        static $identifiers;
        $identifiers ??= array_fill_keys(DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true);
        if (!isset($identifiers[$name])) {
            throw new InvalidTimetable('Expected an IANA timezone identifier.');
        }

        $timezone = new DateTimeZone($name);
        if ($timezone->getTransitions(0, 1) === false) {
            throw new InvalidTimetable('The timezone must expose IANA transition data; abbreviations are not supported.');
        }

        return $timezone;
    }
}
