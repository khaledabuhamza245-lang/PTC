<?php

namespace App\Policies;

use App\Models\CourseUnit;
use App\Models\User;

/**
 * صلاحيات الوحدات — عمل الطاقم، كما الأقسام.
 */
class CourseUnitPolicy
{
    public function create(User $user): bool
    {
        return $user->isStaff();
    }

    public function update(User $user, CourseUnit $unit): bool
    {
        return $user->isStaff();
    }

    public function delete(User $user, CourseUnit $unit): bool
    {
        return $user->isStaff();
    }
}
