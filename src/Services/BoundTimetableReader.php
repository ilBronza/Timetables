<?php

namespace IlBronza\Timetables\Services;

use DateTimeInterface;
use IlBronza\Timetables\Domain\CivilDate;
use IlBronza\Timetables\Domain\InstantInterval;
use IlBronza\Timetables\Domain\TimetableState;
use Illuminate\Database\Eloquent\Model;

final class BoundTimetableReader
{
    public function __construct(
        private readonly TimetableReader $reader,
        private readonly TimetableResolver $resolver,
        private readonly Model $owner,
        private readonly string $kind,
    ) {}

    public function stateAt(DateTimeInterface|string $instant): TimetableState
    {
        return $this->resolver->stateAt($this->reader->scheduleFor($this->owner, $this->kind), $instant);
    }

    public function isActiveAt(DateTimeInterface|string $instant): bool
    {
        return $this->stateAt($instant) === TimetableState::Active;
    }

    /** @return list<InstantInterval> */
    public function intervalsBetween(DateTimeInterface|string $from, DateTimeInterface|string $to): array
    {
        return $this->resolver->intervalsBetween($this->reader->scheduleFor($this->owner, $this->kind), $from, $to);
    }

    /** @return list<InstantInterval> */
    public function intervalsOn(CivilDate $date): array
    {
        return $this->resolver->intervalsOn($this->reader->scheduleFor($this->owner, $this->kind), $date);
    }

    public function covers(DateTimeInterface|string $from, DateTimeInterface|string $to): bool
    {
        return $this->resolver->covers($this->reader->scheduleFor($this->owner, $this->kind), $from, $to);
    }
}
