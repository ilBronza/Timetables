<?php

namespace IlBronza\Timetables\Tests\Fixtures;

class CrudRightsOwner extends Owner
{
    public function userCanShow($user): bool
    {
        return true;
    }

    public function userCanUpdate($user): bool
    {
        return false;
    }
}
