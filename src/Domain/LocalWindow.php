<?php

declare(strict_types=1);

namespace IlBronza\Timetables\Domain;

final readonly class LocalWindow
{
    public function __construct(
        public string $timezone,
        public CivilDate $date,
        public int $startMicros,
        public int $endMicros,
        public int $utcStartMicros,
    ) {
        if ($startMicros < 0 || $endMicros > Instant::DAY || $endMicros <= $startMicros) {
            throw new InvalidTimetable('A local window must be a positive interval within one civil day.');
        }
    }
}
