<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ⛔ لا يدخل «مساقاتي الحالية» صفٌّ إلا بيد الطالب.
 *
 * لا إنشاء الحساب ولا تغيير السنة من «بياناتي» يسجّل شيئًا — والسنة
 * تُحفظ في الحالتين لأن الخطة و«بياناتي» تقرآنها، لكنها إعلانُ موضعٍ
 * لا جدولُ فصلٍ قائم.
 *
 * وهذا ليس تفصيلًا في العرض: «مساقاتي الحالية» والخطة الأكاديمية
 * يقرآن الجدول نفسه (myCourses)، وحالةُ المساق فيه **مصدر الحقيقة
 * الوحيد** لحساب التخرّج (قرار ٧٫١). فصفٌّ يكتبه النظام نيابةً عن
 * الطالب يُحتسب عليه إن سها عنه — لذلك الاختبار حارسٌ لا وصف.
 */
class ManualEnrollmentOnlyTest extends TestCase
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

    private function register(array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/auth/register', array_merge([
            'first_name' => 'أحمد',
            'father_name' => 'محمد',
            'last_name' => 'عيسى',
            'email' => 'student@ptchub.com',
            'password' => 'Student@123456',
            'password_confirmation' => 'Student@123456',
        ], $extra));
    }

    /** الحساب الجديد يفتح فارغًا مهما أعلن صاحبه من سنة. */
    public function test_registering_with_a_year_enrolls_nothing(): void
    {
        $this->course('AAA 100', ['year' => 1]);
        $this->course('AAA 101', ['year' => 1, 'semester' => 2]);

        $this->register(['year' => 1])
            ->assertCreated()
            ->assertJsonMissingPath('auto_enrolled');

        $user = User::where('email', 'student@ptchub.com')->firstOrFail();

        $this->assertSame(1, $user->year);
        $this->assertSame(0, $user->myCourses()->count());
    }

    public function test_registering_without_a_year_enrolls_nothing(): void
    {
        $this->course('AAA 100', ['year' => 1]);

        $this->register()->assertCreated();

        $user = User::where('email', 'student@ptchub.com')->firstOrFail();

        $this->assertSame(0, $user->myCourses()->count());
    }

    /** الانتقال بين السنوات يحفظ السنة ولا يسجّل مساقًا. */
    public function test_changing_the_year_from_the_profile_enrolls_nothing(): void
    {
        $this->course('CCC 300', ['year' => 3, 'semester' => 5]);
        $this->course('CCC 301', ['year' => 3, 'semester' => 6]);

        $user = User::factory()->create(['year' => 2]);
        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/me', ['year' => 3])
            ->assertOk()
            ->assertJsonPath('data.year', 3)
            ->assertJsonMissingPath('auto_enrolled');

        $this->assertSame(0, $user->myCourses()->count());
    }

    /**
     * وما أضافه الطالب بيده لا تمسّه السنة.
     *
     * الحارس المقابل: منعُ الكتابة التلقائية لا يجوز أن يتحوّل إلى
     * محوٍ لما أقرّه الطالب — إقراره يبقى عبر تغيّر السنة وبعده.
     */
    public function test_a_hand_added_course_survives_a_year_change(): void
    {
        $mine = $this->course('CCC 300', ['year' => 3, 'semester' => 5]);

        $user = User::factory()->create(['year' => 3]);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/my-courses/'.$mine->key)->assertCreated();
        $this->patchJson('/api/v1/me', ['year' => 4])->assertOk();

        $this->assertSame([$mine->id], $user->myCourses()->pluck('courses.id')->all());
    }
}
