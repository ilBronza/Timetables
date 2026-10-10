<?php

declare(strict_types=1);

namespace IlBronza\Timetables\Domain;

enum TimetableState: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Unconfigured = 'unconfigured';
}
