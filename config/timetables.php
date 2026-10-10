<?php

return [
    'models' => [
        'timetable' => [
            'class' => \IlBronza\Timetables\Models\Timetable::class,
            'table' => 'timetables__timetables',
        ],
        'period' => [
            'class' => \IlBronza\Timetables\Models\TimetablePeriod::class,
            'table' => 'timetables__periods',
        ],
        'day' => [
            'class' => \IlBronza\Timetables\Models\TimetableDay::class,
            'table' => 'timetables__days',
        ],
        'interval' => [
            'class' => \IlBronza\Timetables\Models\TimetableInterval::class,
            'table' => 'timetables__intervals',
        ],
    ],

    'timezones' => [config('app.timezone', 'UTC')],
    'max_intervals_per_day' => 32,

    'query' => [
        'max_days' => 31,
        'max_windows' => 64,
        'max_bindings' => 900,
    ],

    'validation_connections' => [],
];
