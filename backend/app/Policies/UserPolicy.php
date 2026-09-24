<?php

namespace App\Policies;

use App\Models\User;

/**
 * صلاحيات المستخدمين.
 *
 * إدارة الحسابات للمدير وحده. المشرف يقرأ الطلاب فقط — والتضييق على
 * مستوى الاستعلام في Staff\UserController::index لأن الـ policy تحكم
 * على سجل بعينه لا على نطاق القائمة.
 *
 * الحماية الأخيرة هنا: المدير لا يحذف نفسه ولا يخفّض نفسه، وإلا خلا
 * النظام من مدير بلا طريقة لاستعادة الصلاحية (التسجيل يفرض student،
 * وإنشاء المستخدمين يمنع admin).
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isStaff();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, User $target): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, User $target): bool
    {
        return $user->isAdmin() && $user->id !== $target->id;
    }

    public function updateRole(User $user, User $target): bool
    {
        return $user->isAdmin() && $user->id !== $target->id;
    }
}
