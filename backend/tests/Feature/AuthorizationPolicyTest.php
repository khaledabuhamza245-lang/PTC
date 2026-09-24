<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseProgress;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * طبقة التفويض.
 *
 * قبلها كان EnsureRole على مستوى المسار هو كل ما يوجد، فكل من يمرّ من
 * role:admin,supervisor يستطيع كل شيء داخله — بما فيه حذف مساق يمحو
 * بالـ cascade تسجيلات كل الطلاب وتقدّمهم.
 */
class AuthorizationPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('api');
    }

    private function course(): Course
    {
        return Course::create([
            'key' => 'c_AUTH1', 'code' => 'AUTH1', 'name_ar' => 'مادة',
            'name_en' => 'Course', 'year' => 1, 'semester' => 1, 'is_active' => true,
        ]);
    }

    public function test_authorize_is_callable_on_controllers(): void
    {
        // بدون trait AuthorizesRequests على الكلاس الأساس لا يكون
        // $this->authorize() موجودًا أصلًا — وهو السبب البنيوي لغياب
        // أي تفويض في المشروع.
        $this->assertContains(
            \Illuminate\Foundation\Auth\Access\AuthorizesRequests::class,
            class_uses_recursive(\App\Http\Controllers\Controller::class)
        );
    }

    public function test_supervisor_cannot_delete_a_course(): void
    {
        $course = $this->course();
        Sanctum::actingAs(User::factory()->create(['role' => 'supervisor']));

        $this->deleteJson('/api/v1/staff/courses/'.$course->key)
            ->assertForbidden();

        $this->assertNotSoftDeleted($course);
    }

    public function test_admin_can_delete_a_course(): void
    {
        $course = $this->course();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $this->deleteJson('/api/v1/staff/courses/'.$course->key)->assertOk();

        $this->assertSoftDeleted($course);
    }

    public function test_deleting_a_course_no_longer_destroys_student_progress(): void
    {
        // هذا جوهر المشكلة: الحذف الفعلي كان يُطلق cascade على
        // course_progress و my_courses.
        $course = $this->course();
        $student = User::factory()->create(['role' => 'student']);
        CourseProgress::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'data' => ['done' => [1, 2, 3]],
        ]);

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->deleteJson('/api/v1/staff/courses/'.$course->key)->assertOk();

        $this->assertDatabaseHas('course_progress', [
            'user_id' => $student->id,
            'course_id' => $course->id,
        ]);
    }

    public function test_deleted_course_disappears_from_public_listing(): void
    {
        $course = $this->course();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->deleteJson('/api/v1/staff/courses/'.$course->key)->assertOk();

        $this->app['auth']->forgetGuards();

        $keys = collect($this->getJson('/api/v1/courses')->assertOk()->json('data'))
            ->pluck('key');

        $this->assertNotContains($course->key, $keys->all());
        $this->getJson('/api/v1/courses/'.$course->key)->assertNotFound();
    }

    public function test_admin_can_restore_a_deleted_course(): void
    {
        $course = $this->course();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $this->deleteJson('/api/v1/staff/courses/'.$course->key)->assertOk();
        $this->getJson('/api/v1/admin/courses/trashed')
            ->assertOk()
            ->assertJsonPath('data.0.key', $course->key);

        $this->postJson('/api/v1/admin/courses/'.$course->key.'/restore')->assertOk();

        $this->assertNotSoftDeleted($course);
    }

    public function test_supervisor_cannot_see_or_restore_trashed_courses(): void
    {
        $course = $this->course();
        $course->delete();

        Sanctum::actingAs(User::factory()->create(['role' => 'supervisor']));

        $this->getJson('/api/v1/admin/courses/trashed')->assertForbidden();
        $this->postJson('/api/v1/admin/courses/'.$course->key.'/restore')->assertForbidden();
    }

    public function test_supervisor_keeps_full_content_authoring_rights(): void
    {
        // القرار: الطاقم فريق واحد. تقييد الحذف يجب ألا يعرقل عمل
        // المشرف اليومي في بناء المحتوى.
        $course = $this->course();
        Sanctum::actingAs(User::factory()->create(['role' => 'supervisor']));

        $section = $this->postJson('/api/v1/staff/courses/'.$course->key.'/sections', [
            'title' => 'قسم', 'is_published' => true,
        ])->assertCreated()->json('data.id');

        $this->postJson('/api/v1/staff/courses/'.$course->key.'/units', [
            'course_section_id' => $section, 'title' => 'وحدة',
        ])->assertCreated();

        $this->patchJson('/api/v1/staff/courses/'.$course->key, ['name_ar' => 'اسم جديد'])
            ->assertOk();

        $this->deleteJson('/api/v1/staff/sections/'.$section)->assertOk();
    }

    public function test_supervisor_can_edit_content_created_by_another_supervisor(): void
    {
        $course = $this->course();
        $first = User::factory()->create(['role' => 'supervisor']);
        $second = User::factory()->create(['role' => 'supervisor']);

        Sanctum::actingAs($first);
        $section = $this->postJson('/api/v1/staff/courses/'.$course->key.'/sections', [
            'title' => 'قسم الأول', 'is_published' => true,
        ])->assertCreated()->json('data.id');
        $file = $this->postJson('/api/v1/staff/course-files', [
            'course_id' => $course->id, 'course_section_id' => $section,
            'title' => 'محتوى الأول', 'kind' => 'link',
            'external_url' => 'https://example.com/a.pdf',
        ])->assertCreated()->json('data.id');

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($second);

        $this->patchJson('/api/v1/staff/course-files/'.$file, ['title' => 'عدّله الثاني'])
            ->assertOk();
    }
}
