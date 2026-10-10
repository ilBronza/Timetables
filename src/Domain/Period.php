<?php

declare(strict_types=1);

namespace IlBronza\Timetables\Domain;

final readonly class Period
{
    /** @param array<int, array<CivilInterval>> $weekly @param array<string, array<CivilInterval>> $exceptions */
    public function __construct(
        public ?CivilDate $validFrom = null,
        public ?CivilDate $validTo = null,
        public array $weekly = [],
        public array $exceptions = [],
    ) {
        if ($validFrom !== null && $validTo !== null && $validFrom->value > $validTo->value) {
            throw new InvalidTimetable('The period end must not precede its start.');
        }
        foreach ($weekly as $weekday => $intervals) {
            if (!is_int($weekday) || $weekday < 1 || $weekday > 7) {
                throw new InvalidTimetable('Weekdays must be ISO integers from 1 to 7.');
            }
            self::validateIntervals($intervals);
        }
        foreach ($exceptions as $date => $intervals) {
            if (!is_string($date) || !$this->contains(CivilDate::from($date))) {
                throw new InvalidTimetable('Exception dates must belong to their period.');
            }
            self::validateIntervals($intervals);
        }
    }

    public function contains(CivilDate $date): bool
    {
        return ($this->validFrom === null || $this->validFrom->value <= $date->value)
            && ($this->validTo === null || $this->validTo->value >= $date->value);
    }

    /** @return array<CivilInterval> */
    public function intervalsFor(CivilDate $date): array
    {
        return $this->exceptions[$date->value] ?? $this->weekly[$date->weekday()] ?? [];
    }

    private static function validateIntervals(mixed $intervals): void
    {
        if (!is_array($intervals)) {
            throw new InvalidTimetable('Day intervals must be an array.');
        }
        foreach ($intervals as $interval) {
            if (!$interval instanceof CivilInterval) {
                throw new InvalidTimetable('Day intervals must contain civil intervals.');
            }
        }
    }
}
