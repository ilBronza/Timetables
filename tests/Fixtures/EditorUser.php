<?php

namespace IlBronza\Timetables\Tests\Fixtures;

use IlBronza\AccountManager\Models\User;

class EditorUser extends User
{
    public function isSuperAdmin()
    {
        return true;
    }

    public function hasRole($roles, ?string $guard = null): bool
    {
        return true;
    }

    public function getRolesString(): string
    {
        return 'superadmin';
    }
}
