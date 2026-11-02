<?php

namespace IlBronza\Timetables\Editor;

use Illuminate\Database\Eloquent\Model;
use IlBronza\Timetables\Models\Timetable;
use LogicException;

class TimetableEditor
{
    public static function url(Model $owner, string $action = 'index', ?Timetable $timetable = null): string
    {
        $owners = config('timetables.editor.owners', []);
        // Prefer the actual model: a base-class alias can have broader scopes.
        $exact = array_filter($owners, static fn ($class) => $class === $owner::class);
        foreach ($exact + $owners as $alias => $class) {
            if ($owner instanceof $class) {
                return route('timetables.'.$action, array_filter([
                    'owner' => $alias, 'key' => $owner->getKey(), 'timetable' => $timetable?->getKey(),
                ], static fn ($value) => $value !== null));
            }
        }

        throw new LogicException('Register the owner in timetables.editor.owners before linking its editor.');
    }

    /** Same fetcher interface used by CRUD's SeoFormHelper. */
    public static function addFetcher($form, Model $owner, string $position = 'outherBottom')
    {
        return $form->addFetcherByUrl(static::url($owner), $position, [
            'title' => __('timetables::editor.title'),
        ]);
    }
}
