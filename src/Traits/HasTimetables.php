<?php

namespace IlBronza\Timetables\Traits;

use DateTimeInterface;
use IlBronza\Timetables\Models\Timetable;
use IlBronza\Timetables\Queries\TimetableQuery;
use IlBronza\Timetables\Services\BoundTimetableReader;
use IlBronza\Timetables\Services\TimetableOwnerLifecycle;
use IlBronza\Timetables\Services\TimetableReader;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasTimetables
{
    public static function bootHasTimetables(): void
    {
        static::deleting(function (Model $owner): void {
            if (method_exists($owner, 'isForceDeleting') && ! $owner->isForceDeleting()) {
                return;
            }
            app(TimetableOwnerLifecycle::class)->cleanup($owner);
        });
    }

    public function timetables(): MorphMany
    {
        $class = Timetable::getProjectClassName();
        $related = (new $class)->setConnection($this->getConnectionName());

        return $this->newMorphMany($related->newQuery(), $this,
            $related->qualifyColumn('owner_type'), $related->qualifyColumn('owner_id'), $this->getKeyName());
    }

    public function timetable(string $kind): BoundTimetableReader
    {
        return app(TimetableReader::class)->for($this, $kind);
    }

    public function scopeWhereTimetableActiveAt(Builder $query, DateTimeInterface|string $instant, string $kind): Builder
    {
        return app(TimetableQuery::class)->activeAt($query, $instant, $kind);
    }

    public function scopeWhereTimetableOverlaps(Builder $query, DateTimeInterface|string $from, DateTimeInterface|string $to, string $kind): Builder
    {
        return app(TimetableQuery::class)->overlaps($query, $from, $to, $kind);
    }

    public function deleteWithTimetables(bool $force = false): bool
    {
        return app(TimetableOwnerLifecycle::class)->deleteOwner($this, $force);
    }
}
