<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\Course;
use App\Models\CoursePrerequisite;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AcademicPlanTest extends TestCase
{
    use RefreshDatabase;

    private function course(string $code, array $extra = []): Course
    {
        return Course::create(array_merge([
            'key' => 'c_'.str_replace(' ', '', $code),
            'code' => $code,
            'name_ar' => 'مساق '.$code,
            'name_en' => 'Course '.$code,
            'year' => 1,
            'semester' => 1,
            'credit_hours' => 3,
            'course_type' => 'required',
        ], $extra));
    }

    /** ⛔ الحارس الأهم: المسار العام لا يكشف إلا ما وُسم عامًّا. */
    public function test_program_endpoint_exposes_public_settings_only(): void
    {
        AppSetting::create([
            'key' => 'program.total_credit_hours',
            'value' => '142',
            'group' => 'program',
            'is_public' => true,
        ]);

        AppSetting::create([
            'key' => 'internal.secret_note',
            'value' => 'لا يُعرض',
            'group' => 'internal',
            'is_public' => false,
        ]);

        $response = $this->getJson('/api/v1/program')->assertOk();

        $response->assertJsonPath('data.total_credit_hours', 142);
        $this->assertArrayHasKey('program.total_credit_hours', $response->json('data.settings'));
        $this->assertArrayNotHasKey('internal.secret_note', $response->json('data.settings'));
        $response->assertDontSee('لا يُعرض');
    }

    public function test_program_endpoint_falls_back_when_settings_are_missing(): void
    {
        $this->getJson('/api/v1/program')
            ->assertOk()
            ->assertJsonPath('data.total_credit_hours', 142)
            ->assertJsonPath('data.required_electives', 5);
    }

    public function test_terms_endpoint_marks_the_current_term(): void
    {
        Term::create([
            'code' => '2025-1', 'label' => 'أول', 'academic_year' => '2025/2026',
            'semester' => 1, 'sort_order' => 1,
        ]);

        Term::create([
            'code' => '2026-1', 'label' => 'أول', 'academic_year' => '2026/2027',
            'semester' => 1, 'sort_order' => 2, 'is_current' => true,
        ]);

        $this->getJson('/api/v1/terms')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('current.code', '2026-1');
    }

    public function test_courses_payload_carries_hours_type_and_prerequisite_codes(): void
    {
        $base = $this->course('AAA 100');
        $next = $this->course('BBB 200', ['credit_hours' => 4, 'course_type' => 'elective']);

        CoursePrerequisite::create([
            'course_id' => $next->id,
            'prerequisite_course_id' => $base->id,
            'prerequisite_code_raw' => 'AAA 100',
        ]);

        /* رمز معطوب: يُعرض نصًّا كما ورد لا يُخمَّن ولا يُحذف. */
        CoursePrerequisite::create([
            'course_id' => $next->id,
            'prerequisite_code_raw' => 'ZZZ 999',
            'needs_review' => true,
        ]);

        $data = collect($this->getJson('/api/v1/courses')->assertOk()->json('data'))
            ->keyBy('key');

        $this->assertSame(4, $data['c_BBB200']['credit_hours']);
        $this->assertSame('elective', $data['c_BBB200']['course_type']);
        $this->assertSame(['AAA 100', 'ZZZ 999'], $data['c_BBB200']['prerequisite_codes']);
    }

    /**
     * المتطلب يُعرض باسمه: «مساق AAA 100» تُقرأ، و«AAA 100» تُبحث.
     *
     * والرمز المعطوب لا اسم له، فيُعرض نصّه كما ورد — إسقاطه كان
     * سيُظهر مساقًا له متطلب كأنه بلا متطلب.
     */
    public function test_courses_payload_carries_prerequisite_names_beside_codes(): void
    {
        $base = $this->course('AAA 100');
        $next = $this->course('BBB 200');

        CoursePrerequisite::create([
            'course_id' => $next->id,
            'prerequisite_course_id' => $base->id,
            'prerequisite_code_raw' => 'AAA 100',
        ]);

        CoursePrerequisite::create([
            'course_id' => $next->id,
            'prerequisite_code_raw' => 'ZZZ 999',
            'needs_review' => true,
        ]);

        $data = collect($this->getJson('/api/v1/courses')->assertOk()->json('data'))
            ->keyBy('key');

        $this->assertSame([
            ['code' => 'AAA 100', 'name' => 'مساق AAA 100'],
            ['code' => 'ZZZ 999', 'name' => 'ZZZ 999'],
        ], $data['c_BBB200']['prerequisites_brief']);

        $this->assertSame([], $data['c_AAA100']['prerequisites_brief']);
    }

    /**
     * حالة الطالب لا تدخل المسار العام.
     *
     * /v1/courses مخزَّن في localStorage خمس دقائق ويُطلب بلا رمز
     * مصادقة — أي حقل شخصي فيه يصير مرئيًّا لمن يفتح المتصفح بعده.
     */
    public function test_public_courses_payload_carries_no_student_state(): void
    {
        $user = User::factory()->create();
        $course = $this->course('AAA 100');
        $user->myCourses()->attach($course->id, ['status' => 'completed']);

        Sanctum::actingAs($user);

        $row = $this->getJson('/api/v1/courses')->assertOk()->json('data.0');

        $this->assertArrayNotHasKey('status', $row);
        $this->assertArrayNotHasKey('pivot', $row);
    }

    public function test_prerequisites_endpoint_returns_both_directions(): void
    {
        $base = $this->course('AAA 100');
        $next = $this->course('BBB 200');

        CoursePrerequisite::create([
            'course_id' => $next->id,
            'prerequisite_course_id' => $base->id,
            'prerequisite_code_raw' => 'AAA 100',
        ]);

        $this->getJson('/api/v1/courses/c_BBB200/prerequisites')
            ->assertOk()
            ->assertJsonPath('data.prerequisites.0.code', 'AAA 100')
            ->assertJsonPath('data.prerequisites.0.course.key', 'c_AAA100');

        $this->getJson('/api/v1/courses/c_AAA100/prerequisites')
            ->assertOk()
            ->assertJsonPath('data.required_for.0.key', 'c_BBB200');
    }

    public function test_unresolved_prerequisite_is_shown_as_raw_text(): void
    {
        $course = $this->course('AAA 100');

        CoursePrerequisite::create([
            'course_id' => $course->id,
            'prerequisite_code_raw' => 'EEE1 3250',
            'needs_review' => true,
        ]);

        $this->getJson('/api/v1/courses/c_AAA100/prerequisites')
            ->assertOk()
            ->assertJsonPath('data.prerequisites.0.code', 'EEE1 3250')
            ->assertJsonPath('data.prerequisites.0.course', null)
            ->assertJsonPath('data.prerequisites.0.needs_review', true);
    }

    public function test_staff_cannot_create_a_self_or_circular_prerequisite(): void
    {
        $staff = User::factory()->create(['role' => 'admin']);
        $a = $this->course('AAA 100');
        $b = $this->course('BBB 200');

        Sanctum::actingAs($staff);

        $this->postJson('/api/v1/staff/courses/c_AAA100/prerequisites', [
            'prerequisite_key' => 'c_AAA100',
        ])->assertStatus(422);

        $this->postJson('/api/v1/staff/courses/c_BBB200/prerequisites', [
            'prerequisite_key' => 'c_AAA100',
        ])->assertCreated();

        /* A يطلب B بينما B يطلب A أصلًا — دورة تُرفض. */
        $this->postJson('/api/v1/staff/courses/c_AAA100/prerequisites', [
            'prerequisite_key' => 'c_BBB200',
        ])->assertStatus(422);

        $this->assertSame(1, CoursePrerequisite::count());
    }

    public function test_students_cannot_manage_prerequisites(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->course('AAA 100');

        $this->postJson('/api/v1/staff/courses/c_AAA100/prerequisites', [
            'prerequisite_code_raw' => 'XXX 100',
        ])->assertForbidden();
    }

    public function test_prep_topics_are_replaced_as_a_whole(): void
    {
        $staff = User::factory()->create(['role' => 'admin']);
        $course = $this->course('AAA 100');

        $course->prepTopics()->create(['topic' => 'قديم', 'sort_order' => 1]);

        Sanctum::actingAs($staff);

        $this->putJson('/api/v1/staff/courses/c_AAA100/prep-topics', [
            'topics' => [
                ['topic' => 'الأول', 'link' => 'https://example.com/a'],
                ['topic' => 'الثاني'],
            ],
        ])->assertOk()->assertJsonCount(2, 'data');

        $this->getJson('/api/v1/courses/c_AAA100/prep-topics')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.topic', 'الأول')
            ->assertJsonPath('data.1.sort_order', 2)
            ->assertDontSee('قديم');
    }

    public function test_student_marks_a_course_completed_and_gets_a_timestamp(): void
    {
        $user = User::factory()->create();
        $course = $this->course('AAA 100');
        $user->myCourses()->attach($course->id);

        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/my-courses/c_AAA100', ['status' => 'completed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        $this->assertNotNull(
            $user->myCourses()->where('courses.id', $course->id)->first()->pivot->completed_at,
        );

        /* التراجع يمسح التاريخ — مساق غير منجَز بتاريخ إنجاز تناقض. */
        $this->patchJson('/api/v1/my-courses/c_AAA100', ['status' => 'registered'])->assertOk();

        $this->assertNull(
            $user->myCourses()->where('courses.id', $course->id)->first()->pivot->completed_at,
        );
    }

    public function test_status_update_is_rejected_for_a_course_the_student_did_not_add(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->course('AAA 100');

        $this->patchJson('/api/v1/my-courses/c_AAA100', ['status' => 'completed'])
            ->assertNotFound();
    }

    public function test_status_must_be_one_of_the_three_known_values(): void
    {
        $user = User::factory()->create();
        $course = $this->course('AAA 100');
        $user->myCourses()->attach($course->id);

        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/my-courses/c_AAA100', ['status' => 'graduated'])
            ->assertStatus(422);
    }

    public function test_student_can_set_their_current_term(): void
    {
        $user = User::factory()->create();

        $term = Term::create([
            'code' => '2026-1', 'label' => 'أول', 'academic_year' => '2026/2027', 'semester' => 1,
        ]);

        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/me', ['current_term_id' => $term->id])
            ->assertOk()
            ->assertJsonPath('data.current_term.code', '2026-1');
    }

    public function test_setting_a_current_term_clears_the_previous_one(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $first = Term::create([
            'code' => '2025-1', 'label' => 'أول', 'academic_year' => '2025/2026',
            'semester' => 1, 'is_current' => true,
        ]);

        $second = Term::create([
            'code' => '2026-1', 'label' => 'أول', 'academic_year' => '2026/2027', 'semester' => 1,
        ]);

        Sanctum::actingAs($admin);

        $this->patchJson('/api/v1/admin/terms/'.$second->id, ['is_current' => true])->assertOk();

        $this->assertFalse($first->fresh()->is_current);
        $this->assertTrue($second->fresh()->is_current);
        $this->assertSame(1, Term::where('is_current', true)->count());
    }

    public function test_admin_settings_reject_keys_outside_the_whitelist(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $this->putJson('/api/v1/admin/settings', [
            'settings' => ['program.total_credit_hours' => 130],
        ])->assertOk();

        $this->assertSame('130', AppSetting::get('program.total_credit_hours'));

        $this->putJson('/api/v1/admin/settings', [
            'settings' => ['app.debug' => 'true'],
        ])->assertStatus(422);

        $this->assertNull(AppSetting::get('app.debug'));
    }

    /**
     * القيمة تُفحص فعلًا.
     *
     * النقطة داخل اسم المفتاح فاصل مسار في قواعد لارافيل، فقاعدة
     * متداخلة عليه تصف عنصرًا غير موجود وتمرّ أي قيمة بلا فحص —
     * ومقام صفر أو نصّ يكسر حساب النسبة لكل طالب في الموقع.
     */
    public function test_admin_settings_validate_each_value(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $this->putJson('/api/v1/admin/settings', [
            'settings' => ['program.total_credit_hours' => 0],
        ])->assertStatus(422);

        $this->putJson('/api/v1/admin/settings', [
            'settings' => ['program.total_credit_hours' => 'مئة'],
        ])->assertStatus(422);

        $this->assertNull(AppSetting::get('program.total_credit_hours'));
    }

    public function test_staff_cannot_reach_admin_settings(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'supervisor']));

        $this->getJson('/api/v1/admin/settings')->assertForbidden();
    }

    public function test_staff_can_save_the_new_course_fields(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $this->postJson('/api/v1/staff/courses', [
            'code' => 'NEW 100',
            'name_ar' => 'مساق جديد',
            'name_en' => 'New Course',
            'year' => 1,
            'semester' => 1,
            'credit_hours' => 4,
            'course_type' => 'elective',
            'description' => 'وصف',
        ])->assertCreated()
            ->assertJsonPath('data.credit_hours', 4)
            ->assertJsonPath('data.course_type', 'elective');
    }

    public function test_course_type_is_restricted_to_the_three_known_values(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $this->postJson('/api/v1/staff/courses', [
            'code' => 'NEW 100',
            'name_ar' => 'مساق جديد',
            'year' => 1,
            'semester' => 1,
            'course_type' => 'mandatory',
        ])->assertStatus(422);
    }
}
