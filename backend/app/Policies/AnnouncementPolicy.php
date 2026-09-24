<?php

namespace App\Policies;

use App\Models\Announcement;
use App\Models\User;

/**
 * صلاحيات الإعلانات.
 *
 * القراءة العامة مفتوحة (المنشور النشط فقط، يفلتره الكنترولر العام).
 * الكتابة والحذف للطاقم — فريق واحد، كما في CourseFilePolicy.
 */
class AnnouncementPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isStaff();
    }

    public function create(User $user): bool
    {
        return $user->isStaff();
    }

    public function update(User $user, Announcement $announcement): bool
    {
        return $user->isStaff();
    }

    public function delete(User $user, Announcement $announcement): bool
    {
        return $user->isStaff();
    }
}
