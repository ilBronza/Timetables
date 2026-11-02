<?php

use IlBronza\Timetables\Http\Controllers\TimetableController;
use Illuminate\Support\Facades\Route;

Route::middleware(config('timetables.editor.middleware', ['web', 'auth']))
    ->prefix(config('timetables.editor.prefix', 'timetables-management').'/{owner}/{key}')
    ->name('timetables.')
    ->group(function (): void {
        Route::get('', [TimetableController::class, 'index'])->name('index');
        Route::get('create', [TimetableController::class, 'create'])->name('create');
        Route::post('', [TimetableController::class, 'store'])->name('store');
        Route::get('{timetable}', [TimetableController::class, 'show'])->name('show');
        Route::get('{timetable}/edit', [TimetableController::class, 'edit'])->name('edit');
        Route::put('{timetable}', [TimetableController::class, 'update'])->name('update');
        Route::delete('{timetable}', [TimetableController::class, 'destroy'])->name('destroy');
        Route::post('{timetable}/restore', [TimetableController::class, 'restore'])->name('restore');
    });
