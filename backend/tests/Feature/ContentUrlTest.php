<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * روابط المحتوى: https حصرًا.
 *
 * السبب شرط عمل لا تشدد أمني عام: الموقع يُخدَّم على https، والمتصفح
 * يحجب أي إطار لرابط http بصمت (mixed content) — بلا خطأ ولا حدث
 * ولا أثر في الكونسول يربطه بالسبب. فتظهر المعاينة إطارًا أبيض،
 * ولا وسيلة لكشف ذلك برمجيًا بعد وقوعه. المنع عند الإدخال هو
 * الموضع الوحيد الذي يُمسك فيه.
 *
 * هذا الاختبار يسقط لو رُخِّيت القاعدة يومًا إلى url:http,https.
 */
class ContentUrlTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;

    private int $sectionId;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $this->course = Course::create([
            'key' => 'c_URL101', 'code' => 'URL101', 'name_ar' => 'مادة اختبار الروابط',
            'name_en' => 'URL Test Course', 'year' => 1, 'semester' => 1, 'is_active' => true,
        ]);

        // قسم رئيسي: course_unit_id فيه null فيقبل ملفًا معلَّقًا عليه مباشرة.
        $this->sectionId = $this->postJson('/api/v1/staff/courses/'.$this->course->key.'/sections', [
            'title' => 'قسم الاختبار', 'is_published' => true,
        ])->assertCreated()->json('data.id');
    }

    private function payload(string $url): array
    {
        return [
            'course_id' => $this->course->id,
            'course_section_id' => $this->sectionId,
            'title' => 'محتوى اختبار',
            'kind' => 'link',
            'external_url' => $url,
            'is_published' => true,
        ];
    }

    public static function insecureUrls(): array
    {
        return [
            'http عادي' => ['http://example.com/file.pdf'],
            'http بحروف كبيرة' => ['HTTP://example.com/file.pdf'],
            'يوتيوب على http' => ['http://youtube.com/watch?v=abc'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('insecureUrls')]
    public function test_http_url_is_rejected_on_create(string $url): void
    {
        $this->postJson('/api/v1/staff/course-files', $this->payload($url))
            ->assertStatus(422)
            ->assertJsonValidationErrors('external_url');

        $this->assertSame(0, CourseFile::count(), 'لا يجوز أن يُكتب صف عند رفض الرابط');
    }

    /**
     * الرسالة تقول العلاج لا «رابط غير صالح» فقط: الطاقم سيصطدم بها على
     * صفوف قديمة رابطها http عند تعديل أي حقل آخر.
     */
    public function test_rejection_message_names_https(): void
    {
        $this->postJson('/api/v1/staff/course-files', $this->payload('http://example.com/f.pdf'))
            ->assertStatus(422)
            ->assertJsonFragment([
                'external_url' => ['الرابط يجب أن يبدأ بـ https:// — روابط http لا تُعرض داخل الموقع.'],
            ]);
    }

    public function test_https_url_is_accepted_on_create(): void
    {
        $this->postJson('/api/v1/staff/course-files', $this->payload('https://example.com/file.pdf'))
            ->assertCreated()
            ->assertJsonPath('data.external_url', 'https://example.com/file.pdf');
    }

    /**
     * مسار التحديث يمرّ بالقواعد نفسها ($partial = true)، فلا ينفذ منه
     * رابط http إلى صف موجود.
     */
    public function test_http_url_is_rejected_on_update(): void
    {
        $id = $this->postJson('/api/v1/staff/course-files', $this->payload('https://example.com/ok.pdf'))
            ->assertCreated()->json('data.id');

        $this->patchJson('/api/v1/staff/course-files/'.$id, [
            'external_url' => 'http://example.com/downgraded.pdf',
        ])->assertStatus(422)->assertJsonValidationErrors('external_url');

        $this->assertSame(
            'https://example.com/ok.pdf',
            CourseFile::find($id)->external_url,
            'الرابط الأصلي يجب أن يبقى كما هو بعد رفض التحديث'
        );
    }

    /**
     * محتوى معلَّق على القسم مباشرة بلا وحدة ولا تصنيف.
     *
     * لوحة الأدمن كانت تشترط الأربعة، فيضطر الطاقم لاختراع «وحدة»
     * و«تصنيف» وهميين لكل محاضرة — وهو مصدر الشكوى من أن التصنيف غير
     * احترافي. الـAPI يقبل هذه الحالة، وهذا الاختبار يثبّتها حتى لا
     * تُشدَّد القاعدة يومًا فيسقط النموذج بلا أن ينتبه أحد.
     */
    public function test_content_can_hang_directly_on_a_section(): void
    {
        $id = $this->postJson('/api/v1/staff/course-files', [
            'course_id' => $this->course->id,
            'course_section_id' => $this->sectionId,
            'course_unit_id' => null,
            'title' => 'محتوى بلا وحدة',
            'kind' => 'link',
            'external_url' => 'https://example.com/direct.pdf',
            'is_published' => true,
        ])->assertCreated()->json('data.id');

        $file = CourseFile::find($id);

        $this->assertNull($file->course_unit_id, 'يجب ألا تُخترع وحدة');
        $this->assertSame($this->sectionId, $file->course_section_id);
    }

    /**
     * والعكس مرفوض: تصنيف داخل وحدة لا يُقبل بلا تلك الوحدة، وإلا
     * ضاع المحتوى في شجرة لا تصله.
     */
    public function test_category_still_requires_its_unit(): void
    {
        $unitId = $this->postJson('/api/v1/staff/courses/'.$this->course->key.'/units', [
            'course_section_id' => $this->sectionId,
            'title' => 'وحدة', 'is_published' => true,
        ])->assertCreated()->json('data.id');

        $categoryId = $this->postJson('/api/v1/staff/courses/'.$this->course->key.'/sections', [
            'course_unit_id' => $unitId,
            'title' => 'تصنيف', 'is_published' => true,
        ])->assertCreated()->json('data.id');

        $this->postJson('/api/v1/staff/course-files', [
            'course_id' => $this->course->id,
            'course_section_id' => $categoryId,
            'course_unit_id' => null,
            'title' => 'محتوى يتيم',
            'kind' => 'link',
            'external_url' => 'https://example.com/orphan.pdf',
        ])->assertStatus(422)->assertJsonValidationErrors('course_unit_id');
    }

    /**
     * تعديل حقل آخر على صف رابطه https لا يتأثر بالتشديد — يحرس ضد
     * جعل القاعدة تمنع أي تحرير لاحق بالخطأ.
     */
    public function test_editing_another_field_still_works(): void
    {
        $id = $this->postJson('/api/v1/staff/course-files', $this->payload('https://example.com/ok.pdf'))
            ->assertCreated()->json('data.id');

        $this->patchJson('/api/v1/staff/course-files/'.$id, ['title' => 'عنوان جديد'])
            ->assertOk();

        $this->assertSame('عنوان جديد', CourseFile::find($id)->title);
    }
}
