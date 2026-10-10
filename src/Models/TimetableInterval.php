<?php

namespace IlBronza\Timetables\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TimetableInterval extends TimetableModel
{
    protected static string $configurationKey = 'interval';
    protected static string $defaultTable = 'timetables__intervals';

    protected function casts(): array
    {
        return ['start_minute' => 'integer', 'end_minute' => 'integer'];
    }

    public function day(): BelongsTo
    {
        return $this->belongsTo(TimetableDay::getProjectClassName(), 'day_id');
    }
}
