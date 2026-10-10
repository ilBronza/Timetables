<?php

declare(strict_types=1);

namespace IlBronza\Timetables\Domain;

use DateTimeInterface;

final class LocalWindowFactory
{
    /** @param list<string> $timezones @return list<LocalWindow> */
    public function between(DateTimeInterface|string $from, DateTimeInterface|string $to, array $timezones, int $maxWindows = 4096): array
    {
        return $this->collect($from, $to, $timezones, $maxWindows);
    }

    /** @return list<LocalWindow> */
    public function onDate(CivilDate $date, string $timezone): array
    {
        $midnight = $date->midnightUtc();

        return $this->collect($midnight->modify('-2 days'), $midnight->modify('+3 days'), [$timezone], 16, $date);
    }

    /** @param list<string> $timezones @return list<LocalWindow> */
    private function collect(DateTimeInterface|string $from, DateTimeInterface|string $to, array $timezones, int $maxWindows, ?CivilDate $onlyDate = null): array
    {
        $interval = new InstantInterval($from, $to);
        $a = Instant::toMicros($interval->start);
        $b = Instant::toMicros($interval->end);
        if ($maxWindows < 1 || $maxWindows > intdiv(PHP_INT_MAX, Instant::DAY)) {
            throw new InvalidTimetable('The window limit must be a positive, finite integer.');
        }
        foreach ($timezones as $name) {
            if (!is_string($name)) {
                throw new InvalidTimetable('Timezone names must be strings.');
            }
        }
        $timezones = array_values(array_unique($timezones));
        if (count($timezones) > $maxWindows || ($timezones && $b - $a > $maxWindows * Instant::DAY)) {
            throw new QueryTooBroad('The request exceeds the local window budget.');
        }
        $windows = [];
        foreach ($timezones as $name) {
            $zone = Timezone::parse($name);
            $startSecond = (int) $interval->start->format('U');
            $endSecond = (int) $interval->end->format('U') + ((int) $interval->end->format('u') > 0 ? 1 : 0);
            // The first entry records the offset at the lower boundary; later
            // entries are actual UTC transitions, including non-hour changes.
            $transitions = $zone->getTransitions($startSecond, $endSecond);
            if ($transitions === false || !$transitions) {
                throw new InvalidTimetable('The timezone does not expose IANA transition data.');
            }
            $segmentStart = $a;
            $offset = (int) $transitions[0]['offset'];
            for ($i = 1; $i < count($transitions); ++$i) {
                $transition = (int) $transitions[$i]['ts'] * Instant::SECOND;
                if ($transition >= $b) {
                    break;
                }
                if ($transition > $segmentStart) {
                    $this->appendSegment($windows, $name, $segmentStart, $transition, $offset, $maxWindows, $onlyDate);
                }
                $segmentStart = max($segmentStart, $transition);
                $offset = (int) $transitions[$i]['offset'];
            }
            $this->appendSegment($windows, $name, $segmentStart, $b, $offset, $maxWindows, $onlyDate);
        }

        return $windows;
    }

    /** @param list<LocalWindow> $windows */
    private function appendSegment(array &$windows, string $timezone, int $a, int $b, int $offset, int $limit, ?CivilDate $onlyDate): void
    {
        $shift = $offset * Instant::SECOND;
        $civilStart = $a + $shift;
        $civilEnd = $b + $shift;
        while ($civilStart < $civilEnd) {
            if (count($windows) >= $limit) {
                throw new QueryTooBroad('The request exceeds the local window budget.');
            }
            $day = intdiv($civilStart, Instant::DAY);
            if ($civilStart % Instant::DAY < 0) {
                --$day;
            }
            $midnight = $day * Instant::DAY;
            $end = min($civilEnd, $midnight + Instant::DAY);
            $dateValue = Instant::fromMicros($midnight)->format('Y-m-d');
            if ($onlyDate !== null && $dateValue !== $onlyDate->value) {
                $civilStart = $end;
                continue;
            }
            $windows[] = new LocalWindow(
                $timezone,
                CivilDate::from($dateValue),
                $civilStart - $midnight,
                $end - $midnight,
                $civilStart - $shift,
            );
            $civilStart = $end;
        }
    }
}
