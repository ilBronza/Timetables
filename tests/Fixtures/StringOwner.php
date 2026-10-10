<?php

namespace IlBronza\Timetables\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use IlBronza\Timetables\Traits\HasTimetables;

class StringOwner extends Model
{
    use HasTimetables;
    use SoftDeletes;

    protected $table = 'string_owners';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];
}
