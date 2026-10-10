<?php

namespace IlBronza\Timetables\Services;

use IlBronza\Timetables\Domain\CivilDate;
use IlBronza\Timetables\Domain\CivilInterval;
use IlBronza\Timetables\Domain\Period;
use IlBronza\Timetables\Domain\Schedule;
use IlBronza\Timetables\Exceptions\TimetableConflict;
use IlBronza\Timetables\Models\Timetable;
use IlBronza\Timetables\Support\Timezones;
use Illuminate\Database\Eloquent\Model;

final class TimetableReader
{
    public function __construct(private readonly TimetableResolver $resolver) {}

    public function for(Model $owner, string $kind): BoundTimetableReader
    {
        return new BoundTimetableReader($this, $this->resolver, $owner, $kind);
    }

    public function scheduleFor(Model $owner, string $kind): ?Schedule
    {
        if (! $owner->exists || ! $owner->newQuery()->whereKey($owner->getKey())->exists()) {
            return null;
        }
        $class = Timetable::getProjectClassName();
        $query = $class::on($owner->getConnectionName())
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', (string) $owner->getKey())->where('kind', $kind);

        // A fresh read of the revision detects commits between eager-load queries.
        // This also works inside an existing transaction with a consistent snapshot.
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $header = (clone $query)->with('periods.days.intervals')->first();
            if ($header === null) {
                return null;
            }
            if (! (clone $query)->whereKey($header->getKey())->where('revision', $header->revision)->exists()) {
                continue;
            }
            if (! $header->enabled) {
                return null;
            }
            Timezones::assertAllowed($header->timezone);
            $periods = [];
            foreach ($header->periods as $period) {
                $weekly = $exceptions = [];
                foreach ($period->days as $day) {
                    $spans = [];
                    foreach ($day->intervals as $interval) {
                        $spans[] = new CivilInterval((int) $interval->start_minute, (int) $interval->end_minute);
                    }
                    if ($day->on_date !== null) {
                        $exceptions[$this->dateString($day->on_date)] = $spans;
                    } else {
                        $weekly[(int) $day->weekday] = $spans;
                    }
                }
                $periods[] = new Period(
                    $period->valid_from === null ? null : CivilDate::from($this->dateString($period->valid_from)),
                    $period->valid_to === null ? null : CivilDate::from($this->dateString($period->valid_to)),
                    $weekly, $exceptions,
                );
            }

            return new Schedule($header->timezone, true, $periods);
        }

        throw new TimetableConflict('The timetable changed repeatedly during reading; retry the operation.');
    }

    private function dateString(mixed $value): string
    {
        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : (string) $value;
    }
}
