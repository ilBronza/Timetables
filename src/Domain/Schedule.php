<?php

declare(strict_types=1);

namespace IlBronza\Timetables\Domain;

final readonly class Schedule
{
    /** @param array<Period> $periods */
    public function __construct(public string $timezone, public bool $enabled = true, public array $periods = [])
    {
        Timezone::parse($timezone);
        foreach ($periods as $period) {
            if (!$period instanceof Period) {
                throw new InvalidTimetable('Schedules must contain periods.');
            }
        }
        $ordered = array_values($periods);
        usort($ordered, fn (Period $a, Period $b): int => ($a->validFrom?->value ?? '') <=> ($b->validFrom?->value ?? ''));
        for ($i = 1; $i < count($ordered); ++$i) {
            $previousEnd = $ordered[$i - 1]->validTo;
            $currentStart = $ordered[$i]->validFrom;
            if ($previousEnd === null || $currentStart === null || $previousEnd->value >= $currentStart->value) {
                throw new InvalidTimetable('Periods within a schedule must be disjoint.');
            }
        }
    }

    public function periodFor(CivilDate $date): ?Period
    {
        foreach ($this->periods as $period) {
            if ($period->contains($date)) {
                return $period;
            }
        }

        return null;
    }
}
