<?php

declare(strict_types=1);

namespace IlBronza\Timetables\Domain;

use DateTimeImmutable;
use DateTimeInterface;

final readonly class InstantInterval
{
    public DateTimeImmutable $start;
    public DateTimeImmutable $end;

    public function __construct(DateTimeInterface|string $start, DateTimeInterface|string $end)
    {
        $this->start = Instant::parse($start);
        $this->end = Instant::parse($end);
        if (Instant::toMicros($this->end) <= Instant::toMicros($this->start)) {
            throw new InvalidTimetable('An instant interval must have a strictly positive duration.');
        }
    }

    /** @param array<self> $intervals @return list<self> */
    public static function merge(array $intervals): array
    {
        foreach ($intervals as $interval) {
            if (!$interval instanceof self) {
                throw new InvalidTimetable('Expected instant intervals.');
            }
        }
        usort($intervals, fn (self $a, self $b): int => $a->start <=> $b->start);
        $result = [];
        foreach ($intervals as $interval) {
            $last = $result ? $result[array_key_last($result)] : null;
            if ($last !== null && $interval->start <= $last->end) {
                $result[array_key_last($result)] = new self($last->start, max($last->end, $interval->end));
            } else {
                $result[] = $interval;
            }
        }

        return $result;
    }
}
