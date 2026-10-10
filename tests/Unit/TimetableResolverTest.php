<?php

declare(strict_types=1);

namespace IlBronza\Timetables\Tests\Unit;

use IlBronza\Timetables\Domain\CivilDate;
use IlBronza\Timetables\Domain\CivilInterval;
use IlBronza\Timetables\Domain\Instant;
use IlBronza\Timetables\Domain\InstantInterval;
use IlBronza\Timetables\Domain\InvalidTimetable;
use IlBronza\Timetables\Domain\Period;
use IlBronza\Timetables\Domain\Schedule;
use IlBronza\Timetables\Domain\TimetableState;
use IlBronza\Timetables\Domain\Timezone;
use IlBronza\Timetables\Services\TimetableResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TimetableResolverTest extends TestCase
{
    private function schedule(array $weekly = [], array $exceptions = [], string $zone = 'UTC'): Schedule
    {
        return new Schedule($zone, periods: [new Period(weekly: $weekly, exceptions: $exceptions)]);
    }

    /** @param array<InstantInterval> $intervals */
    private function formatted(array $intervals): array
    {
        return array_map(fn (InstantInterval $i): array => [$i->start->format('Y-m-d\TH:i:s.u\Z'), $i->end->format('Y-m-d\TH:i:s.u\Z')], $intervals);
    }

    public function test_states_distinguish_closed_and_unconfigured(): void
    {
        $resolver = new TimetableResolver();
        $at = '2026-01-05T12:00Z';
        self::assertSame(TimetableState::Unconfigured, $resolver->stateAt(null, $at));
        self::assertSame(TimetableState::Unconfigured, $resolver->stateAt(new Schedule('UTC', false), $at));
        self::assertSame(TimetableState::Unconfigured, $resolver->stateAt(new Schedule('UTC'), $at));
        self::assertSame(TimetableState::Inactive, $resolver->stateAt($this->schedule(), $at));
        $future = new Schedule('UTC', periods: [new Period(validFrom: CivilDate::from('2026-01-06'))]);
        self::assertSame(TimetableState::Unconfigured, $resolver->stateAt($future, $at));
    }

    public function test_boundaries_and_subminute_overlap_are_half_open(): void
    {
        $resolver = new TimetableResolver();
        $schedule = $this->schedule([1 => [new CivilInterval(540, 720), new CivilInterval(840, 1080)]]);
        foreach (['08:59:59.999999' => false, '09:00:00' => true, '11:59:59.999999' => true, '12:00:00' => false, '14:00:00' => true, '18:00:00' => false] as $time => $active) {
            self::assertSame($active ? TimetableState::Active : TimetableState::Inactive, $resolver->stateAt($schedule, '2026-01-05T'.$time.'Z'));
        }
        self::assertSame([], $resolver->intervalsBetween($schedule, '2026-01-05T08:59:59.999999Z', '2026-01-05T09:00Z'));
        self::assertSame([['2026-01-05T09:00:00.000000Z', '2026-01-05T09:00:00.000001Z']], $this->formatted($resolver->intervalsBetween($schedule, '2026-01-05T08:59:59.999999Z', '2026-01-05T09:00:00.000001Z')));
        self::assertFalse($resolver->covers($schedule, '2026-01-05T11:00Z', '2026-01-05T15:00Z'));
        self::assertTrue($resolver->covers($schedule, '2026-01-05T09:00Z', '2026-01-05T12:00Z'));
    }

    public function test_exception_replaces_the_entire_date_including_previous_tail(): void
    {
        $resolver = new TimetableResolver();
        $weekly = [7 => [new CivilInterval(1320, 1560)], 1 => [new CivilInterval(600, 660)]];
        $closed = $this->schedule($weekly, ['2026-01-05' => []]);
        $replacement = $this->schedule($weekly, ['2026-01-05' => [new CivilInterval(30, 45)]]);
        self::assertSame([], $resolver->intervalsOn($closed, CivilDate::from('2026-01-05')));
        self::assertSame([['2026-01-05T00:30:00.000000Z', '2026-01-05T00:45:00.000000Z']], $this->formatted($resolver->intervalsOn($replacement, CivilDate::from('2026-01-05'))));
        self::assertSame([['2026-01-05T00:00:00.000000Z', '2026-01-05T02:00:00.000000Z'], ['2026-01-05T10:00:00.000000Z', '2026-01-05T11:00:00.000000Z']], $this->formatted($resolver->intervalsOn($this->schedule($weekly), CivilDate::from('2026-01-05'))));
    }

    public function test_previous_exception_can_carry_to_an_ordinary_day(): void
    {
        $schedule = $this->schedule(exceptions: ['2026-01-04' => [new CivilInterval(1380, 1620)]]);
        self::assertSame([['2026-01-05T00:00:00.000000Z', '2026-01-05T03:00:00.000000Z']], $this->formatted((new TimetableResolver())->intervalsOn($schedule, CivilDate::from('2026-01-05'))));
    }

    public function test_period_end_clips_tail_even_when_next_period_starts_immediately(): void
    {
        $schedule = new Schedule('UTC', periods: [
            new Period(validTo: CivilDate::from('2026-01-04'), weekly: [7 => [new CivilInterval(1320, 1560)]]),
            new Period(validFrom: CivilDate::from('2026-01-05'), weekly: [1 => [new CivilInterval(600, 660)]]),
        ]);
        self::assertSame([['2026-01-04T22:00:00.000000Z', '2026-01-05T00:00:00.000000Z'], ['2026-01-05T10:00:00.000000Z', '2026-01-05T11:00:00.000000Z']], $this->formatted((new TimetableResolver())->intervalsBetween($schedule, '2026-01-04T20:00Z', '2026-01-05T12:00Z')));
    }

    public function test_repeated_rome_hour_produces_two_ranges_with_a_gap(): void
    {
        $resolver = new TimetableResolver();
        $schedule = $this->schedule([7 => [new CivilInterval(135, 165)]], zone: 'Europe/Rome');
        self::assertSame([['2026-10-25T00:15:00.000000Z', '2026-10-25T00:45:00.000000Z'], ['2026-10-25T01:15:00.000000Z', '2026-10-25T01:45:00.000000Z']], $this->formatted($resolver->intervalsOn($schedule, CivilDate::from('2026-10-25'))));
        self::assertFalse($resolver->covers($schedule, '2026-10-25T00:15Z', '2026-10-25T01:45Z'));
        self::assertSame([], $resolver->intervalsOn($schedule, CivilDate::from('2026-03-29')));
    }

    public function test_partial_spring_gap_keeps_existing_minutes_and_merges_adjacent_utc_parts(): void
    {
        $schedule = $this->schedule([7 => [new CivilInterval(105, 195)]], zone: 'Europe/Rome');
        self::assertSame([['2026-03-29T00:45:00.000000Z', '2026-03-29T01:15:00.000000Z']], $this->formatted((new TimetableResolver())->intervalsOn($schedule, CivilDate::from('2026-03-29'))));
    }

    public function test_lord_howe_repeats_thirty_minutes_and_skips_thirty_minutes(): void
    {
        $resolver = new TimetableResolver();
        $repeat = $this->schedule([7 => [new CivilInterval(100, 110)]], zone: 'Australia/Lord_Howe');
        self::assertSame([['2026-04-04T14:40:00.000000Z', '2026-04-04T14:50:00.000000Z'], ['2026-04-04T15:10:00.000000Z', '2026-04-04T15:20:00.000000Z']], $this->formatted($resolver->intervalsOn($repeat, CivilDate::from('2026-04-05'))));
        $gap = $this->schedule([7 => [new CivilInterval(130, 140)]], zone: 'Australia/Lord_Howe');
        self::assertSame([], $resolver->intervalsOn($gap, CivilDate::from('2026-10-04')));
    }

    public function test_apia_skipped_date_has_no_occurrence_and_no_tail_jumps_two_dates(): void
    {
        $resolver = new TimetableResolver();
        $skipped = $this->schedule(exceptions: ['2011-12-30' => [new CivilInterval(0, 1440)]], zone: 'Pacific/Apia');
        self::assertSame([], $resolver->intervalsOn($skipped, CivilDate::from('2011-12-30')));
        $night = $this->schedule(exceptions: ['2011-12-29' => [new CivilInterval(1380, 1500)]], zone: 'Pacific/Apia');
        self::assertSame(TimetableState::Active, $resolver->stateAt($night, '2011-12-30T09:59:59.999999Z'));
        self::assertSame(TimetableState::Inactive, $resolver->stateAt($night, '2011-12-30T10:00Z'));
    }

    public function test_full_days_have_correct_real_duration_across_dst_and_future_dates(): void
    {
        $resolver = new TimetableResolver();
        $schedule = $this->schedule([7 => [new CivilInterval(0, 1440)]], zone: 'Europe/Rome');
        foreach (['2026-03-29' => 23, '2026-10-25' => 25] as $date => $hours) {
            $interval = $resolver->intervalsOn($schedule, CivilDate::from($date))[0];
            self::assertSame($hours * 3600 * Instant::SECOND, Instant::toMicros($interval->end) - Instant::toMicros($interval->start));
        }
        $future = $this->schedule(exceptions: ['2040-01-02' => [new CivilInterval(540, 600)]]);
        self::assertSame(TimetableState::Active, $resolver->stateAt($future, '2040-01-02T09:00Z'));
        self::assertSame(TimetableState::Inactive, $resolver->stateAt($future, '2040-01-02T10:00Z'));
    }

    public function test_overlapping_and_adjacent_civil_spans_merge_only_after_projection(): void
    {
        $schedule = $this->schedule([1 => [new CivilInterval(0, 1440), new CivilInterval(60, 180), new CivilInterval(180, 300), new CivilInterval(1320, 1560)]]);
        self::assertSame([['2026-01-05T00:00:00.000000Z', '2026-01-06T02:00:00.000000Z']], $this->formatted((new TimetableResolver())->intervalsBetween($schedule, '2026-01-05T00:00Z', '2026-01-06T03:00Z')));
    }

    public function test_first_and_last_supported_civil_dates_resolve_without_envelope_overflow(): void
    {
        $resolver = new TimetableResolver();
        foreach (['0001-01-01', '9999-12-31'] as $value) {
            $schedule = $this->schedule(exceptions: [$value => [new CivilInterval(0, 1440)]]);
            $intervals = $resolver->intervalsOn($schedule, CivilDate::from($value));
            self::assertCount(1, $intervals);
            self::assertSame($value.'T00:00:00.000000Z', $intervals[0]->start->format('Y-m-d\TH:i:s.u\Z'));
            self::assertSame(Instant::DAY, Instant::toMicros($intervals[0]->end) - Instant::toMicros($intervals[0]->start));
        }
    }

    #[DataProvider('invalidRanges')]
    public function test_invalid_range_is_rejected_even_without_a_schedule(string $from, string $to): void
    {
        $this->expectException(InvalidTimetable::class);
        (new TimetableResolver())->intervalsBetween(null, $from, $to);
    }

    public static function invalidRanges(): array
    {
        return [['2026-01-01T12:00Z', '2026-01-01T12:00Z'], ['2026-01-01T12:00Z', '2026-01-01T11:00Z']];
    }

    public function test_generated_instants_match_an_independent_wall_clock_oracle(): void
    {
        $resolver = new TimetableResolver();
        $weekly = [];
        for ($weekday = 1; $weekday <= 7; ++$weekday) {
            $weekly[$weekday] = [new CivilInterval(100, 110), new CivilInterval(135, 165), new CivilInterval(1320, 1560)];
        }
        $exceptions = ['2026-01-05' => [], '2026-03-29' => [new CivilInterval(60, 210)], '2011-12-30' => [new CivilInterval(0, 1440)]];
        $anchors = ['2026-01-05T00:00Z', '2026-03-29T01:00Z', '2026-10-25T01:00Z', '2026-04-04T15:00Z', '2026-10-03T15:30Z', '2011-12-30T10:00Z'];
        foreach (['UTC', 'Europe/Rome', 'America/New_York', 'Australia/Lord_Howe', 'Pacific/Apia'] as $zone) {
            $schedule = $this->schedule($weekly, $exceptions, $zone);
            foreach ($anchors as $anchor) {
                for ($n = 0; $n < 16; ++$n) {
                    $at = Instant::parse($anchor)->modify((($n * 47) % 401 - 200).' minutes')->modify('+123456 microseconds');
                    $local = $at->setTimezone(Timezone::parse($zone));
                    $date = CivilDate::from($local->format('Y-m-d'));
                    $minute = ((int) $local->format('H') * 60 + (int) $local->format('i')) * Instant::MINUTE
                        + (int) $local->format('s') * Instant::SECOND + (int) $local->format('u');
                    $today = $exceptions[$date->value] ?? $weekly[$date->weekday()];
                    $active = false;
                    foreach ($today as $span) {
                        $active = $active || ($span->startMinute * Instant::MINUTE <= $minute && $minute < $span->endMinute * Instant::MINUTE);
                    }
                    if (!array_key_exists($date->value, $exceptions)) {
                        $previous = $date->previous();
                        foreach ($exceptions[$previous->value] ?? $weekly[$previous->weekday()] as $span) {
                            $active = $active || $minute + Instant::DAY < $span->endMinute * Instant::MINUTE;
                        }
                    }
                    self::assertSame($active, $resolver->stateAt($schedule, $at) === TimetableState::Active, $zone.' '.$at->format('c'));
                }
            }
        }
    }
}
