<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * شكل رسائل الخطأ التي تصل المستخدم.
 *
 * الواجهة تعرض `error.message` كما هو (api.js ثم profile-page.js)، فأي نصّ
 * داخلي يتسرّب من لارافيل يُقرأ عربيًّا في صفحة عربية. وربط النموذج بالمسار
 * كان يفعل ذلك بالضبط: يرمي ModelNotFoundException، فيحوّلها الإطار إلى
 * NotFoundHttpException **حاملةً نصّها**، وconvertExceptionToArray يمرّر نصّ
 * أي HttpException حتى مع APP_DEBUG=false.
 */
class ApiErrorShapeTest extends TestCase
{
    use RefreshDatabase;

    private const LEAK = 'No query results for model';

    public function test_unknown_course_returns_arabic_message_without_internals(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->getJson('/api/v1/courses/c_DOES_NOT_EXIST/structure')
            ->assertNotFound();

        $message = (string) $response->json('message');

        $this->assertStringNotContainsString(self::LEAK, $message);
        $this->assertStringNotContainsString('App\\Models', $message);
        $this->assertSame('العنصر المطلوب غير موجود أو حُذف.', $message);
    }

    /**
     * الحالة الواقعية: auth.js يخزّن المساقات خمس دقائق، فحذف مادة من
     * اللوحة يترك كل طالب مفتوحةٍ لديه الصفحة على صفٍّ يشير إلى مساق زال.
     * أول ضغطة على حالته كانت تطبع اسم الصنف الكامل تحت جدول الخطة.
     */
    public function test_acting_on_a_soft_deleted_course_does_not_leak_the_model_class(): void
    {
        $course = Course::create([
            'key' => 'c_GONE1', 'code' => 'GONE1', 'name_ar' => 'مادة محذوفة',
            'name_en' => 'Gone Course', 'year' => 1, 'semester' => 1, 'is_active' => true,
        ]);

        $student = User::factory()->create();
        Sanctum::actingAs($student);
        $this->postJson('/api/v1/my-courses/'.$course->key)->assertCreated();

        $course->delete();

        $message = (string) $this->patchJson('/api/v1/my-courses/'.$course->key, [
            'status' => 'completed',
        ])->assertNotFound()->json('message');

        $this->assertStringNotContainsString(self::LEAK, $message);
        $this->assertStringNotContainsString('App\\Models', $message);
    }
}
