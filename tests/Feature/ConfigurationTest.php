<?php

namespace IlBronza\Timetables\Tests\Feature;

use IlBronza\Timetables\Models\Timetable;
use IlBronza\Timetables\Models\TimetablePeriod;
use IlBronza\Timetables\Services\TimetableWriter;
use IlBronza\Timetables\Tests\Fixtures\Owner;
use IlBronza\Timetables\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class ConfigurationTest extends TestCase
{
    private function definition(): array
    {
        return ['timezone' => 'Europe/Rome', 'periods' => [['weekly' => [1 => [[540, 780]]]]]];
    }

    public function test_aggregate_relations_reader_scopes_and_cleanup_use_the_owner_connection(): void
    {
        config(['database.connections.branch' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => 'branch_', 'foreign_key_constraints' => true,
        ]]);
        $branch = DB::connection('branch');
        $branch->getSchemaBuilder()->create('owners', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('tenant_id')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });
        $original = DB::getDefaultConnection();
        try {
            DB::setDefaultConnection('branch');
            $migration = require glob(__DIR__.'/../../database/migrations/*.php')[0];
            $migration->up();
        } finally {
            DB::setDefaultConnection($original);
        }

        // Customized classes must not accidentally route aggregate rows elsewhere.
        config(['timetables.models.timetable.class' => CustomTimetable::class,
            'timetables.models.period.class' => CustomPeriod::class]);
        $owner = Owner::on('branch')->create(['name' => 'Branch owner']);
        $header = app(TimetableWriter::class)->replace($owner, 'working', $this->definition());
        self::assertInstanceOf(CustomTimetable::class, $header);
        self::assertInstanceOf(CustomPeriod::class, $header->periods[0]);
        self::assertSame('branch', $header->periods[0]->days[0]->intervals[0]->getConnectionName());
        self::assertSame('branch', $header->periods[0]->timetable->getConnectionName());
        self::assertSame('branch', $header->owner->getConnectionName());
        self::assertSame(0, Timetable::on($original)->count());
        self::assertSame(1, $owner->timetables()->count());
        self::assertTrue($owner->timetable('working')->isActiveAt('2026-01-05T08:30Z'));
        self::assertSame(1, Owner::on('branch')->whereTimetableActiveAt('2026-01-05T08:30Z', 'working')->count());
        self::assertSame(0, Owner::on($original)->whereTimetableActiveAt('2026-01-05T08:30Z', 'working')->count());
        self::assertSame(0, Artisan::call('timetables:validate', ['--connection' => ['branch']]));
        config(['timetables.timezones' => ['UTC']]);
        self::assertSame(1, Artisan::call('timetables:validate', ['--connection' => ['branch']]));
        $owner->deleteWithTimetables(force: true);
        self::assertSame(0, $branch->table('timetables__timetables')->count());
        self::assertSame(0, $branch->table('timetables__intervals')->count());
        DB::purge('branch');
    }

    public function test_custom_table_names_are_used_by_migrations_models_queries_and_rollback(): void
    {
        foreach (['timetable', 'period', 'day', 'interval'] as $key) {
            config(['timetables.models.'.$key.'.table' => 'custom_'.$key]);
        }
        $migration = require glob(__DIR__.'/../../database/migrations/*.php')[0];
        $migration->up();
        $owner = Owner::create(['name' => 'Custom tables']);
        app(TimetableWriter::class)->replace($owner, 'working', $this->definition());
        self::assertSame(1, DB::table('custom_timetable')->count());
        self::assertTrue($owner->timetable('working')->isActiveAt('2026-01-05T08:30Z'));
        self::assertSame(1, Owner::whereTimetableActiveAt('2026-01-05T08:30Z', 'working')->count());
        $migration->down();
        self::assertFalse(DB::getSchemaBuilder()->hasTable('custom_timetable'));
        self::assertFalse(DB::getSchemaBuilder()->hasTable('custom_interval'));
        self::assertTrue(DB::getSchemaBuilder()->hasTable('timetables__timetables'));
    }

    public function test_reader_retries_if_a_commit_occurs_between_aggregate_queries(): void
    {
        $owner = Owner::create(['name' => 'Concurrent read']);
        app(TimetableWriter::class)->replace($owner, 'working', $this->definition());
        $fired = false;
        Timetable::retrieved(function (Timetable $header) use (&$fired, $owner): void {
            if ($fired) {
                return;
            }
            $fired = true;
            app(TimetableWriter::class)->replace($owner, 'working', [
                'timezone' => 'Europe/Rome', 'periods' => [['weekly' => [1 => [[600, 660]]]]],
            ], $header->revision);
        });
        try {
            self::assertFalse($owner->timetable('working')->isActiveAt('2026-01-05T08:30Z'));
            self::assertTrue($fired);
            self::assertSame(2, $owner->timetables()->first()->revision);
        } finally {
            Timetable::flushEventListeners();
        }
    }

    public function test_query_can_evaluate_the_first_supported_civil_date_without_prior_year_carry(): void
    {
        config(['timetables.timezones' => ['UTC']]);
        $owner = Owner::create(['name' => 'Gregorian boundary']);
        app(TimetableWriter::class)->replace($owner, 'working', ['timezone' => 'UTC', 'periods' => [
            ['weekly' => [1 => [[540, 600]], 7 => [[1320, 1560]]]],
        ]]);
        self::assertTrue(Owner::whereTimetableActiveAt('0001-01-01T09:00Z', 'working')->exists());
        self::assertFalse(Owner::whereTimetableActiveAt('0001-01-01T01:00Z', 'working')->exists());
    }
}

class CustomTimetable extends Timetable
{
    protected $connection = 'must_not_be_used';
}

class CustomPeriod extends TimetablePeriod
{
    protected $connection = 'must_not_be_used';
}
