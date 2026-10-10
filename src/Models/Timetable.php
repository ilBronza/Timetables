<?php

namespace IlBronza\Timetables\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Timetable extends TimetableModel
{
    use SoftDeletes;

    protected static string $configurationKey = 'timetable';
    protected static string $defaultTable = 'timetables__timetables';

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'revision' => 'integer'];
    }

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    public function periods(): HasMany
    {
        return $this->hasMany(TimetablePeriod::getProjectClassName(), 'timetable_id')->orderBy('valid_from')->orderBy('id');
    }
}
