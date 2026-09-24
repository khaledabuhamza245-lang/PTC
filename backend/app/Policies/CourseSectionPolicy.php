<?php

namespace App\Policies;

use App\Models\CourseSection;
use App\Models\User;

/**
 * صلاحيات الأقسام والتصنيفات (السجل نفسه يؤدي الدورين حسب course_unit_id).
 *
 * بناء البنية عمل الطاقم. الحذف تسلسلي — يحذف الوحدات والتصنيفات
 * والمحتوى تحته — لكنه محصور في مساق واحد فلا يمس بيانات الطلاب،
 * بخلاف حذف المساق نفسه المحصور بالمدير.
 */
class CourseSectionPolicy
{
    public function create(User $user): bool
    {
        return $user->isStaff();
    }

    public function update(User $user, CourseSection $section): bool
    {
        return $user->isStaff();
    }

    public function delete(User $user, CourseSection $section): bool
    {
        return $user->isStaff();
    }
}
