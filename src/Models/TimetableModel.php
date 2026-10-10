<?php

namespace IlBronza\Timetables\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

abstract class TimetableModel extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected static string $configurationKey;
    protected static string $defaultTable;

    public static function getProjectClassName(): string
    {
        return config('timetables.models.'.static::$configurationKey.'.class', static::class);
    }

    protected function newRelatedInstance($class)
    {
        return (new $class)->setConnection($this->getConnectionName());
    }

    public function getTable()
    {
        return $this->table ?? config('timetables.models.'.static::$configurationKey.'.table', static::$defaultTable);
    }
}
