<?php

namespace IlBronza\Timetables\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use IlBronza\Timetables\Traits\HasTimetables;

class OtherOwner extends Model
{
    use HasTimetables;
    use SoftDeletes;

    protected $table = 'other_owners';

    protected $guarded = [];
}
