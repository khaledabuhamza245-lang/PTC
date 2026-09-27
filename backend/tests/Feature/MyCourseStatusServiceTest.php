<?php

namespace Tests\Feature;

use App\Exceptions\CourseStatusException;
use App\Models\Course;
use App\Models\Term;
use App\Models\User;
use App\Services\MyCourseStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * يثبّت أن App\Services\MyCourseStatusService (المستخرَجة لخدمة ميزة
 * التحكّم بالخطة من بوت تيليجرام) تطابق سلوك
 * App\Http\Controllers\Api\V1\MyCourseController الحيّ حرفيًا — نفس
 * السيناريوهات المغطاة أصلًا بـ AcademicPlanTest/ManualEnrollmentOnlyTest،
 * لكن عبر الخدمة مباشرة لا عبر HTTP، لأن المتحكّم الأصلي لم يُمَسّ
 * بهذه الخطوة (راجع تعليق أعلى الخدمة نفسها).
 */
class MyCourseStatusServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): MyCourseStatusService
    {
        return app(MyCourseStatusService::class);
    }

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
            'is_active' => true,
        ], $extra));
    }

    /** مطابقة test_student_marks_a_course_completed_and_gets_a_timestamp. */
    public function test_completing_a_course_stamps_completed_at_and_reverting_clears_it(): void
    {
        $user = User::factory()->create();
        $course = $this->course('AAA 100');
        $user->myCourses()->attach($course->id);

        $result = $this->service()->setStatus($user, $course, 'completed');

        $this->assertSame('completed', $result['status']);
        $this->assertNotNull($result['completed_at']);

        $result = $this->service()->setStatus($user, $course, 'registered');

        $this->assertSame('registered', $result['status']);
        $this->assertNull($result['completed_at']);
    }

    /** مطابقة test_status_update_is_rejected_for_a_course_the_student_did_not_add. */
    public function test_setting_status_on_an_unregistered_course_throws_404(): void
    {
        $user = User::factory()->create();
        $course = $this->course('AAA 100');

        $this->expectException(CourseStatusException::class);

        try {
            $this->service()->setStatus($user, $course, 'completed');
        } catch (CourseStatusException $e) {
            $this->assertSame(404, $e->status);

            throw $e;
        }
    }

    /** مطابقة test_status_must_be_one_of_the_three_known_values. */
    public function test_an_unknown_status_value_is_rejected(): void
    {
        $user = User::factory()->create();
        $course = $this->course('AAA 100');
        $user->myCourses()->attach($course->id);

        $this->expectException(CourseStatusException::class);

        try {
            $this->service()->setStatus($user, $course, 'graduated');
        } catch (CourseStatusException $e) {
            $this->assertSame(422, $e->status);

            throw $e;
        }
    }

    /** ensureStatus (اختصار البوت) يضيف المساق أولًا إن لم يكن مسجَّلًا. */
    public function test_ensure_status_attaches_a_new_course_then_sets_its_status(): void
    {
        $user = User::factory()->create();
        $course = $this->course('AAA 100');

        $this->assertSame(0, $user->myCourses()->count());

        $result = $this->service()->ensureStatus($user, $course, 'completed');

        $this->assertSame('completed', $result['status']);
        $this->assertSame(1, $user->myCourses()->count());
    }

    /**
     * مطابقة قاعدة destroy(): مساق من الفصل الحالي المُعلَن للطالب لا
     * يُحذف فعليًا — يتحوّل لـ"منسحب" فقط.
     */
    public function test_removing_a_current_semester_course_marks_it_dropped_not_deleted(): void
    {
        $term = Term::create([
            'code' => 't1', 'label' => 'الفصل الأول', 'academic_year' => '2025-2026', 'semester' => 1,
        ]);

        $user = User::factory()->create(['year' => 1, 'current_term_id' => $term->id]);
        $course = $this->course('AAA 100', ['year' => 1, 'semester' => 1, 'course_type' => 'required']);
        $user->myCourses()->attach($course->id, ['status' => 'registered', 'source' => 'manual']);

        $this->service()->remove($user, $course);

        $row = DB::table('my_courses')->where('user_id', $user->id)->where('course_id', $course->id)->first();

        $this->assertNotNull($row);
        $this->assertSame('dropped', $row->status);
    }

    /** ومساق يدوي خارج الفصل الحالي يُحذف فعليًا من الجدول. */
    public function test_removing_a_manually_added_non_current_course_deletes_the_row(): void
    {
        $user = User::factory()->create(['year' => 3]);
        $course = $this->course('CCC 300', ['year' => 1, 'semester' => 1]);
        $user->myCourses()->attach($course->id, ['status' => 'registered', 'source' => 'manual']);

        $this->service()->remove($user, $course);

        $this->assertSame(0, DB::table('my_courses')->where('user_id', $user->id)->where('course_id', $course->id)->count());
    }

    /** مساق بمصدر "automatic" لا يُحذف فعليًا أبدًا، بغضّ النظر عن فصله. */
    public function test_removing_an_automatic_source_course_marks_it_dropped(): void
    {
        $user = User::factory()->create(['year' => 3]);
        $course = $this->course('CCC 300', ['year' => 1, 'semester' => 1]);
        $user->myCourses()->attach($course->id, ['status' => 'registered', 'source' => 'automatic']);

        $this->service()->remove($user, $course);

        $row = DB::table('my_courses')->where('user_id', $user->id)->where('course_id', $course->id)->first();

        $this->assertNotNull($row);
        $this->assertSame('dropped', $row->status);
    }

    /** إعادة تسجيل مساق "منسحب" يصفّر تاريخ الإنجاز ويعيده لفصل الطالب الحالي. */
    public function test_attaching_a_dropped_course_again_reactivates_it_as_registered(): void
    {
        $term = Term::create([
            'code' => 't1', 'label' => 'الفصل الأول', 'academic_year' => '2025-2026', 'semester' => 1,
        ]);

        $user = User::factory()->create(['current_term_id' => $term->id]);
        $course = $this->course('AAA 100');
        $user->myCourses()->attach($course->id, ['status' => 'dropped', 'source' => 'manual', 'completed_at' => now()]);

        $this->service()->attach($user, $course);

        $row = DB::table('my_courses')->where('user_id', $user->id)->where('course_id', $course->id)->first();

        $this->assertSame('registered', $row->status);
        $this->assertSame($term->id, $row->term_id);
        $this->assertNull($row->completed_at);
    }

    /** لا مساق اختياري بلا خانة مفتوحة فعليًا بفصل الطالب الحالي. */
    public function test_attaching_an_elective_without_an_open_slot_is_rejected(): void
    {
        $user = User::factory()->create(['year' => 1]);
        $elective = $this->course('ELEC 100', ['course_type' => 'elective', 'year' => 4, 'semester' => 7]);

        $this->expectException(CourseStatusException::class);

        try {
            $this->service()->attach($user, $elective);
        } catch (CourseStatusException $e) {
            $this->assertSame(422, $e->status);

            throw $e;
        }
    }

    /** مساق غير مفعَّل (is_active=false) لا يُضاف إطلاقًا. */
    public function test_attaching_an_inactive_course_is_rejected(): void
    {
        $user = User::factory()->create();
        $course = $this->course('AAA 100', ['is_active' => false]);

        $this->expectException(CourseStatusException::class);

        try {
            $this->service()->attach($user, $course);
        } catch (CourseStatusException $e) {
            $this->assertSame(404, $e->status);

            throw $e;
        }
    }
}
