<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الوصول العام لملفات المساقات.
 *
 * ثلاثة مسارات عامة تقرأ نفس المحتوى — قائمة الملفات ورابط التحميل
 * وشجرة البنية — ويجب أن تتفق على من يرى ماذا. كان /structure يفحص
 * is_published و is_active بينما الآخران لا، فكانت المسوّدات ومحتوى
 * المساقات المعطّلة مقروءة وقابلة للتحميل لأي زائر.
 */
class PublicFileAccessTest extends TestCase
{
    use RefreshDatabase;

    private function course(array $overrides = []): Course
    {
        return Course::create(array_merge([
            'key' => 'c_SEC101', 'code' => 'SEC101', 'name_ar' => 'مادة',
            'name_en' => 'Course', 'year' => 1, 'semester' => 1, 'is_active' => true,
        ], $overrides));
    }

    private function file(Course $course, array $overrides = []): CourseFile
    {
        return CourseFile::create(array_merge([
            'course_id' => $course->id,
            'title' => 'ملف',
            'kind' => 'link',
            'external_url' => 'https://example.com/f.pdf',
            'visibility' => 'public',
            'status' => 'ready',
            'is_published' => true,
        ], $overrides));
    }

    public function test_published_file_is_publicly_listed_and_downloadable(): void
    {
        $course = $this->course();
        $file = $this->file($course);

        $this->getJson('/api/v1/courses/'.$course->key.'/files')
            ->assertOk()
            ->assertJsonPath('data.0.id', $file->id);

        $this->getJson('/api/v1/files/'.$file->id.'/download')
            ->assertOk()
            ->assertJsonPath('data.url', 'https://example.com/f.pdf');
    }

    public function test_unpublished_file_is_hidden_from_public_list(): void
    {
        $course = $this->course();
        $this->file($course, ['is_published' => false, 'title' => 'مسودة']);

        $this->getJson('/api/v1/courses/'.$course->key.'/files')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_unpublished_file_cannot_be_downloaded_anonymously(): void
    {
        $course = $this->course();
        $file = $this->file($course, ['is_published' => false]);

        $this->getJson('/api/v1/files/'.$file->id.'/download')
            ->assertForbidden();
    }

    public function test_files_of_inactive_course_are_not_listed(): void
    {
        $course = $this->course(['is_active' => false]);
        $this->file($course);

        // المساق نفسه يرد 404 عند التعطيل، فيجب ألا تكون ملفاته مسرودة
        $this->getJson('/api/v1/courses/'.$course->key.'/files')
            ->assertNotFound();
    }

    public function test_files_of_inactive_course_cannot_be_downloaded(): void
    {
        $course = $this->course(['is_active' => false]);
        $file = $this->file($course);

        $this->getJson('/api/v1/files/'.$file->id.'/download')
            ->assertForbidden();
    }

    public function test_not_ready_file_stays_hidden(): void
    {
        $course = $this->course();
        $file = $this->file($course, ['status' => 'pending']);

        $this->getJson('/api/v1/courses/'.$course->key.'/files')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson('/api/v1/files/'.$file->id.'/download')
            ->assertForbidden();
    }

    public function test_structure_and_files_endpoints_agree_on_visibility(): void
    {
        $course = $this->course();
        $this->file($course, ['title' => 'منشور']);
        $this->file($course, ['title' => 'مسودة', 'is_published' => false]);

        $listed = $this->getJson('/api/v1/courses/'.$course->key.'/files')
            ->assertOk()->json('data');

        $structure = $this->getJson('/api/v1/courses/'.$course->key.'/structure')
            ->assertOk()->json();

        $this->assertCount(1, $listed, 'قائمة الملفات يجب أن تُظهر المنشور فقط');
        $this->assertSame('منشور', $listed[0]['title']);
        $this->assertStringNotContainsString('مسودة', json_encode($structure, JSON_UNESCAPED_UNICODE));
    }
}
