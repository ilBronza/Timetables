<?php

namespace IlBronza\Timetables\Editor;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use IlBronza\Timetables\Traits\HasTimetables;

class OwnerResolver
{
    public function resolve(string $alias, string $key, string $ability): Model
    {
        $class = config('timetables.editor.owners', [])[$alias] ?? null;
        abort_unless(is_string($class) && is_subclass_of($class, Model::class), 404);
        abort_unless(in_array(HasTimetables::class, class_uses_recursive($class), true), 404);

        // Keep the owner's connection, soft-delete and tenant/global scopes.
        $owner = (new $class)->newQuery()->whereKey($key)->firstOrFail();
        $this->authorize($owner, $ability);

        return $owner;
    }

    public function allows(Model $owner, string $ability): bool
    {
        if (Gate::getPolicyFor($owner) !== null) {
            return Gate::allows($ability, $owner);
        }

        $method = $ability === 'view' ? 'userCanShow' : 'userCanUpdate';

        return method_exists($owner, $method)
            ? (bool) $owner->$method(auth()->user())
            : Gate::allows($ability, $owner);
    }

    public function authorize(Model $owner, string $ability): void
    {
        abort_unless($this->allows($owner, $ability), 403);
    }
}
