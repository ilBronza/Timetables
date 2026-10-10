<?php

namespace IlBronza\Timetables\Tests\Feature;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use IlBronza\Timetables\Domain\InvalidTimetable;
use IlBronza\Timetables\Exceptions\TimetableConflict;
use IlBronza\Timetables\Models\Timetable;
use IlBronza\Timetables\Models\TimetableDay;
use IlBronza\Timetables\Models\TimetableInterval;
use IlBronza\Timetables\Models\TimetablePeriod;
use IlBronza\Timetables\Services\TimetableOwnerLifecycle;
use IlBronza\Timetables\Services\TimetableWriter;
use IlBronza\Timetables\Tests\Fixtures\Owner;
use IlBronza\Timetables\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class WriterTest extends TestCase
{
    private function owner(): Owner
    {
        return Owner::create(['name' => 'Office']);
    }

    private function definition(): array
    {
        return ['timezone' => 'UTC', 'periods' => [['weekly' => [1 => [[540, 780], [1320, 1560]]], 'exceptions' => ['2026-12-25' => []]]]];
    }

    public function test_creates_normalized_aggregate_and_preserves_empty_exception(): void
    {
        $data = $this->definition();
        $data['periods'][0]['weekly'][1] = [[1320, 1560], [540, 780], [540, 780], [0, 1440]];
        $header = app(TimetableWriter::class)->replace($this->owner(), 'opening', $data);
        $this->assertSame(1, $header->revision);
        $this->assertTrue($header->enabled);
        $this->assertCount(1, $header->periods);
        $this->assertCount(2, $header->periods[0]->days);
        $day = $header->periods[0]->days->firstWhere('weekday', 1);
        $this->assertSame([[0, 1440], [540, 780], [1320, 1560]], $day->intervals->map(fn ($i) => [$i->start_minute, $i->end_minute])->all());
        $this->assertCount(0, $header->periods[0]->days->firstWhere('on_date', '2026-12-25')->intervals);
        $this->assertSame($header->owner_id, (string) $header->owner->getKey());
        $this->assertSame($header->id, $day->period->timetable->id);
        $this->assertSame($day->id, $day->intervals[0]->day->id);
    }

    public function test_updates_require_revision_and_replace_children_atomically(): void
    {
        $owner = $this->owner();
        $writer = app(TimetableWriter::class);
        $header = $writer->replace($owner, 'opening', $this->definition());
        $oldPeriod = $header->periods[0]->id;
        foreach ([null, 0, 2] as $revision) {
            try {
                $writer->replace($owner, 'opening', $this->definition(), $revision);
                $this->fail('Missing or stale revision was accepted.');
            } catch (TimetableConflict) {
                $this->assertSame(1, $header->fresh()->revision);
                $this->assertTrue(TimetablePeriod::whereKey($oldPeriod)->exists());
            }
        }
        $updated = $writer->replace($owner, 'opening', ['timezone' => 'Europe/Rome', 'enabled' => false, 'name' => 'Summer', 'periods' => []], 1);
        $this->assertSame($header->id, $updated->id);
        $this->assertSame(2, $updated->revision);
        $this->assertFalse($updated->enabled);
        $this->assertSame(0, TimetablePeriod::count());
        $this->assertSame(0, TimetableDay::count());
        $this->assertSame(0, TimetableInterval::count());
    }

    public function test_lifecycle_revision_soft_delete_restore_and_force_cascade(): void
    {
        $owner = $this->owner();
        $writer = app(TimetableWriter::class);
        $header = $writer->replace($owner, 'opening', $this->definition());
        $this->assertSame(2, $writer->setEnabled($owner, 'opening', false, 1)->revision);
        $this->assertSame(3, $writer->setEnabled($owner, 'opening', true, 2)->revision);
        $deleted = $writer->delete($owner, 'opening', 3);
        $this->assertTrue($deleted->trashed());
        $this->assertSame(4, $deleted->revision);
        $this->assertSame(0, Timetable::count());
        $this->assertSame(1, TimetablePeriod::count());
        try {
            $writer->replace($owner, 'opening', $this->definition());
            $this->fail('Recreation of a deleted timetable was accepted.');
        } catch (TimetableConflict) {
            $this->assertSame(1, Timetable::withTrashed()->count());
        }
        $restored = $writer->restore($owner, 'opening', 4);
        $this->assertSame($header->id, $restored->id);
        $this->assertSame(5, $restored->revision);
        $this->assertTrue($restored->enabled);
        $writer->forceDelete($owner, 'opening', 5);
        $this->assertSame(0, Timetable::withTrashed()->count());
        $this->assertSame(0, TimetablePeriod::count());
        $this->assertSame(0, TimetableDay::count());
        $this->assertSame(0, TimetableInterval::count());
        $this->assertNotSame($header->id, $writer->replace($owner, 'opening', $this->definition())->id);
    }

    public function test_owner_soft_delete_preserves_and_force_delete_cleans_all_kinds(): void
    {
        $owner = $this->owner();
        $writer = app(TimetableWriter::class);
        $writer->replace($owner, 'opening', $this->definition());
        $writer->replace($owner, 'working', $this->definition());
        app(TimetableOwnerLifecycle::class)->deleteOwner($owner);
        $this->assertSame(2, Timetable::count());
        $owner->restore();
        $writer->delete($owner, 'working', 1);
        app(TimetableOwnerLifecycle::class)->deleteOwner($owner, true);
        $this->assertSame(0, Timetable::withTrashed()->count());
        $this->assertSame(0, TimetablePeriod::count());
        $this->assertSame(0, TimetableDay::count());
        $this->assertSame(0, TimetableInterval::count());
    }

    public function test_cleanup_can_remove_orphans_after_explicit_raw_owner_deletion(): void
    {
        $owner = $this->owner();
        app(TimetableWriter::class)->replace($owner, 'opening', $this->definition());
        $owner->getConnection()->table('owners')->where('id', $owner->id)->delete();
        app(TimetableOwnerLifecycle::class)->cleanup($owner);
        $this->assertSame(0, Timetable::withTrashed()->count());
        $this->assertSame(0, TimetableInterval::count());
    }

    public function test_unique_owner_and_kind_constraint_includes_soft_deleted_headers(): void
    {
        $owner = $this->owner();
        $header = app(TimetableWriter::class)->replace($owner, 'opening', $this->definition());
        app(TimetableWriter::class)->delete($owner, 'opening', 1);
        $this->expectException(QueryException::class);
        Timetable::create([
            'owner_type' => $header->owner_type, 'owner_id' => $header->owner_id,
            'kind' => 'opening', 'timezone' => 'UTC', 'revision' => 1,
        ]);
    }

    public function test_custom_tables_prefixes_and_related_classes_keep_owner_connection(): void
    {
        $connection = $this->app['db']->connection();
        $originalPrefix = $connection->getTablePrefix();
        $originalModels = config('timetables.models');
        $migration = require __DIR__.'/../../database/migrations/2026_10_09_000000_create_timetables_tables.php';
        config(['timetables.models.period.class' => WrongConnectionPeriod::class]);
        foreach (['timetable', 'period', 'day', 'interval'] as $key) {
            config(['timetables.models.'.$key.'.table' => 'custom_'.$key]);
        }
        try {
            foreach (['first_', 'second_'] as $prefix) {
                $connection->setTablePrefix($prefix);
                $connection->getSchemaGrammar()->setTablePrefix($prefix);
                $connection->getSchemaBuilder()->create('owners', function (Blueprint $table) {
                    $table->increments('id');
                    $table->string('name');
                    $table->integer('tenant_id')->default(1);
                    $table->timestamps();
                    $table->softDeletes();
                });
                $migration->up();
                $owner = $this->owner();
                $header = app(TimetableWriter::class)->replace($owner, 'opening', $this->definition());
                $period = $header->periods[0];
                $this->assertInstanceOf(WrongConnectionPeriod::class, $period);
                $this->assertSame($owner->getConnection()->getName(), $period->getConnection()->getName());
                $this->assertSame('custom_period', $period->getTable());
                $this->assertCount(2, $period->days);
            }
            // Both prefixed schemas coexist; SQLite index names must not collide.
            foreach (['first_', 'second_'] as $prefix) {
                $connection->setTablePrefix($prefix);
                $connection->getSchemaGrammar()->setTablePrefix($prefix);
                $migration->down();
                $this->assertFalse($connection->getSchemaBuilder()->hasTable('custom_timetable'));
            }
        } finally {
            $connection->setTablePrefix($originalPrefix);
            $connection->getSchemaGrammar()->setTablePrefix($originalPrefix);
            config(['timetables.models' => $originalModels]);
        }
    }

    public function test_owner_deletion_veto_rolls_back_header_cleanup(): void
    {
        $owner = $this->owner();
        app(TimetableWriter::class)->replace($owner, 'opening', $this->definition());
        Owner::deleting(fn () => false);
        $this->assertFalse(app(TimetableOwnerLifecycle::class)->deleteOwner($owner, true));
        $this->assertTrue(Owner::whereKey($owner->id)->exists());
        $this->assertSame(1, Timetable::withTrashed()->count());
        $this->assertSame(2, TimetableInterval::count());
    }

    public function test_header_save_veto_rolls_back_revision_and_preserves_existing_children(): void
    {
        $owner = $this->owner();
        $writer = app(TimetableWriter::class);
        $header = $writer->replace($owner, 'opening', $this->definition());
        $periodId = $header->periods[0]->id;
        Timetable::saving(fn () => false);
        try {
            $writer->replace($owner, 'opening', ['timezone' => 'UTC', 'periods' => []], 1);
            $this->fail('Expected save cancellation.');
        } catch (TimetableConflict) {
            $this->assertSame(1, $header->fresh()->revision);
            $this->assertTrue(TimetablePeriod::whereKey($periodId)->exists());
            $this->assertSame(2, TimetableInterval::count());
        }
    }

    public function test_empty_exception_creation_veto_rolls_back_entire_aggregate(): void
    {
        TimetableDay::creating(fn ($day) => $day->on_date === null ? null : false);
        try {
            app(TimetableWriter::class)->replace($this->owner(), 'opening', $this->definition());
            $this->fail('Expected empty exception cancellation.');
        } catch (TimetableConflict) {
            $this->assertSame(0, Timetable::withTrashed()->count());
            $this->assertSame(0, TimetablePeriod::count());
            $this->assertSame(0, TimetableDay::count());
            $this->assertSame(0, TimetableInterval::count());
        }
    }

    public function test_owner_scopes_are_respected_by_writes(): void
    {
        $owner = $this->owner();
        $owner->delete();
        $this->expectException(ModelNotFoundException::class);
        app(TimetableWriter::class)->replace($owner, 'opening', $this->definition());
    }

    public function test_failure_during_child_write_rolls_back_header_and_previous_children(): void
    {
        $owner = $this->owner();
        $writer = app(TimetableWriter::class);
        $header = $writer->replace($owner, 'opening', $this->definition());
        $periodId = $header->periods[0]->id;
        TimetableInterval::creating(function () { throw new \RuntimeException('Simulated write failure'); });
        try {
            $writer->replace($owner, 'opening', $this->definition(), 1);
            $this->fail('Expected child write failure.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Simulated write failure', $error->getMessage());
            $this->assertSame(1, $header->fresh()->revision);
            $this->assertTrue(TimetablePeriod::whereKey($periodId)->exists());
            $this->assertSame(2, TimetableInterval::count());
        } finally {
            TimetableInterval::flushEventListeners();
        }
    }

    public function test_disjoint_periods_are_sorted_and_adjacent_days_are_accepted(): void
    {
        $data = ['timezone' => 'UTC', 'periods' => [
            ['valid_from' => '2027-01-01', 'weekly' => []],
            ['valid_to' => '2026-12-31', 'weekly' => []],
        ]];
        $header = app(TimetableWriter::class)->replace($this->owner(), 'opening', $data);
        $this->assertNull($header->periods[0]->valid_from);
        $this->assertSame('2026-12-31', $header->periods[0]->valid_to);
        $this->assertSame('2027-01-01', $header->periods[1]->valid_from);
    }

    #[DataProvider('invalidDefinitions')]
    public function test_invalid_definitions_leave_database_empty(array $definition): void
    {
        try {
            app(TimetableWriter::class)->replace($this->owner(), 'opening', $definition);
            $this->fail('Invalid definition was accepted.');
        } catch (InvalidTimetable) {
            $this->assertSame(0, Timetable::withTrashed()->count());
            $this->assertSame(0, TimetablePeriod::count());
            $this->assertSame(0, TimetableDay::count());
            $this->assertSame(0, TimetableInterval::count());
        }
    }

    public static function invalidDefinitions(): array
    {
        return [
            'unknown field' => [['timezone' => 'UTC', 'periods' => [], 'enabeld' => true]],
            'unknown period field' => [['timezone' => 'UTC', 'periods' => [['weeky' => []]]]],
            'unconfigured timezone' => [['timezone' => 'Asia/Tokyo', 'periods' => []]],
            'wrong enabled type' => [['timezone' => 'UTC', 'enabled' => 'false', 'periods' => []]],
            'null weekly' => [['timezone' => 'UTC', 'periods' => [['weekly' => null]]]],
            'null exceptions' => [['timezone' => 'UTC', 'periods' => [['exceptions' => null]]]],
            'null enabled' => [['timezone' => 'UTC', 'enabled' => null, 'periods' => []]],
            'not list' => [['timezone' => 'UTC', 'periods' => ['a' => []]]],
            'reversed dates' => [['timezone' => 'UTC', 'periods' => [['valid_from' => '2026-02-02', 'valid_to' => '2026-02-01']]]],
            'invalid date' => [['timezone' => 'UTC', 'periods' => [['valid_from' => '2026-02-30']]]],
            'overlap inclusive' => [['timezone' => 'UTC', 'periods' => [['valid_to' => '2026-02-02'], ['valid_from' => '2026-02-02']]]],
            'duplicate unbounded periods' => [['timezone' => 'UTC', 'periods' => [[], []]]],
            'exception outside period' => [['timezone' => 'UTC', 'periods' => [['valid_from' => '2026-01-01', 'exceptions' => ['2025-12-31' => []]]]]],
            'invalid weekday' => [['timezone' => 'UTC', 'periods' => [['weekly' => [0 => []]]]]],
            'noninteger minute' => [['timezone' => 'UTC', 'periods' => [['weekly' => [1 => [['540', 780]]]]]]],
            'too long interval' => [['timezone' => 'UTC', 'periods' => [['weekly' => [1 => [[0, 1441]]]]]]],
            'zero interval' => [['timezone' => 'UTC', 'periods' => [['weekly' => [1 => [[540, 540]]]]]]],
            'extra interval property' => [['timezone' => 'UTC', 'periods' => [['weekly' => [1 => [[540, 780, 900]]]]]]],
            'too many intervals' => [['timezone' => 'UTC', 'periods' => [['weekly' => [1 => array_map(fn ($i) => [$i, $i + 1], range(0, 32))]]]]],
        ];
    }

    #[DataProvider('databaseViolations')]
    public function test_database_rejects_local_invariant_violations(string $model, array $attributes): void
    {
        $header = app(TimetableWriter::class)->replace($this->owner(), 'opening', $this->definition());
        $period = $header->periods[0];
        if ($model === TimetablePeriod::class) {
            $attributes['timetable_id'] = $header->id;
        } elseif ($model === TimetableDay::class) {
            $attributes['period_id'] = $period->id;
        } else {
            $attributes['day_id'] = $period->days[0]->id;
        }
        $this->expectException(QueryException::class);
        $model::create($attributes);
    }

    public static function databaseViolations(): array
    {
        return [
            'both day selectors' => [TimetableDay::class, ['weekday' => 2, 'on_date' => '2026-12-26']],
            'neither day selector' => [TimetableDay::class, []],
            'weekday outside range' => [TimetableDay::class, ['weekday' => 8]],
            'fractional weekday' => [TimetableDay::class, ['weekday' => 2.5]],
            'reversed period' => [TimetablePeriod::class, ['valid_from' => '2026-12-31', 'valid_to' => '2026-01-01']],
            'negative interval' => [TimetableInterval::class, ['start_minute' => -1, 'end_minute' => 1]],
            'zero duration' => [TimetableInterval::class, ['start_minute' => 100, 'end_minute' => 100]],
            'long duration' => [TimetableInterval::class, ['start_minute' => 0, 'end_minute' => 1441]],
            'fractional minutes' => [TimetableInterval::class, ['start_minute' => 0.5, 'end_minute' => 1]],
        ];
    }
}

/** A host override cannot send aggregate children to another database. */
class WrongConnectionPeriod extends TimetablePeriod
{
    protected $connection = 'this_connection_must_never_be_used';
}
