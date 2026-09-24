<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * توجيه الإعلان: لكل الطلاب، أو لسنة، أو لمساق.
 *
 * الخطر هنا في اتجاهين متعاكسين، وكلاهما صامت:
 * إعلانٌ يصل من لا يعنيه (تسريب)، وإعلانٌ لا يصل أحدًا (يبدو منشورًا
 * في اللوحة ولا يراه إنسان). لا شيء في الواجهة يكشف أيًّا منهما.
 */
class AnnouncementAudienceTest extends TestCase
{
    use RefreshDatabase;

    private function course(string $code): Course
    {
        return Course::create([
            'key' => 'c_'.$code,
            'code' => $code,
            'name_ar' => 'مساق '.$code,
            'name_en' => 'Course '.$code,
            'year' => 1,
            'semester' => 1,
            'is_active' => true,
        ]);
    }

    private function announce(array $attributes): Announcement
    {
        return Announcement::create($attributes + [
            'title' => 'إعلان',
            'active' => true,
            'audience' => 'all',
        ]);
    }

    private function titlesFor(?User $user): array
    {
        if ($user) {
            Sanctum::actingAs($user);
        }

        return array_column(
            $this->getJson('/api/v1/announcements')->assertOk()->json('data'),
            'title',
        );
    }

    public function test_public_announcement_reaches_everyone_including_guests(): void
    {
        $this->announce(['title' => 'للجميع']);

        $this->assertSame(['للجميع'], $this->titlesFor(null));
        $this->assertSame(['للجميع'], $this->titlesFor(User::factory()->create(['year' => 2])));
    }

    public function test_year_announcement_reaches_only_that_year(): void
    {
        $this->announce(['title' => 'للسنة الثانية', 'audience' => 'year', 'audience_year' => 2]);

        $this->assertSame(['للسنة الثانية'], $this->titlesFor(User::factory()->create(['year' => 2])));
        $this->assertSame([], $this->titlesFor(User::factory()->create(['year' => 3])));
        $this->assertSame([], $this->titlesFor(User::factory()->create(['year' => null])));
    }

    public function test_year_announcement_never_reaches_a_guest(): void
    {
        $this->announce(['title' => 'للسنة الأولى', 'audience' => 'year', 'audience_year' => 1]);

        $this->assertSame([], $this->titlesFor(null));
    }

    public function test_course_announcement_reaches_only_the_enrolled(): void
    {
        $course = $this->course('CMP101');
        $other = $this->course('CMP202');

        $this->announce([
            'title' => 'لمساق CMP101',
            'audience' => 'course',
            'course_id' => $course->id,
        ]);

        $enrolled = User::factory()->create(['year' => 1]);
        $enrolled->myCourses()->attach($course->id, ['status' => 'registered']);

        $elsewhere = User::factory()->create(['year' => 1]);
        $elsewhere->myCourses()->attach($other->id, ['status' => 'registered']);

        $this->assertSame(['لمساق CMP101'], $this->titlesFor($enrolled));
        $this->assertSame([], $this->titlesFor($elsewhere));
        $this->assertSame([], $this->titlesFor(User::factory()->create(['year' => 1])));
    }

    /**
     * الانسحاب يُخرِج صاحبه من إعلانات المساق.
     *
     * الصفّ يبقى في my_courses بحالة dropped — الانسحاب لا يحذفه، والحذف
     * من بطاقة «مساقاتي» وحده يحذفه. فبلا قيد الحالة يظلّ من أعلن «لم
     * أعد في هذا المساق» يستقبل إعلاناته، ولا شيء في الواجهة يكشف ذلك.
     */
    public function test_a_dropped_course_stops_delivering_its_announcements(): void
    {
        $course = $this->course('CMP101');

        $this->announce([
            'title' => 'لمساق CMP101',
            'audience' => 'course',
            'course_id' => $course->id,
        ]);

        $student = User::factory()->create(['year' => 1]);
        $student->myCourses()->attach($course->id, ['status' => 'registered']);

        $this->assertSame(['لمساق CMP101'], $this->titlesFor($student));

        $student->myCourses()->updateExistingPivot($course->id, ['status' => 'dropped']);

        $this->assertSame([], $this->titlesFor($student->fresh()));

        /* والصفّ لم يُحذف — القيد على الحالة لا على وجود التسجيل. */
        $this->assertDatabaseHas('my_courses', [
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'dropped',
        ]);
    }

    public function test_a_completed_course_still_delivers_its_announcements(): void
    {
        $course = $this->course('CMP101');

        $this->announce([
            'title' => 'نتائج CMP101',
            'audience' => 'course',
            'course_id' => $course->id,
        ]);

        $student = User::factory()->create(['year' => 1]);

        $student->myCourses()->attach($course->id, [
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        /* «صدرت النتائج» يخصّ من أنهى المساق كما يخصّ من فيه. */
        $this->assertSame(['نتائج CMP101'], $this->titlesFor($student));
    }

    public function test_a_student_sees_the_union_of_what_applies_without_duplicates(): void
    {
        $course = $this->course('CMP101');

        $this->announce(['title' => 'عام']);
        $this->announce(['title' => 'سنة', 'audience' => 'year', 'audience_year' => 2]);
        $this->announce(['title' => 'مساق', 'audience' => 'course', 'course_id' => $course->id]);
        $this->announce(['title' => 'سنة أخرى', 'audience' => 'year', 'audience_year' => 4]);

        $student = User::factory()->create(['year' => 2]);
        $student->myCourses()->attach($course->id, ['status' => 'registered']);

        $titles = $this->titlesFor($student);

        sort($titles);
        $this->assertSame(['سنة', 'عام', 'مساق'], $titles);
    }

    public function test_inactive_announcement_is_never_served(): void
    {
        $this->announce(['title' => 'مطفأ', 'active' => false]);

        $this->assertSame([], $this->titlesFor(null));
    }

    public function test_targeted_announcement_carries_its_course_for_display(): void
    {
        $course = $this->course('CMP101');

        $this->announce(['title' => 'مع المساق', 'audience' => 'course', 'course_id' => $course->id]);

        $student = User::factory()->create(['year' => 1]);
        $student->myCourses()->attach($course->id, ['status' => 'registered']);

        Sanctum::actingAs($student);

        $this->getJson('/api/v1/announcements')
            ->assertOk()
            ->assertJsonPath('data.0.course.code', 'CMP101');
    }

    public function test_year_audience_without_a_year_is_rejected(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        /* لو مرّ لحُفظ بـ audience_year = NULL فلا يطابق أي طالب أبدًا. */
        $this->postJson('/api/v1/staff/announcements', [
            'title' => 'ناقص',
            'audience' => 'year',
        ])->assertStatus(422)->assertJsonValidationErrors('audience_year');
    }

    public function test_course_audience_without_a_course_is_rejected(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $this->postJson('/api/v1/staff/announcements', [
            'title' => 'ناقص',
            'audience' => 'course',
        ])->assertStatus(422)->assertJsonValidationErrors('course_id');
    }

    public function test_switching_back_to_everyone_clears_the_old_target(): void
    {
        $course = $this->course('CMP101');

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $id = $this->postJson('/api/v1/staff/announcements', [
            'title' => 'موجَّه',
            'audience' => 'course',
            'course_id' => $course->id,
        ])->assertCreated()->json('data.id');

        /* التحويل إلى العام بلا ذكر course_id — يجب أن يُمسح لا أن يبقى. */
        $this->patchJson("/api/v1/staff/announcements/{$id}", ['audience' => 'all'])
            ->assertOk()
            ->assertJsonPath('data.audience', 'all')
            ->assertJsonPath('data.course_id', null);

        $this->assertDatabaseHas('announcements', [
            'id' => $id,
            'audience' => 'all',
            'course_id' => null,
        ]);
    }

    public function test_editing_the_title_alone_keeps_the_target(): void
    {
        $course = $this->course('CMP101');

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $id = $this->postJson('/api/v1/staff/announcements', [
            'title' => 'موجَّه',
            'audience' => 'course',
            'course_id' => $course->id,
        ])->assertCreated()->json('data.id');

        /* طلبٌ لا يذكر الوجهة يحتكم إلى المخزَّن لا إلى الافتراضي. */
        $this->patchJson("/api/v1/staff/announcements/{$id}", ['title' => 'عنوان جديد'])
            ->assertOk()
            ->assertJsonPath('data.audience', 'course')
            ->assertJsonPath('data.course_id', $course->id);
    }

    public function test_existing_announcements_default_to_everyone(): void
    {
        /* الهجرة تضع all افتراضًا، فما كُتب قبلها يبقى ظاهرًا للجميع. */
        $item = Announcement::create(['title' => 'قديم', 'active' => true]);

        $this->assertSame('all', $item->fresh()->audience);
        $this->assertSame(['قديم'], $this->titlesFor(null));
    }
}
