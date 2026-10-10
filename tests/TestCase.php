<?php

namespace IlBronza\Timetables\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [\IlBronza\Timetables\TimetablesServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('timetables.timezones', ['UTC', 'Europe/Rome', 'America/New_York', 'Australia/Lord_Howe', 'Pacific/Apia']);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('owners', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('name');
            $table->unsignedBigInteger('tenant_id')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('other_owners', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('name');
            $table->unsignedBigInteger('tenant_id')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('string_owners', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('name');
            $table->unsignedBigInteger('tenant_id')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });

        foreach (glob(__DIR__.'/../database/migrations/*.php') ?: [] as $migrationPath) {
            $migration = require $migrationPath;
            $migration->up();
        }
    }
}
