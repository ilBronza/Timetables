<?php

namespace IlBronza\Timetables\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TimetablePeriod extends TimetableModel
{
    protected static string $configurationKey = 'period';
    protected static string $defaultTable = 'timetables__periods';

    public function timetable(): BelongsTo
    {
        return $this->belongsTo(Timetable::getProjectClassName(), 'timetable_id');
    }

    public function days(): HasMany
    {
        return $this->hasMany(TimetableDay::getProjectClassName(), 'period_id')->orderBy('weekday')->orderBy('on_date');
    }
}
