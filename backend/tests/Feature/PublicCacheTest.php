<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\User;
use App\Support\PublicCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * يثبّت App\Support\PublicCache (خطوة ١٠٣، لتخفيف تزاحم الاستضافة تحت
 * الحمل — راجع claude/step103_hosting_load_test_findings.md بمشروع
 * التوثيق): يخزّن نتيجة الاستدعاء الأول فقط (لا يُعاد تنفيذه)، ويُمسح
 * فورًا عند forget*()، **والأهم**: مسار كتابة حقيقي (إضافة مادة عبر
 * /staff/courses) يجعل /api/v1/courses يعرض المادة الجديدة فورًا بلا
 * أي تأخير — لا بيانات قديمة يراها طالب بعد تعديل الطاقم مباشرة.
 */
class PublicCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_remember_courses_executes_the_callback_only_once(): void
    {
        $calls = 0;

        $first = PublicCache::rememberCourses(function () use (&$calls) {
            $calls++;

            return ['a'];
        });

        $second = PublicCache::rememberCourses(function () use (&$calls) {
            $calls++;

            return ['b'];
        });

        $this->assertSame(['a'], $first);
        $this->assertSame(['a'], $second);
        $this->assertSame(1, $calls);
    }

    public function test_forget_courses_clears_the_cached_value(): void
    {
        PublicCache::rememberCourses(fn () => ['a']);
        PublicCache::forgetCourses();

        $this->assertSame(['b'], PublicCache::rememberCourses(fn () => ['b']));
    }

    public function test_program_terms_and_tools_caches_are_independent_keys(): void
    {
        PublicCache::rememberCourses(fn () => ['courses']);
        PublicCache::rememberProgram(fn () => ['program']);
        PublicCache::rememberTerms(fn () => ['terms']);
        PublicCache::rememberTools(fn () => ['tools']);

        PublicCache::forgetProgram();

        $this->assertSame(['courses'], PublicCache::rememberCourses(fn () => ['changed']));
        $this->assertSame(['new-program'], PublicCache::rememberProgram(fn () => ['new-program']));
        $this->assertSame(['terms'], PublicCache::rememberTerms(fn () => ['changed']));
        $this->assertSame(['tools'], PublicCache::rememberTools(fn () => ['changed']));
    }

    /**
     * الاختبار الأهم: طاقم يضيف مادة عبر /staff/courses، ثم
     * /api/v1/courses العام يعرضها فورًا — يثبّت أن forgetCourses() في
     * StaffCourseController::store() يعمل فعليًا، لا مجرد موجود بالكود.
     */
    public function test_adding_a_course_invalidates_the_public_courses_cache_immediately(): void
    {
        Course::create([
            'key' => 'c_AAA100', 'code' => 'AAA 100', 'name_ar' => 'مساق قديم',
            'name_en' => 'Old', 'year' => 1, 'semester' => 1, 'is_active' => true,
        ]);

        // أول طلب يملأ الكاش بمادة واحدة فقط.
        $before = $this->getJson('/api/v1/courses')->assertOk()->json('data');
        $this->assertCount(1, $before);

        $staff = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($staff);

        $this->postJson('/api/v1/staff/courses', [
            'code' => 'BBB 200', 'name_ar' => 'مساق جديد', 'name_en' => 'New Course', 'year' => 1, 'semester' => 1,
        ])->assertCreated();

        Sanctum::actingAs(User::factory()->create()); // طالب عادي يطلب بعدها

        $after = $this->getJson('/api/v1/courses')->assertOk()->json('data');
        $this->assertCount(2, $after);
    }

    /** نفس الفكرة لتحديث إعدادات البرنامج العامة (/api/v1/program). */
    public function test_updating_settings_invalidates_the_public_program_cache_immediately(): void
    {
        $before = $this->getJson('/api/v1/program')->assertOk()->json('data.total_credit_hours');
        $this->assertSame(142, $before);

        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        $this->putJson('/api/v1/admin/settings', [
            'settings' => ['program.total_credit_hours' => 150],
        ])->assertOk();

        $after = $this->getJson('/api/v1/program')->assertOk()->json('data.total_credit_hours');
        $this->assertSame(150, $after);
    }
}
