<?php

namespace IlBronza\Timetables\Tests\Feature;

use IlBronza\Timetables\Tests\TestCase;
use Illuminate\Support\Facades\Route;

class CoreWithoutEditorTest extends TestCase
{
    public function test_main_provider_does_not_register_web_routes_or_suite_services(): void
    {
        $this->assertFalse(Route::has('timetables.index'));
        $this->assertFalse($this->app->bound('uikittemplate'));
        $this->assertFalse($this->app->bound('menu'));
    }
}
