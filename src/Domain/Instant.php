<?php

declare(strict_types=1);

namespace IlBronza\Timetables\Domain;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

final class Instant
{
    public const SECOND = 1_000_000;
    public const MINUTE = 60_000_000;
    public const DAY = 86_400_000_000;

    public static function parse(DateTimeInterface|string $value): DateTimeImmutable
    {
        if (is_string($value)) {
            if (!preg_match('/\A(\d{4}-\d{2}-\d{2})T([01]\d|2[0-3]):([0-5]\d)(?::([0-5]\d)(?:\.(\d{1,6}))?)?(Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)\z/D', $value, $parts)) {
                throw new InvalidTimetable('Expected an ISO timestamp with an explicit offset and at most six fractional digits.');
            }
            CivilDate::from($parts[1]);
            $instant = new DateTimeImmutable($value);
        } else {
            $instant = DateTimeImmutable::createFromInterface($value);
        }

        return $instant->setTimezone(new DateTimeZone('UTC'));
    }

    public static function toMicros(DateTimeInterface $value): int
    {
        self::require64Bit();
        $seconds = (int) $value->format('U');
        if ($seconds > intdiv(PHP_INT_MAX - 999_999, self::SECOND)
            || $seconds < intdiv(PHP_INT_MIN, self::SECOND)) {
            throw new InvalidTimetable('The instant exceeds the supported microsecond range.');
        }

        return $seconds * self::SECOND + (int) $value->format('u');
    }

    public static function fromMicros(int $value): DateTimeImmutable
    {
        self::require64Bit();
        $seconds = intdiv($value, self::SECOND);
        $fraction = $value % self::SECOND;
        if ($fraction < 0) {
            --$seconds;
            $fraction += self::SECOND;
        }

        return (new DateTimeImmutable('@'.$seconds))->modify('+'.$fraction.' microseconds')
            ->setTimezone(new DateTimeZone('UTC'));
    }

    private static function require64Bit(): void
    {
        if (PHP_INT_SIZE !== 8) {
            throw new \RuntimeException('Timetables requires 64-bit PHP integers.');
        }
    }
}
