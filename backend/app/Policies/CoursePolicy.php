<?php

namespace App\Policies;

use App\Models\Course;
use App\Models\User;

/**
 * صلاحيات المساقات.
 *
 * الطاقم (مدير + مشرف) يبني المساقات ويعدّلها — هذا عمله.
 * الحذف للمدير وحده: حذف مساق يمحو بالـ cascade تسجيلات كل الطلاب فيه
 * وتقدّمهم، فهو قرار لا يُترك لضغطة زر من أي عضو طاقم.
 */
class CoursePolicy
{
    public function viewAny(?User $user): bool
    {
        return true;
    }

    public function view(?User $user, Course $course): bool
    {
        return $course->is_active || ($user?->isStaff() ?? false);
    }

    public function create(User $user): bool
    {
        return $user->isStaff();
    }

    public function update(User $user, Course $course): bool
    {
        return $user->isStaff();
    }

    /**
     * المدير وحده. القرار مأخوذ بوعي: أثر الحذف يتجاوز المساق نفسه
     * إلى بيانات كل طالب مسجَّل فيه.
     */
    public function delete(User $user, Course $course): bool
    {
        return $user->isAdmin();
    }

    public function restore(User $user, Course $course): bool
    {
        return $user->isAdmin();
    }

    /**
     * رؤية سلة المحذوفات.
     *
     * قدرة مستقلة عن viewAny لأن الأخيرة تعني القائمة العامة وهي مفتوحة
     * للجميع — استعمالها هنا كان سيكشف المحذوفات لأي زائر.
     */
    public function viewTrashed(User $user): bool
    {
        return $user->isAdmin();
    }

    /** المحو النهائي لا يُتاح عبر الـ API إطلاقًا. */
    public function forceDelete(User $user, Course $course): bool
    {
        return false;
    }
}
