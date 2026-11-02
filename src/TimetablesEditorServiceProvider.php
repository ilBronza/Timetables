<?php

namespace IlBronza\Timetables;

use Illuminate\Support\ServiceProvider;

/** Register explicitly in the host application to enable the web editor. */
class TimetablesEditorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->register(TimetablesServiceProvider::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'timetables');
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'timetables');
        $this->loadRoutesFrom(__DIR__.'/../routes/editor.php');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/timetables'),
            ], 'timetables-views');
        }
    }
}
