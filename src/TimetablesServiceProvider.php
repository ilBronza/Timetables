<?php

namespace IlBronza\Timetables;

use Illuminate\Support\ServiceProvider;

class TimetablesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/timetables.php', 'timetables');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/timetables.php' => config_path('timetables.php'),
        ], 'timetables-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'timetables-migrations');

        $this->commands([
            Console\ValidateConfigurationCommand::class,
        ]);
    }
}
