<?php

namespace App\Policies;

use App\Models\Tool;
use App\Models\User;

/**
 * صلاحيات دليل الأدوات.
 *
 * القراءة العامة مفتوحة (النشط فقط، يفلتره كنترولر بنية المساق).
 * الكتابة والحذف للطاقم، كما في AnnouncementPolicy.
 */
class ToolPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isStaff();
    }

    public function create(User $user): bool
    {
        return $user->isStaff();
    }

    public function update(User $user, Tool $tool): bool
    {
        return $user->isStaff();
    }

    public function delete(User $user, Tool $tool): bool
    {
        return $user->isStaff();
    }
}
