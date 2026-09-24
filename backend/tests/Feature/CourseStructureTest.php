<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseSection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CourseStructureTest extends TestCase
{
    use RefreshDatabase;

    /**
     * الوحدة تُنشأ دائمًا داخل قسم رئيسي — storeUnit يشترط course_section_id
     * ولوحة الأدمن ترسله دومًا. القسم الرئيسي هو ما كان course_unit_id فيه null.
     */
    private function createSection(Course $course, string $title): int
    {
        return $this->postJson('/api/v1/staff/courses/'.$course->key.'/sections', [
            'title' => $title, 'is_published' => true,
        ])->assertCreated()->json('data.id');
    }

    public function test_staff_can_build_course_structure_and_student_can_complete_content(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $student = User::factory()->create(['role' => 'student']);
        $course = Course::create([
            'key' => 'c_QA101', 'code' => 'QA101', 'name_ar' => 'مادة اختبار',
            'name_en' => 'QA Course', 'year' => 1, 'semester' => 1, 'is_active' => true,
        ]);

        Sanctum::actingAs($admin);

        $sectionId = $this->createSection($course, 'القسم الأول');

        $unitId = $this->postJson('/api/v1/staff/courses/'.$course->key.'/units', [
            'course_section_id' => $sectionId,
            'title' => 'الوحدة الأولى', 'is_published' => true,
        ])->assertCreated()->json('data.id');

        $categoryId = $this->postJson('/api/v1/staff/courses/'.$course->key.'/sections', [
            'course_unit_id' => $unitId,
            'title' => 'المحاضرات وملفاتها',
            'counts_toward_progress' => true,
            'is_published' => true,
        ])->assertCreated()->json('data.id');

        $contentId = $this->postJson('/api/v1/staff/course-files', [
            'course_id' => $course->id,
            'course_unit_id' => $unitId,
            'course_section_id' => $categoryId,
            'title' => 'محاضرة الوحدة الأولى',
            'kind' => 'youtube',
            'external_url' => 'https://youtube.com/watch?v=qa',
            'counts_toward_progress' => true,
            'is_published' => true,
            'visibility' => 'public',
        ])->assertCreated()->json('data.id');

        Sanctum::actingAs($student);

        $this->getJson('/api/v1/courses/'.$course->key.'/structure')
            ->assertOk()
            ->assertJsonPath('data.units.0.categories.0.contents.0.id', $contentId)
            ->assertJsonPath('data.progress.total', 1)
            ->assertJsonPath('data.progress.percentage', 0);

        $this->putJson('/api/v1/content/'.$contentId.'/completed', ['completed' => true])
            ->assertOk()->assertJsonPath('data.completed', true);

        $this->getJson('/api/v1/courses/'.$course->key.'/structure')
            ->assertOk()
            ->assertJsonPath('data.progress.completed', 1)
            ->assertJsonPath('data.progress.percentage', 100);
    }

    /**
     * يوثّق العقد الفعلي: البنية تُرجع الوحدات والأقسام حتى لو كانت فارغة،
     * ولا تحسبها في التقدّم.
     *
     * الاسم السابق كان "empty items are hidden" ويؤكّد إخفاء الفارغ — وهو
     * سلوك غير منفَّذ: لا $units (CourseStructureController:70-104) ولا
     * $sections (:128-163) تمرّان بأي فلترة للفارغ. إخفاء الفارغ قرار منتج
     * لم يُتخذ بعد، وإذا اتُّخذ فمكانه هاتان الحلقتان.
     */
    public function test_structure_returns_units_and_general_sections_even_when_empty(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $course = Course::create([
            'key' => 'c_QA102', 'code' => 'QA102', 'name_ar' => 'مادة فارغة',
            'name_en' => 'Empty Course', 'year' => 1, 'semester' => 1, 'is_active' => true,
        ]);

        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/staff/courses/'.$course->key.'/units', [
            'course_section_id' => $this->createSection($course, 'قسم فارغ'),
            'title' => 'وحدة فارغة', 'is_published' => true,
        ])->assertCreated();
        $this->postJson('/api/v1/staff/courses/'.$course->key.'/sections', [
            'title' => 'مراجع فارغة', 'is_published' => true,
        ])->assertCreated();

        $this->getJson('/api/v1/courses/'.$course->key.'/structure')
            ->assertOk()
            ->assertJsonCount(1, 'data.units')
            ->assertJsonCount(0, 'data.units.0.categories')
            ->assertJsonCount(2, 'data.general_sections')
            ->assertJsonPath('data.progress.total', 0)
            ->assertJsonPath('data.progress.percentage', 0);
    }

    public function test_category_must_belong_to_selected_unit(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $course = Course::create([
            'key' => 'c_QA103', 'code' => 'QA103', 'name_ar' => 'مادة',
            'name_en' => 'Course', 'year' => 1, 'semester' => 1, 'is_active' => true,
        ]);
        Sanctum::actingAs($admin);

        $sectionId = $this->createSection($course, 'القسم الأول');

        $firstUnit = $this->postJson('/api/v1/staff/courses/'.$course->key.'/units', [
            'course_section_id' => $sectionId, 'title' => 'الأولى',
        ])->assertCreated()->json('data.id');
        $secondUnit = $this->postJson('/api/v1/staff/courses/'.$course->key.'/units', [
            'course_section_id' => $sectionId, 'title' => 'الثانية',
        ])->assertCreated()->json('data.id');
        $category = $this->postJson('/api/v1/staff/courses/'.$course->key.'/sections', [
            'title' => 'محاضرات', 'course_unit_id' => $firstUnit,
        ])->assertCreated()->json('data.id');

        $this->postJson('/api/v1/staff/course-files', [
            'course_id' => $course->id,
            'course_unit_id' => $secondUnit,
            'course_section_id' => $category,
            'title' => 'محتوى خاطئ',
            'kind' => 'drive',
            'external_url' => 'https://drive.google.com/example',
        ])->assertUnprocessable()->assertJsonValidationErrors('course_section_id');
    }

    /**
     * إخفاء قسم رئيسي يخفي ما تحته — لا يرفعه إلى «محتوى إضافي».
     *
     * الاتجاه معكوس وهو ما يجعله خطرًا: الوحدة تحت قسم غير منشور كانت
     * تفشل في اختبار «أبوها منشور» فتُصنَّف يتيمة، واليتامى يُدفعون إلى
     * قسم اصطناعي ظاهر للطالب. أي أن الضغط على «إخفاء» كان يُظهر.
     */
    public function test_unpublishing_a_section_hides_its_units_instead_of_orphaning_them(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $student = User::factory()->create(['role' => 'student']);
        $course = Course::create([
            'key' => 'c_HID9', 'code' => 'HID9', 'name_ar' => 'مادة إخفاء',
            'name_en' => 'Hide Course', 'year' => 1, 'semester' => 1, 'is_active' => true,
        ]);

        Sanctum::actingAs($admin);

        $sectionId = $this->createSection($course, 'قسم سيُخفى');

        $unitId = $this->postJson('/api/v1/staff/courses/'.$course->key.'/units', [
            'course_section_id' => $sectionId,
            'title' => 'وحدة تحت القسم المخفي', 'is_published' => true,
        ])->assertCreated()->json('data.id');

        /*
         * المقارنة على الحمولة المفكوكة لا على getContent(): لارافيل يهرّب
         * العربية إلى \uXXXX، فأي assertStringContainsString على النصّ الخام
         * ينجح دائمًا في اتجاه النفي ويفشل دائمًا في اتجاه الإثبات — أي أنه
         * لا يفحص شيئًا.
         */
        $payload = fn () => json_encode(
            $this->getJson('/api/v1/courses/'.$course->key.'/structure')->assertOk()->json(),
            JSON_UNESCAPED_UNICODE
        );

        // ظاهرة قبل الإخفاء
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($student);
        $this->assertStringContainsString('وحدة تحت القسم المخفي', $payload());

        // ثم يُخفى القسم
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($admin);
        $this->patchJson('/api/v1/staff/sections/'.$sectionId, ['is_published' => false])->assertOk();

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($student);

        $this->assertStringNotContainsString(
            'وحدة تحت القسم المخفي',
            $payload(),
            'وحدة تحت قسم مخفي يجب ألا تظهر — ولا تحت «محتوى إضافي»'
        );
    }

    /**
     * create_default_categories يُطاع، والرسالة تصف ما جرى فعلًا.
     *
     * كان المفتاح يُتحقَّق منه ثم يُهمَل: الوحدة تُنشأ بلا تصنيف والرسالة
     * تقول «وتصنيفاتها الأساسية». والطاقم يكتشفه متأخرًا لأن رفع أي ملف
     * يستلزم تصنيفًا، فيبحث عن العطل في الرفع لا في وحدة أُنشئت ناقصة.
     */
    public function test_default_categories_are_created_when_requested(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $course = Course::create([
            'key' => 'c_DEF1', 'code' => 'DEF1', 'name_ar' => 'مادة التصنيفات',
            'name_en' => 'Defaults Course', 'year' => 1, 'semester' => 1, 'is_active' => true,
        ]);

        Sanctum::actingAs($admin);
        $sectionId = $this->createSection($course, 'القسم الرئيسي');

        $withDefaults = $this->postJson('/api/v1/staff/courses/'.$course->key.'/units', [
            'course_section_id' => $sectionId,
            'title' => 'وحدة بتصنيفات',
            'create_default_categories' => true,
        ])->assertCreated();

        $this->assertSame(
            4,
            CourseSection::where('course_unit_id', $withDefaults->json('data.id'))->count(),
            'التصنيفات الأربعة الأساسية يجب أن تُنشأ عند طلبها'
        );
        $this->assertStringContainsString('وتصنيفاتها الأساسية', $withDefaults->json('message'));

        // وبلا طلبها: لا تصنيفات، ولا ادعاء بها في الرسالة
        $without = $this->postJson('/api/v1/staff/courses/'.$course->key.'/units', [
            'course_section_id' => $sectionId,
            'title' => 'وحدة بلا تصنيفات',
        ])->assertCreated();

        $this->assertSame(
            0,
            CourseSection::where('course_unit_id', $without->json('data.id'))->count()
        );
        $this->assertStringNotContainsString('وتصنيفاتها الأساسية', $without->json('message'));
    }
}
