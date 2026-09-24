<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\Course;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * حاسبة التخرّج.
 *
 * كل اختبار هنا يحرس قاعدة كان يمكن كسرها بصمت: النتيجة تبقى رقمًا
 * معقولًا وهي خاطئة، فلا شيء يكشفها إلا التثبيت بالمثال.
 */
class PlanSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        AppSetting::create([
            'key' => 'program.total_credit_hours',
            'value' => '142',
            'group' => 'program',
            'is_public' => true,
        ]);

        AppSetting::create([
            'key' => 'program.required_electives',
            'value' => '5',
            'group' => 'program',
            'is_public' => true,
        ]);
    }

    private function course(string $code, string $type, ?int $hours, int $year = 1, int $semester = 1): Course
    {
        return Course::create([
            'key' => 'c_'.str_replace(' ', '', $code),
            'code' => $code,
            'name_ar' => 'مساق '.$code,
            'name_en' => 'Course '.$code,
            'year' => $year,
            'semester' => $semester,
            'credit_hours' => $hours,
            'course_type' => $type,
        ]);
    }

    private function enroll(User $user, Course $course, string $status, ?string $completedAt = null): void
    {
        $user->myCourses()->attach($course->id, [
            'status' => $status,
            'completed_at' => $completedAt,
        ]);
    }

    public function test_completed_required_hours_are_summed(): void
    {
        $user = User::factory()->create();

        $this->enroll($user, $this->course('AAA 100', 'required', 3), 'completed');
        $this->enroll($user, $this->course('BBB 100', 'required', 4), 'completed');
        $this->enroll($user, $this->course('CCC 100', 'required', 3), 'registered');

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/me/plan-summary')
            ->assertOk()
            ->assertJsonPath('data.completed_hours', 7)
            ->assertJsonPath('data.total_credit_hours', 142)
            ->assertJsonPath('data.remaining_hours', 135)
            ->assertJsonPath('data.counts.completed', 2)
            ->assertJsonPath('data.counts.registered', 1);
    }

    /** الخانة النائبة موضع في المقام لا مساق — احتسابها يضيف ساعات وهمية. */
    public function test_placeholder_never_counts_towards_completed_hours(): void
    {
        $user = User::factory()->create();

        $this->enroll($user, $this->course('AAA 100', 'required', 3), 'completed');
        $this->enroll($user, $this->course('EEEX 35XX', 'placeholder', 3, 3, 6), 'completed');

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/me/plan-summary')
            ->assertOk()
            ->assertJsonPath('data.completed_hours', 3);
    }

    /** الاختياري السادس لا يرفع البسط، وإلا تجاوزت النسبة ١٠٠٪. */
    public function test_only_the_first_five_electives_are_credited(): void
    {
        $user = User::factory()->create();

        for ($i = 1; $i <= 6; $i++) {
            $this->enroll(
                $user,
                $this->course('ELE 10'.$i, 'elective', 3, 4, 3),
                'completed',
                '2026-01-0'.$i.' 00:00:00',
            );
        }

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/me/plan-summary')
            ->assertOk()
            ->assertJsonPath('data.completed_hours', 15)
            ->assertJsonPath('data.electives.counted', 5)
            ->assertJsonPath('data.electives.extra', 1);
    }

    /** الترتيب بتاريخ الإنجاز: الأقدم يدخل السقف، والأحدث يفيض. */
    public function test_electives_are_credited_oldest_first(): void
    {
        $user = User::factory()->create();

        AppSetting::where('key', 'program.required_electives')->update(['value' => '1']);

        $this->enroll($user, $this->course('ELE 200', 'elective', 4, 4, 3), 'completed', '2026-05-01 00:00:00');
        $this->enroll($user, $this->course('ELE 201', 'elective', 3, 4, 3), 'completed', '2026-01-01 00:00:00');

        Sanctum::actingAs($user);

        /* الأقدم ساعاته ٣، فاحتسابه دليل على أن الترتيب بالتاريخ لا بالإدخال. */
        $this->getJson('/api/v1/me/plan-summary')
            ->assertOk()
            ->assertJsonPath('data.completed_hours', 3)
            ->assertJsonPath('data.electives.counted', 1);
    }

    /** ساعات مفقودة تُجمَع صفرًا لكن تُعدّ — نقص البيانات لا يذوب صامتًا. */
    public function test_missing_credit_hours_are_counted_not_hidden(): void
    {
        $user = User::factory()->create();

        $this->enroll($user, $this->course('AAA 100', 'required', null), 'completed');
        $this->enroll($user, $this->course('BBB 100', 'required', 3), 'completed');

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/me/plan-summary')
            ->assertOk()
            ->assertJsonPath('data.completed_hours', 3)
            ->assertJsonPath('data.courses_missing_hours', 1);
    }

    /** المقام من الإعدادات لا مجموعًا من الجدول. */
    public function test_denominator_comes_from_settings(): void
    {
        $user = User::factory()->create();

        AppSetting::where('key', 'program.total_credit_hours')->update(['value' => '120']);

        $this->enroll($user, $this->course('AAA 100', 'required', 30), 'completed');

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/me/plan-summary')
            ->assertOk()
            ->assertJsonPath('data.total_credit_hours', 120)
            ->assertJsonPath('data.percent', 25);
    }

    public function test_by_year_breakdown_counts_plan_hours(): void
    {
        $user = User::factory()->create();

        $this->enroll($user, $this->course('AAA 100', 'required', 3, 1, 1), 'completed');
        $this->course('BBB 100', 'required', 3, 1, 2);
        $this->course('CCC 200', 'required', 4, 2, 3);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/me/plan-summary')->assertOk();

        $response->assertJsonPath('data.by_year.0.year', 1);
        $response->assertJsonPath('data.by_year.0.plan_hours', 6);
        $response->assertJsonPath('data.by_year.0.completed_hours', 3);
        $response->assertJsonPath('data.by_year.0.percent', 50);
        $response->assertJsonPath('data.by_year.1.year', 2);
        $response->assertJsonPath('data.by_year.1.completed_hours', 0);
    }

    /** الاختيارية المحتسَبة تُنسب إلى سنة الخانة التي تملؤها. */
    public function test_credited_electives_fill_placeholder_slots_by_year(): void
    {
        $user = User::factory()->create();

        $this->course('EEEX 35XX', 'placeholder', 3, 3, 6);
        $this->enroll($user, $this->course('ELE 100', 'elective', 3, 4, 3), 'completed', '2026-01-01 00:00:00');

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/me/plan-summary')->assertOk();

        $years = collect($response->json('data.by_year'))->keyBy('year');

        $this->assertSame(3, $years[3]['plan_hours']);
        $this->assertSame(3, $years[3]['completed_hours'], 'الاختياري يملأ خانة السنة الثالثة');
    }

    public function test_plan_summary_requires_authentication(): void
    {
        $this->getJson('/api/v1/me/plan-summary')->assertUnauthorized();
    }

    public function test_status_map_reports_each_enrollment(): void
    {
        $user = User::factory()->create();
        $course = $this->course('AAA 100', 'required', 3);

        $this->enroll($user, $course, 'completed', '2026-02-02 00:00:00');

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/me/plan-summary')
            ->assertOk()
            ->assertJsonPath('data.statuses.c_AAA100.status', 'completed');
    }

    public function test_enrollments_can_be_filtered_by_current_term(): void
    {
        $user = User::factory()->create();

        $term = Term::create([
            'code' => '2026-1',
            'label' => 'الفصل الأول',
            'academic_year' => '2026/2027',
            'semester' => 1,
            'is_current' => true,
        ]);

        $inTerm = $this->course('AAA 100', 'required', 3);
        $outOfTerm = $this->course('BBB 100', 'required', 3);

        $user->myCourses()->attach($inTerm->id, ['status' => 'registered', 'term_id' => $term->id]);
        $user->myCourses()->attach($outOfTerm->id, ['status' => 'registered']);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/me/enrollments?term=current')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.key', 'c_AAA100');

        $this->getJson('/api/v1/me/enrollments')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }
}
