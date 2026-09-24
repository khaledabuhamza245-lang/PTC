<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Tool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CourseToolsTest extends TestCase
{
    use RefreshDatabase;

    private function course(string $suffix, string $name): Course
    {
        return Course::create([
            'key' => 'c_'.$suffix, 'code' => $suffix, 'name_ar' => $name,
            'name_en' => $name, 'year' => 1, 'semester' => 1, 'is_active' => true,
        ]);
    }

    public function test_staff_creates_a_tool_and_students_see_it_on_the_course(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $course = $this->course('NET401', 'شبكات الحاسوب');

        Sanctum::actingAs($admin);

        $toolId = $this->postJson('/api/v1/staff/tools', [
            'name' => 'Wireshark',
            'type' => 'software',
            'description' => 'يلتقط حزم الشبكة ويحللها طبقةً طبقة.',
            'official_url' => 'https://www.wireshark.org/download.html',
            'video_url' => 'https://www.youtube.com/watch?v=demo',
            'course_ids' => [$course->id],
        ])->assertCreated()->json('data.id');

        $tools = $this->getJson('/api/v1/courses/'.$course->key.'/structure')
            ->assertOk()
            ->json('data.tools');

        $this->assertCount(1, $tools);
        $this->assertSame($toolId, $tools[0]['id']);
        $this->assertSame('software', $tools[0]['type']);
        $this->assertSame('https://www.wireshark.org/download.html', $tools[0]['official_url']);
    }

    public function test_one_tool_serves_many_courses(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $web = $this->course('WEB301', 'تطوير تطبيقات الويب');
        $se = $this->course('SE301', 'هندسة البرمجيات');

        $this->postJson('/api/v1/staff/tools', [
            'name' => 'Git',
            'type' => 'software',
            'description' => 'يتابع تغيّرات الشيفرة ويدير الفروع.',
            'official_url' => 'https://git-scm.com/downloads',
            'course_ids' => [$web->id, $se->id],
        ])->assertCreated();

        foreach ([$web, $se] as $course) {
            $this->getJson('/api/v1/courses/'.$course->key.'/structure')
                ->assertOk()
                ->assertJsonPath('data.tools.0.name', 'Git');
        }
    }

    public function test_concept_requires_explanation_and_others_require_a_link(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        // مفهوم بلا شرح: زرّه يفتح نافذة فارغة.
        $this->postJson('/api/v1/staff/tools', [
            'name' => 'Big-O',
            'type' => 'concept',
            'description' => 'يقيس نمو زمن الخوارزمية مع حجم المدخل.',
        ])->assertJsonValidationErrors('explanation');

        // برنامج بلا رابط: زرّه لا وجهة له.
        $this->postJson('/api/v1/staff/tools', [
            'name' => 'Proteus',
            'type' => 'software',
            'description' => 'يحاكي الدوائر قبل تنزيلها على البورد.',
        ])->assertJsonValidationErrors('official_url');

        // المفهوم يُقبل بالشرح وحده، بلا رابط رسمي.
        $this->postJson('/api/v1/staff/tools', [
            'name' => 'Big-O',
            'type' => 'concept',
            'description' => 'يقيس نمو زمن الخوارزمية مع حجم المدخل.',
            'explanation' => 'رتبة النمو تصف كيف يتضاعف العمل كلما كبر المدخل.',
        ])->assertCreated();
    }

    public function test_editing_a_tool_without_sending_courses_keeps_its_links(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $course = $this->course('DB301', 'نظم قواعد البيانات');

        $toolId = $this->postJson('/api/v1/staff/tools', [
            'name' => 'MySQL Workbench',
            'type' => 'software',
            'description' => 'يصمم المخططات وينفّذ استعلامات SQL.',
            'official_url' => 'https://dev.mysql.com/downloads/workbench/',
            'course_ids' => [$course->id],
        ])->assertCreated()->json('data.id');

        $this->patchJson('/api/v1/staff/tools/'.$toolId, [
            'name' => 'MySQL Workbench 8',
        ])->assertOk();

        $this->assertSame(1, Tool::find($toolId)->courses()->count());
    }

    public function test_inactive_tools_are_hidden_from_students(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $course = $this->course('EMB401', 'الأنظمة المدمجة');

        $toolId = $this->postJson('/api/v1/staff/tools', [
            'name' => 'Arduino IDE',
            'type' => 'software',
            'description' => 'يبرمج لوحات أردوينو ويرفع الشيفرة إليها.',
            'official_url' => 'https://www.arduino.cc/en/software',
            'course_ids' => [$course->id],
        ])->assertCreated()->json('data.id');

        $this->patchJson('/api/v1/staff/tools/'.$toolId, ['is_active' => false])->assertOk();

        $this->getJson('/api/v1/courses/'.$course->key.'/structure')
            ->assertOk()
            ->assertJsonCount(0, 'data.tools');
    }

    public function test_only_http_links_are_accepted(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        // زرّ يحمل javascript: ينفّذ شيفرة في متصفح كل طالب يفتح المادة.
        $this->postJson('/api/v1/staff/tools', [
            'name' => 'أداة ملغومة',
            'type' => 'online',
            'description' => 'رابط ببروتوكول غير آمن.',
            'official_url' => 'javascript:alert(1)',
        ])->assertJsonValidationErrors('official_url');

        $this->postJson('/api/v1/staff/tools', [
            'name' => 'أداة سليمة',
            'type' => 'online',
            'description' => 'رابط عادي.',
            'official_url' => 'https://app.diagrams.net/',
            'video_url' => 'javascript:alert(2)',
        ])->assertJsonValidationErrors('video_url');
    }

    public function test_students_cannot_manage_the_directory(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'student']));

        $this->getJson('/api/v1/staff/tools')->assertForbidden();

        $this->postJson('/api/v1/staff/tools', [
            'name' => 'أداة مدسوسة',
            'type' => 'online',
            'description' => 'محاولة كتابة من حساب طالب.',
            'official_url' => 'https://example.com',
        ])->assertForbidden();
    }

    public function test_deleting_a_course_does_not_orphan_the_pivot(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $course = $this->course('AI401', 'الذكاء الاصطناعي');

        $toolId = $this->postJson('/api/v1/staff/tools', [
            'name' => 'draw.io',
            'type' => 'online',
            'description' => 'يرسم المخططات في المتصفح بلا تثبيت.',
            'official_url' => 'https://app.diagrams.net/',
            'course_ids' => [$course->id],
        ])->assertCreated()->json('data.id');

        $course->forceDelete();

        $this->assertDatabaseMissing('course_tool', ['tool_id' => $toolId]);
        $this->assertDatabaseHas('tools', ['id' => $toolId]);
    }
}
