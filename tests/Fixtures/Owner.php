<?php

namespace IlBronza\Timetables\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use IlBronza\Timetables\Traits\HasTimetables;

class Owner extends Model
{
    use HasTimetables;
    use SoftDeletes;

    protected $table = 'owners';

    protected $guarded = [];
}
