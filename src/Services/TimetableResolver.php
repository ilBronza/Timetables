<?php

declare(strict_types=1);

namespace IlBronza\Timetables\Services;

use DateTimeInterface;
use IlBronza\Timetables\Domain\CivilDate;
use IlBronza\Timetables\Domain\Instant;
use IlBronza\Timetables\Domain\InstantInterval;
use IlBronza\Timetables\Domain\LocalWindow;
use IlBronza\Timetables\Domain\LocalWindowFactory;
use IlBronza\Timetables\Domain\Period;
use IlBronza\Timetables\Domain\Schedule;
use IlBronza\Timetables\Domain\TimetableState;
use IlBronza\Timetables\Domain\Timezone;

final class TimetableResolver
{
    public function __construct(private readonly LocalWindowFactory $windows = new LocalWindowFactory())
    {
    }

    public function stateAt(?Schedule $schedule, DateTimeInterface|string $instant): TimetableState
    {
        $instant = Instant::parse($instant);
        if ($schedule === null || !$schedule->enabled) {
            return TimetableState::Unconfigured;
        }
        $date = CivilDate::from($instant->setTimezone(Timezone::parse($schedule->timezone))->format('Y-m-d'));
        if ($schedule->periodFor($date) === null) {
            return TimetableState::Unconfigured;
        }
        $a = Instant::toMicros($instant);

        return $this->intervalsBetween($schedule, $instant, Instant::fromMicros($a + 1))
            ? TimetableState::Active : TimetableState::Inactive;
    }

    /** @return list<InstantInterval> */
    public function intervalsBetween(?Schedule $schedule, DateTimeInterface|string $from, DateTimeInterface|string $to): array
    {
        $interval = new InstantInterval($from, $to);
        if ($schedule === null || !$schedule->enabled) {
            return [];
        }

        return $this->resolveWindows($schedule, $this->windows->between($interval->start, $interval->end, [$schedule->timezone]));
    }

    /** @return list<InstantInterval> */
    public function intervalsOn(?Schedule $schedule, CivilDate $date): array
    {
        if ($schedule === null || !$schedule->enabled || $schedule->periodFor($date) === null) {
            return [];
        }
        // A UTC envelope captures every occurrence of a local date, including
        // midnight transitions and a repeated date. A skipped date has none.
        return $this->resolveWindows($schedule, $this->windows->onDate($date, $schedule->timezone));
    }

    public function covers(?Schedule $schedule, DateTimeInterface|string $from, DateTimeInterface|string $to): bool
    {
        $requested = new InstantInterval($from, $to);
        $active = $this->intervalsBetween($schedule, $requested->start, $requested->end);

        return count($active) === 1 && $active[0]->start == $requested->start && $active[0]->end == $requested->end;
    }

    /** @param list<LocalWindow> $windows @return list<InstantInterval> */
    private function resolveWindows(Schedule $schedule, array $windows): array
    {
        $result = [];
        foreach ($windows as $window) {
            $period = $schedule->periodFor($window->date);
            if ($period === null) {
                continue;
            }
            foreach ($this->civilRanges($period, $window->date) as [$start, $end]) {
                $a = max($start, $window->startMicros);
                $b = min($end, $window->endMicros);
                if ($a < $b) {
                    $result[] = new InstantInterval(
                        Instant::fromMicros($window->utcStartMicros + $a - $window->startMicros),
                        Instant::fromMicros($window->utcStartMicros + $b - $window->startMicros),
                    );
                }
            }
        }

        return InstantInterval::merge($result);
    }

    /** @return list<array{int, int}> */
    private function civilRanges(Period $period, CivilDate $date): array
    {
        $ranges = [];
        foreach ($period->intervalsFor($date) as $interval) {
            $ranges[] = [$interval->startMinute * Instant::MINUTE, min($interval->endMinute * Instant::MINUTE, Instant::DAY)];
        }
        if (!array_key_exists($date->value, $period->exceptions) && $date->value !== '0001-01-01') {
            $previous = $date->previous();
            if ($period->contains($previous)) {
                foreach ($period->intervalsFor($previous) as $interval) {
                    if ($interval->endMinute > 1440) {
                        $ranges[] = [0, ($interval->endMinute - 1440) * Instant::MINUTE];
                    }
                }
            }
        }

        return $ranges;
    }
}
