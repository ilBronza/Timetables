<?php

declare(strict_types=1);

namespace IlBronza\Timetables\Tests\Unit;

use IlBronza\Timetables\Domain\Instant;
use IlBronza\Timetables\Domain\LocalWindow;
use IlBronza\Timetables\Domain\LocalWindowFactory;
use IlBronza\Timetables\Domain\QueryTooBroad;
use PHPUnit\Framework\TestCase;

final class LocalWindowFactoryTest extends TestCase
{
    public function test_spring_and_autumn_windows_keep_gap_and_repeated_hour(): void
    {
        $factory = new LocalWindowFactory();
        $spring = $factory->between('2026-03-29T00:30Z', '2026-03-29T01:30Z', ['Europe/Rome']);
        $fall = $factory->between('2026-10-25T00:30Z', '2026-10-25T01:30Z', ['Europe/Rome']);
        $minutes = fn (LocalWindow $w): array => [intdiv($w->startMicros, Instant::MINUTE), intdiv($w->endMicros, Instant::MINUTE)];
        self::assertSame([[90, 120], [180, 210]], array_map($minutes, $spring));
        self::assertSame([[150, 180], [120, 150]], array_map($minutes, $fall));
    }

    public function test_apia_does_not_generate_a_skipped_civil_date(): void
    {
        $windows = (new LocalWindowFactory())->between('2011-12-30T09:30Z', '2011-12-30T10:30Z', ['Pacific/Apia']);
        self::assertSame(['2011-12-29', '2011-12-31'], array_map(fn (LocalWindow $w): string => $w->date->value, $windows));
    }

    public function test_midnight_and_microsecond_are_preserved_before_epoch(): void
    {
        $windows = (new LocalWindowFactory())->between('1969-12-31T23:59:59.999999Z', '1970-01-01T00:00:00.000001Z', ['UTC', 'UTC']);
        self::assertCount(2, $windows);
        self::assertSame(Instant::DAY - 1, $windows[0]->startMicros);
        self::assertSame(Instant::DAY, $windows[0]->endMicros);
        self::assertSame(-1, $windows[0]->utcStartMicros);
        self::assertSame(0, $windows[1]->startMicros);
        self::assertSame(1, $windows[1]->endMicros);
    }

    public function test_equal_endpoint_offsets_do_not_hide_interior_transitions(): void
    {
        $windows = (new LocalWindowFactory())->between('2026-01-01T00:00Z', '2026-12-01T00:00Z', ['Europe/Rome']);
        $spring = array_values(array_filter($windows, fn (LocalWindow $w): bool => $w->date->value === '2026-03-29'));
        $fall = array_values(array_filter($windows, fn (LocalWindow $w): bool => $w->date->value === '2026-10-25'));
        self::assertCount(2, $spring);
        self::assertCount(2, $fall);
        self::assertSame(180 * Instant::MINUTE, $spring[1]->startMicros);
        self::assertSame(120 * Instant::MINUTE, $fall[1]->startMicros);
    }

    public function test_window_budget_fails_without_truncating(): void
    {
        $this->expectException(QueryTooBroad::class);
        (new LocalWindowFactory())->between('2026-01-01T12:00Z', '2026-01-02T12:00Z', ['UTC'], 1);
    }
}
