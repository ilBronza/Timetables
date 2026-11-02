<?php

namespace IlBronza\Timetables\Tests\Fixtures;

use Illuminate\Database\Eloquent\Builder;

class ScopedOwner extends Owner
{
    protected static function booted(): void
    {
        static::addGlobalScope('tenant', fn (Builder $query) => $query->where('tenant_id', 1));
    }
}
