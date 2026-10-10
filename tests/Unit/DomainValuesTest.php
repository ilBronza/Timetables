<?php

declare(strict_types=1);

namespace IlBronza\Timetables\Tests\Unit;

use IlBronza\Timetables\Domain\CivilDate;
use IlBronza\Timetables\Domain\CivilInterval;
use IlBronza\Timetables\Domain\Instant;
use IlBronza\Timetables\Domain\InvalidTimetable;
use IlBronza\Timetables\Domain\Period;
use IlBronza\Timetables\Domain\Schedule;
use IlBronza\Timetables\Domain\Timezone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DomainValuesTest extends TestCase
{
    public function test_civil_date_calendar_and_previous(): void
    {
        self::assertSame('2024-02-29', CivilDate::from('2024-03-01')->previous()->value);
        self::assertSame('2025-12-31', CivilDate::from('2026-01-01')->previous()->value);
        self::assertSame(7, CivilDate::from('2026-01-04')->weekday());
        self::assertSame(1, CivilDate::from('2026-01-05')->weekday());
    }

    #[DataProvider('badDates')]
    public function test_civil_date_rejects_invalid_input(string $date): void
    {
        $this->expectException(InvalidTimetable::class);
        CivilDate::from($date);
    }

    public static function badDates(): array
    {
        return array_map(fn (string $s): array => [$s], ['2026-02-29', '2024-02-30', '0000-01-01', '2026-1-01', '2026-01-01 tomorrow', '2026-01-01\n']);
    }

    #[DataProvider('badInstants')]
    public function test_instant_requires_strict_absolute_timestamp(string $instant): void
    {
        $this->expectException(InvalidTimetable::class);
        Instant::parse($instant);
    }

    public static function badInstants(): array
    {
        return array_map(fn (string $s): array => [$s], [
            'now', '2026-01-01T09:00:00', '2026-01-01 09:00:00Z', '2026-02-29T00:00Z',
            '2026-01-01T24:00Z', '2026-01-01T12:60Z', '2026-01-01T12:00:60Z',
            '2026-01-01T12:00:00.1234567Z', '2026-01-01T12:00:00+24:00', '2026-01-01T12:00Z tomorrow',
        ]);
    }

    public function test_micros_preserve_precision_negative_epoch_and_future(): void
    {
        foreach (['1969-12-31T23:59:59.999999Z', '1900-01-01T00:00:00.000001Z', '2040-01-02T09:00:00.123456Z'] as $value) {
            $instant = Instant::parse($value);
            self::assertEquals($instant, Instant::fromMicros(Instant::toMicros($instant)));
        }
        self::assertSame(-1, Instant::toMicros(Instant::parse('1969-12-31T23:59:59.999999Z')));
        self::assertSame('2026-01-01T07:30:00.120000+00:00', Instant::parse('2026-01-01T09:00:00.12+01:30')->format('Y-m-d\TH:i:s.uP'));
    }

    public function test_minute_transport_supports_full_day_and_overnight(): void
    {
        self::assertEquals(new CivilInterval(0, 1440), CivilInterval::fromTimes('00:00', '24:00'));
        self::assertEquals(new CivilInterval(1320, 1560), CivilInterval::fromTimes('22:00', '02:00', true));
        self::assertEquals(new CivilInterval(540, 1980), CivilInterval::fromTimes('09:00', '09:00', true));
    }

    #[DataProvider('badIntervals')]
    public function test_invalid_civil_intervals_are_rejected(string $start, string $end, bool $next): void
    {
        $this->expectException(InvalidTimetable::class);
        CivilInterval::fromTimes($start, $end, $next);
    }

    public static function badIntervals(): array
    {
        return [['09:00', '09:00', false], ['22:00', '02:00', false], ['24:00', '24:00', false], ['9:00', '10:00', false], ['09:00', '10:00', true], ['00:00', '24:00', true]];
    }

    public function test_periods_cannot_overlap_including_inclusive_boundaries(): void
    {
        $this->expectException(InvalidTimetable::class);
        new Schedule('UTC', periods: [new Period(validTo: CivilDate::from('2026-01-05')), new Period(validFrom: CivilDate::from('2026-01-05'))]);
    }

    public function test_reverse_period_is_rejected(): void
    {
        $this->expectException(InvalidTimetable::class);
        new Period(CivilDate::from('2026-01-06'), CivilDate::from('2026-01-05'));
    }

    public function test_exception_must_be_within_period(): void
    {
        $this->expectException(InvalidTimetable::class);
        new Period(validFrom: CivilDate::from('2026-01-05'), exceptions: ['2026-01-04' => []]);
    }

    public function test_weekday_must_be_iso(): void
    {
        $this->expectException(InvalidTimetable::class);
        new Period(weekly: [0 => []]);
    }

    public function test_fixed_offset_is_not_an_iana_timezone(): void
    {
        $this->expectException(InvalidTimetable::class);
        new Schedule('+02:00');
    }

    #[DataProvider('abbreviatedTimezones')]
    public function test_abbreviations_are_rejected_before_persisting_a_schedule(string $timezone): void
    {
        $this->expectException(InvalidTimetable::class);
        Timezone::parse($timezone);
    }

    public static function abbreviatedTimezones(): array
    {
        return [['EST'], ['MST'], ['CET']];
    }

    public function test_named_fixed_iana_timezone_exposes_usable_transition_data(): void
    {
        $timezone = Timezone::parse('Etc/GMT+5');
        self::assertSame('Etc/GMT+5', $timezone->getName());
        self::assertNotFalse($timezone->getTransitions(0, 1));
        self::assertSame(-5 * 3600, $timezone->getOffset(Instant::parse('2026-01-01T00:00Z')));
    }
}
