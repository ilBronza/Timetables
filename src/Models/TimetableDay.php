<?php

namespace IlBronza\Timetables\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TimetableDay extends TimetableModel
{
    protected static string $configurationKey = 'day';
    protected static string $defaultTable = 'timetables__days';

    protected function casts(): array
    {
        return ['weekday' => 'integer'];
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(TimetablePeriod::getProjectClassName(), 'period_id');
    }

    public function intervals(): HasMany
    {
        return $this->hasMany(TimetableInterval::getProjectClassName(), 'day_id')->orderBy('start_minute')->orderBy('end_minute');
    }
}
