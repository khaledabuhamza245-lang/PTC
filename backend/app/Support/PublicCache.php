<?php

namespace App\Support;

use Closure;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * تخزين مؤقت قصير لبيانات عامة ثقيلة مشتركة بين كل الزوار (كتالوج
 * المواد، إعدادات البرنامج، الفصول، دليل الأدوات) — خطوة ١٠٣.
 *
 * السبب: اختبار حمل حقيقي على الاستضافة (خطوة ١٠٣ بالتوثيق) أظهر أن
 * ٢٠ طلبًا متزامنًا على مسار يستعلم قاعدة البيانات يرفع زمن الاستجابة
 * من ~ثانية واحدة إلى ٦-١٠ ثوانٍ — الاستضافة المشتركة عندها عدد محدود
 * جدًا من عمليات PHP المتزامنة، فالطلبات تصطفّ. أكثر ما يُطلَب بلا أي
 * تخصيص شخصي (كل زائر يرى نفس الجواب بالضبط) هي هذه المسارات الأربعة
 * تحديدًا — تخزينها مؤقتًا لثوانٍ معدودة يعني أن عشرات الطلاب بنفس
 * اللحظة يشتركون بنفس النتيجة الجاهزة بدل كل واحد يُشغّل الاستعلام من
 * جديد.
 *
 * `file` store لا القاعدة الافتراضية للمشروع (`database`) عمدًا: كاش
 * القاعدة نفسه يحتاج استعلام DB، فلا يقلّل الحمل الحقيقي الذي نحاول
 * تخفيفه. `storage/framework/cache/data` مضمونة الكتابة على أي
 * استضافة تشغّل لارافيل أصلًا (لا تحتاج خدمة إضافية كـRedis).
 *
 * كل مفتاح يُمسَح فورًا من أي عملية كتابة تُغيّر بياناته (راجع نداءات
 * forget*() الموزَّعة على متحكمات الطاقم/الأدمن المعنيّة) بدل الانتظار
 * حتى انتهاء المدة — أقصى تأخير ممكن هو المدة القصيرة (٩٠ ثانية) لو
 * نُسي نداء مسح بمسار كتابة مستقبلي، لا بيانات قديمة تدوم صامتة طويلًا.
 *
 * ⚠ لا يُستخدم لأي بيانات فيها تخصيص شخصي (حالة طالب، إعلانات موجَّهة
 * بالجمهور...) — خزّن هذه بمفتاح عام لطالب واحد ستُقدَّم لآخر يطلب
 * بعده خلال نفس المدة، وهو تسريب بيانات لا مجرد خطأ عرض.
 */
class PublicCache
{
    private const TTL_SECONDS = 90;

    private const KEY_COURSES = 'public:courses:index';
    private const KEY_PROGRAM = 'public:program';
    private const KEY_TERMS = 'public:terms';
    private const KEY_TOOLS = 'public:tools';

    public static function rememberCourses(Closure $callback): mixed
    {
        return self::store()->remember(self::KEY_COURSES, self::TTL_SECONDS, $callback);
    }

    public static function forgetCourses(): void
    {
        self::store()->forget(self::KEY_COURSES);
    }

    public static function rememberProgram(Closure $callback): mixed
    {
        return self::store()->remember(self::KEY_PROGRAM, self::TTL_SECONDS, $callback);
    }

    public static function forgetProgram(): void
    {
        self::store()->forget(self::KEY_PROGRAM);
    }

    public static function rememberTerms(Closure $callback): mixed
    {
        return self::store()->remember(self::KEY_TERMS, self::TTL_SECONDS, $callback);
    }

    public static function forgetTerms(): void
    {
        self::store()->forget(self::KEY_TERMS);
    }

    public static function rememberTools(Closure $callback): mixed
    {
        return self::store()->remember(self::KEY_TOOLS, self::TTL_SECONDS, $callback);
    }

    public static function forgetTools(): void
    {
        self::store()->forget(self::KEY_TOOLS);
    }

    /**
     * `file` بالإنتاج، لكن `array` أثناء الاختبارات عمدًا: كل اختبار
     * بهذا المشروع يستخدم RefreshDatabase (قاعدة sqlite جديدة تمامًا
     * لكل دالة اختبار)، بينما ملف كاش حقيقي كان سيبقى عبر ملفات
     * الاختبارات المتتالية (ليس جزءًا من دورة تصفير القاعدة)، فيُرجع
     * لاختبار لاحق بيانات مساقات من اختبار سابق قديمة/غير موجودة أصلًا
     * بقاعدته الجديدة — تعطّل صامت لعشرات الاختبارات القائمة
     * (AcademicPlanTest, AuthorizationPolicyTest, SecurityHardeningTest
     * وغيرها تستدعي GET /api/v1/courses مباشرة). `array` store مرتبط
     * بحاوية التطبيق التي تُبنى من جديد كل دالة اختبار فلا يتسرّب شيء.
     */
    private static function store(): Repository
    {
        return Cache::store(app()->environment('testing') ? 'array' : 'file');
    }
}
