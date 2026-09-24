<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    /*
     * بدون هذا الـ trait لا يكون $this->authorize() قابلاً للاستدعاء
     * أصلاً — وهو السبب البنيوي لغياب أي تفويض في المشروع: لم تكن
     * الصلاحيات تُفحص إلا بـ EnsureRole على مستوى المسار، فكل من يمرّ
     * منه يستطيع كل شيء داخله.
     */
    use AuthorizesRequests;
}
