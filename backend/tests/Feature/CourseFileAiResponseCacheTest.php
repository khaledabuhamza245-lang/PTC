<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseFile;
use App\Models\CourseFileAiResponse;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * خطوة ١١٤: تخزين الرد الجاهز (تلخيص/بطاقات) لكل ملف — أول طالب فقط
 * يستدعي Gemini فعليًا، أي طالب تاني بعده (من نفس الملف بنفس الوضع)
 * يستلم نفس الرد فورًا من الكاش بلا أي استهلاك من حدّه اليومي.
 */
class CourseFileAiResponseCacheTest extends TestCase
{
    use RefreshDatabase;

    private function makeCourseFile(): CourseFile
    {
        $course = Course::create([
            'key' => 'c_QA114', 'code' => 'QA114', 'name_ar' => 'مادة اختبار',
            'name_en' => 'QA Course', 'year' => 1, 'semester' => 1, 'is_active' => true,
        ]);

        return CourseFile::create([
            'course_id' => $course->id,
            'title' => 'ملف اختبار الكاش',
            'kind' => 'file',
            'storage_disk' => 'gdrive',
            'storage_path' => 'fake-path',
            'is_published' => true,
            'visibility' => 'public',
            'status' => 'ready',
            'ai_summarizable' => true,
        ]);
    }

    public function test_a_cached_response_is_served_instantly_without_touching_the_daily_limit(): void
    {
        $courseFile = $this->makeCourseFile();

        CourseFileAiResponse::create([
            'course_file_id' => $courseFile->id,
            'mode' => 'summary',
            'response_text' => 'هذا ملخّص جاهز مخزَّن مسبقًا للاختبار.',
        ]);

        $student = User::factory()->create(['role' => 'student']);
        Sanctum::actingAs($student);

        $response = $this->postJson("/api/v1/ai/course-files/{$courseFile->id}/summarize", ['mode' => 'summary']);

        $response->assertOk();
        $this->assertSame('هذا ملخّص جاهز مخزَّن مسبقًا للاختبار.', $response->json('reply'));

        // ما في أي استهلاك من الحد اليومي — الرد جاي من الكاش لا Gemini
        $key = 'ai-assistant-usage:' . $student->id . ':' . now()->toDateString();
        $this->assertSame(0, (int) Cache::get($key, 0));

        // السؤال والمحادثة انُشئا فعليًا (يظهر بسجل محادثات الطالب) بحالة done مباشرة
        $this->assertDatabaseHas('ai_questions', [
            'user_id' => $student->id,
            'referenced_course_file_id' => $courseFile->id,
            'status' => 'done',
            'reply' => 'هذا ملخّص جاهز مخزَّن مسبقًا للاختبار.',
        ]);
    }

    public function test_summary_and_flashcards_caches_are_independent_for_the_same_file(): void
    {
        $courseFile = $this->makeCourseFile();

        CourseFileAiResponse::create([
            'course_file_id' => $courseFile->id,
            'mode' => 'summary',
            'response_text' => 'ملخّص مخزَّن',
        ]);

        $student = User::factory()->create(['role' => 'student']);
        Sanctum::actingAs($student);

        // طلب بطاقات مراجعة لنفس الملف — لا يوجد كاش لهذا الوضع بعد، فيُتوقَّع pending (202)
        // لأنه لا يوجد CourseFileAiMeta جاهز بهذه البيئة (بلا مفاتيح Gemini حقيقية بالاختبار)
        $response = $this->postJson("/api/v1/ai/course-files/{$courseFile->id}/summarize", ['mode' => 'flashcards']);

        $this->assertNotSame(200, $response->status());
        $this->assertDatabaseMissing('course_file_ai_responses', ['course_file_id' => $courseFile->id, 'mode' => 'flashcards']);
    }
}
