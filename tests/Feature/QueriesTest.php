<?php

namespace IlBronza\Timetables\Tests\Feature;

use IlBronza\Timetables\Domain\CivilDate;
use IlBronza\Timetables\Domain\Instant;
use IlBronza\Timetables\Domain\InvalidTimetable;
use IlBronza\Timetables\Domain\QueryTooBroad;
use IlBronza\Timetables\Domain\TimetableState;
use IlBronza\Timetables\Services\TimetableWriter;
use IlBronza\Timetables\Tests\Fixtures\OtherOwner;
use IlBronza\Timetables\Tests\Fixtures\Owner;
use IlBronza\Timetables\Tests\Fixtures\StringOwner;
use IlBronza\Timetables\Tests\TestCase;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;

class QueriesTest extends TestCase
{
    private function schedule(Owner|OtherOwner|StringOwner $owner, array $weekly, array $exceptions = [], string $timezone = 'UTC', string $kind = 'opening')
    {
        return app(TimetableWriter::class)->replace($owner, $kind, [
            'timezone' => $timezone,
            'periods' => [['weekly' => $weekly, 'exceptions' => $exceptions]],
        ]);
    }

    public function test_filters_count_and_paginate_without_duplicates_or_losing_owner_conditions(): void
    {
        for ($i = 0; $i < 7; $i++) {
            $owner = Owner::create(['name' => 'Owner '.$i, 'tenant_id' => $i < 5 ? 1 : 2]);
            $this->schedule($owner, [1 => [[0, 1440], [540, 780], [1320, 1560]]]);
        }
        $query = Owner::where('tenant_id', 1)->whereTimetableActiveAt('2026-01-05T10:00:00Z', kind: 'opening');
        self::assertSame(5, (clone $query)->count());
        $page = (clone $query)->orderBy('id')->paginate(2, ['*'], 'page', 2);
        self::assertSame(5, $page->total());
        self::assertSame([3, 4], $page->pluck('id')->all());
        self::assertSame(0, Owner::where('tenant_id', 7)->whereTimetableActiveAt('2026-01-05T10:00:00Z', 'opening')->count());
    }

    public function test_exact_endpoints_pauses_and_continuous_coverage(): void
    {
        $owner = Owner::create(['name' => 'Split day']);
        $this->schedule($owner, [1 => [[540, 780], [840, 1080]]]);
        $reader = $owner->timetable('opening');
        foreach ([['08:59:59.999999', false], ['09:00:00', true], ['12:59:59.999999', true], ['13:00:00', false]] as [$time, $active]) {
            $instant = '2026-01-05T'.$time.'Z';
            self::assertSame($active, $reader->isActiveAt($instant));
            self::assertSame($active, Owner::whereTimetableActiveAt($instant, 'opening')->exists());
        }
        self::assertFalse($reader->covers('2026-01-05T12:00Z', '2026-01-05T15:00Z'));
        self::assertTrue($reader->covers('2026-01-05T09:00Z', '2026-01-05T13:00Z'));
        self::assertFalse(Owner::whereTimetableOverlaps('2026-01-05T13:00Z', '2026-01-05T14:00Z', 'opening')->exists());
        self::assertTrue(Owner::whereTimetableOverlaps('2026-01-05T08:59:59.999999Z', '2026-01-05T09:00:00.000001Z', 'opening')->exists());
        self::assertFalse(Owner::whereTimetableOverlaps('2026-01-05T08:59:59.999999Z', '2026-01-05T09:00:00Z', 'opening')->exists());
    }

    public function test_empty_exception_cancels_yesterdays_night_and_removing_it_restores_carry(): void
    {
        $owner = Owner::create(['name' => 'Night']);
        $header = $this->schedule($owner, [7 => [[1320, 1560]]], ['2026-01-05' => []]);
        self::assertSame(TimetableState::Inactive, $owner->timetable('opening')->stateAt('2026-01-05T01:00Z'));
        self::assertFalse(Owner::whereTimetableActiveAt('2026-01-05T01:00Z', 'opening')->exists());
        app(TimetableWriter::class)->replace($owner, 'opening', [
            'timezone' => 'UTC', 'periods' => [['weekly' => [7 => [[1320, 1560]]]]],
        ], $header->revision);
        self::assertTrue($owner->timetable('opening')->isActiveAt('2026-01-05T01:00Z'));
        self::assertTrue(Owner::whereTimetableActiveAt('2026-01-05T01:00Z', 'opening')->exists());
    }

    public function test_period_boundaries_clip_nights_and_gaps_are_unconfigured(): void
    {
        $owner = Owner::create(['name' => 'Seasons']);
        app(TimetableWriter::class)->replace($owner, 'working', ['timezone' => 'UTC', 'periods' => [
            ['valid_to' => '2026-01-04', 'weekly' => [7 => [[1320, 1560]]]],
            ['valid_from' => '2026-01-06', 'weekly' => [2 => [[600, 660]]]],
        ]]);
        self::assertFalse(Owner::whereTimetableActiveAt('2026-01-05T01:00Z', 'working')->exists());
        self::assertSame(TimetableState::Unconfigured, $owner->timetable('working')->stateAt('2026-01-05T01:00Z'));
        self::assertTrue(Owner::whereTimetableActiveAt('2026-01-06T10:00Z', 'working')->exists());
        self::assertTrue(Owner::whereTimetableActiveAt('2026-01-04T23:59:59.999999Z', 'working')->exists());
    }

    public static function timezoneCases(): array
    {
        return [
            'Rome spring missing hour' => ['Europe/Rome', [7 => [[135, 165]]], [], '2026-03-29T00:00Z', '2026-03-29T03:00Z', false],
            'Rome autumn first copy' => ['Europe/Rome', [7 => [[135, 165]]], [], '2026-10-25T00:15Z', '2026-10-25T00:45Z', true],
            'Rome autumn gap' => ['Europe/Rome', [7 => [[135, 165]]], [], '2026-10-25T00:45Z', '2026-10-25T01:15Z', false],
            'Rome autumn second copy' => ['Europe/Rome', [7 => [[135, 165]]], [], '2026-10-25T01:15Z', '2026-10-25T01:45Z', true],
            'Lord Howe repeated half hour' => ['Australia/Lord_Howe', [7 => [[100, 110]]], [], '2026-04-04T15:10Z', '2026-04-04T15:20Z', true],
            'Lord Howe missing half hour' => ['Australia/Lord_Howe', [7 => [[130, 140]]], [], '2026-10-03T14:00Z', '2026-10-03T17:00Z', false],
            'Apia skipped civil date' => ['Pacific/Apia', [], ['2011-12-30' => [[0, 1440]]], '2011-12-29T10:00Z', '2011-12-31T10:00Z', false],
            'Beyond 2038' => ['UTC', [], ['2040-01-02' => [[540, 600]]], '2040-01-02T09:00Z', '2040-01-02T10:00Z', true],
        ];
    }

    #[DataProvider('timezoneCases')]
    public function test_dst_sql_and_reader_agree_with_explicit_expected_results(string $timezone, array $weekly, array $exceptions, string $from, string $to, bool $expected): void
    {
        $owner = Owner::create(['name' => $timezone]);
        $this->schedule($owner, $weekly, $exceptions, $timezone);
        self::assertSame($expected, Owner::whereTimetableOverlaps($from, $to, 'opening')->exists());
        self::assertSame($expected, $owner->timetable('opening')->intervalsBetween($from, $to) !== []);
    }

    public function test_civil_day_reader_preserves_the_gap_between_repeated_hours(): void
    {
        $owner = Owner::create(['name' => 'Autumn']);
        $this->schedule($owner, [7 => [[135, 165]]], timezone: 'Europe/Rome');
        $ranges = $owner->timetable('opening')->intervalsOn(CivilDate::from('2026-10-25'));
        self::assertCount(2, $ranges);
        self::assertSame('00:15', $ranges[0]->start->format('H:i'));
        self::assertSame('01:15', $ranges[1]->start->format('H:i'));
        self::assertFalse($owner->timetable('opening')->covers('2026-10-25T00:15Z', '2026-10-25T01:45Z'));
    }

    public function test_morph_types_and_string_keys_do_not_collide(): void
    {
        Relation::morphMap(['owner' => Owner::class, 'other' => OtherOwner::class]);
        try {
            $first = Owner::create(['name' => 'First']);
            $second = OtherOwner::create(['name' => 'Other']);
            $this->schedule($first, [1 => [[540, 600]]]);
            self::assertTrue(Owner::whereTimetableActiveAt('2026-01-05T09:00Z', 'opening')->exists());
            self::assertFalse(OtherOwner::whereTimetableActiveAt('2026-01-05T09:00Z', 'opening')->exists());
            self::assertSame($first->id, $second->id);
            self::assertSame('owner', $first->timetables->first()->owner_type);
            self::assertTrue($first->timetables->first()->owner->is($first));
            foreach (['1', '01', '550e8400-e29b-41d4-a716-446655440000', '01ARZ3NDEKTSV4RRFFQ69G5FAV'] as $id) {
                $owner = StringOwner::create(['id' => $id, 'name' => $id]);
                if ($id !== '01') {
                    $this->schedule($owner, [1 => [[540, 600]]]);
                }
            }
            self::assertEqualsCanonicalizing(['1', '550e8400-e29b-41d4-a716-446655440000', '01ARZ3NDEKTSV4RRFFQ69G5FAV'],
                StringOwner::whereTimetableActiveAt('2026-01-05T09:00Z', 'opening')->pluck('id')->all());
        } finally {
            Relation::morphMap([], false);
        }
    }

    public function test_multiple_kinds_and_scope_composition_preserve_boolean_grouping(): void
    {
        $owner = Owner::create(['name' => 'Both']);
        $this->schedule($owner, [1 => [[540, 600]]]);
        $this->schedule($owner, [1 => [[570, 660]]], kind: 'reception');
        self::assertSame(1, Owner::whereTimetableActiveAt('2026-01-05T09:45Z', 'opening')
            ->whereTimetableActiveAt('2026-01-05T09:45Z', 'reception')->count());
        self::assertSame(0, Owner::whereTimetableActiveAt('2026-01-05T10:00Z', 'opening')
            ->whereTimetableActiveAt('2026-01-05T10:00Z', 'reception')->count());
        self::assertSame(1, Owner::withoutGlobalScopes()->from('owners as candidate')
            ->whereTimetableActiveAt('2026-01-05T09:45Z', 'opening')->count());
    }

    public function test_reader_observes_updates_and_soft_deleted_owners_are_unconfigured(): void
    {
        $owner = Owner::create(['name' => 'Lifecycle']);
        $header = $this->schedule($owner, [1 => [[0, 1440]]]);
        $reader = $owner->timetable('opening');
        self::assertTrue($reader->isActiveAt('2026-01-05T09:00Z'));
        $disabled = app(TimetableWriter::class)->setEnabled($owner, 'opening', false, $header->revision);
        self::assertSame(TimetableState::Unconfigured, $reader->stateAt('2026-01-05T09:00Z'));
        self::assertSame(0, Owner::whereTimetableActiveAt('2026-01-05T09:00Z', 'opening')->count());
        app(TimetableWriter::class)->setEnabled($owner, 'opening', true, $disabled->revision);
        $owner->delete();
        self::assertSame(TimetableState::Unconfigured, $reader->stateAt('2026-01-05T09:00Z'));
        self::assertSame(0, Owner::whereTimetableActiveAt('2026-01-05T09:00Z', 'opening')->count());
    }

    public function test_random_queries_match_independent_instant_membership_and_resolver(): void
    {
        mt_srand(61823);
        $definitions = [];
        foreach (['UTC', 'Europe/Rome', 'America/New_York'] as $zone) {
            $owner = Owner::create(['name' => $zone]);
            $weekly = [];
            for ($day = 1; $day <= 7; $day++) {
                $start = mt_rand(0, 1439);
                $weekly[$day] = [[$start, $start + mt_rand(1, 1440)]];
            }
            $exceptions = ['2026-10-25' => $zone === 'UTC' ? [] : [[135, 165]]];
            $this->schedule($owner, $weekly, $exceptions, $zone);
            $definitions[$owner->id] = [$owner, $zone, $weekly, $exceptions];
        }
        $anchor = Instant::parse('2026-10-25T00:00Z');
        for ($i = 0; $i < 60; $i++) {
            $at = $anchor->modify((string) mt_rand(-180000, 180000).' seconds')->modify('+'.mt_rand(0, 999999).' microseconds');
            $end = $at->modify('+'.mt_rand(1, 600).' seconds');
            $pointExpected = $overlapExpected = [];
            foreach ($definitions as $id => [$owner, $zone, $weekly, $exceptions]) {
                if ($this->directlyActive($at, $zone, $weekly, $exceptions)) {
                    $pointExpected[] = $id;
                }
                if ($owner->timetable('opening')->intervalsBetween($at, $end)) {
                    $overlapExpected[] = $id;
                }
            }
            self::assertSame($pointExpected, Owner::whereTimetableActiveAt($at, 'opening')->orderBy('id')->pluck('id')->all());
            self::assertSame($overlapExpected, Owner::whereTimetableOverlaps($at, $end, 'opening')->orderBy('id')->pluck('id')->all());
        }
    }

    private function directlyActive(\DateTimeImmutable $at, string $zone, array $weekly, array $exceptions): bool
    {
        $local = $at->setTimezone(new \DateTimeZone($zone));
        $date = $local->format('Y-m-d');
        $minute = ((int) $local->format('H') * 60 + (int) $local->format('i')) * 60_000_000
            + (int) $local->format('s') * 1_000_000 + (int) $local->format('u');
        foreach ($exceptions[$date] ?? $weekly[(int) $local->format('N')] ?? [] as [$a, $b]) {
            if ($a * 60_000_000 <= $minute && $minute < $b * 60_000_000) {
                return true;
            }
        }
        if (array_key_exists($date, $exceptions)) {
            return false;
        }
        $yesterday = new \DateTimeImmutable($date.'T00:00:00Z');
        $yesterday = $yesterday->modify('-1 day');
        foreach ($exceptions[$yesterday->format('Y-m-d')] ?? $weekly[(int) $yesterday->format('N')] ?? [] as [$a, $b]) {
            if ($b * 60_000_000 > 86_400_000_000 + $minute) {
                return true;
            }
        }
        return false;
    }

    public function test_search_budget_is_enforced_before_execution(): void
    {
        config(['timetables.query.max_windows' => 1]);
        $this->expectException(QueryTooBroad::class);
        Owner::whereTimetableActiveAt('2026-01-05T09:00Z', 'opening');
    }

    public function test_large_searches_fail_instead_of_returning_incomplete_results(): void
    {
        $this->expectException(QueryTooBroad::class);
        Owner::whereTimetableOverlaps('2026-01-01T00:00Z', '2026-03-01T00:00Z', 'opening');
    }

    public function test_existing_bindings_are_counted_toward_search_budget(): void
    {
        config(['timetables.query.max_bindings' => 40]);
        $this->expectException(QueryTooBroad::class);
        Owner::whereIn('id', range(1, 20))->whereTimetableActiveAt('2026-01-05T09:00Z', 'opening');
    }

    public function test_unzoned_input_is_rejected(): void
    {
        $this->expectException(InvalidTimetable::class);
        Owner::whereTimetableActiveAt('2026-01-05 09:00', 'opening');
    }

    public function test_configuration_command_checks_disabled_and_soft_deleted_headers(): void
    {
        $owner = Owner::create(['name' => 'Retired']);
        $header = $this->schedule($owner, [], timezone: 'Europe/Rome');
        app(TimetableWriter::class)->delete($owner, 'opening', $header->revision);
        self::assertSame(0, Artisan::call('timetables:validate'));
        config(['timetables.timezones' => ['UTC']]);
        self::assertSame(1, Artisan::call('timetables:validate'));
        self::assertStringContainsString('Europe/Rome', Artisan::output());
    }
}
