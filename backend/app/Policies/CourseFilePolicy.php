<?php

namespace App\Policies;

use App\Models\CourseFile;
use App\Models\User;

/**
 * صلاحيات المحتوى.
 *
 * الطاقم فريق واحد: أي مشرف يعدّل محتوى أي مشرف. قرار مأخوذ بوعي —
 * الفريق صغير ويثق ببعضه، وتقييد الملكية كان سيعرقل العمل لو تغيّب
 * أحدهم. العمود created_by موجود، فتضييق القاعدة لاحقًا تعديل هنا
 * وحده لا بحث في الكنترولرات.
 */
class CourseFilePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isStaff();
    }

    public function create(User $user): bool
    {
        return $user->isStaff();
    }

    public function update(User $user, CourseFile $file): bool
    {
        return $user->isStaff();
    }

    public function delete(User $user, CourseFile $file): bool
    {
        return $user->isStaff();
    }
}
