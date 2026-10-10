<?php

declare(strict_types=1);

namespace IlBronza\Timetables\Domain;

use DateTimeImmutable;
use DateTimeZone;

final readonly class CivilDate
{
    private function __construct(public string $value)
    {
    }

    public static function from(string $value): self
    {
        if (!preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/D', $value, $parts)
            || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            throw new InvalidTimetable('Expected a valid Gregorian date in YYYY-MM-DD format.');
        }

        return new self($value);
    }

    public function previous(): self
    {
        return self::from($this->midnightUtc()->modify('-1 day')->format('Y-m-d'));
    }

    public function weekday(): int
    {
        return (int) $this->midnightUtc()->format('N');
    }

    public function midnightUtc(): DateTimeImmutable
    {
        return new DateTimeImmutable($this->value.'T00:00:00', new DateTimeZone('UTC'));
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
