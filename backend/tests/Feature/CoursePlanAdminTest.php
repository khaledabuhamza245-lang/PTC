<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * إدارة مواد الخطة من اللوحة.
 *
 * الخطر الأول هنا صامت تمامًا: مادة تُحفظ بـ page فارغة **تنجح** ثم
 * تختفي عن كل طالب، لأن dynamic-courses.js يرشّح بالصفحة حرفيًّا. لا
 * رسالة خطأ ولا صفّ ناقص — تظهر في اللوحة ولا تظهر في الموقع.
 */
class CoursePlanAdminTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function payload(array $extra = []): array
    {
        return array_merge([
            'code' => 'CMP0 1111',
            'name_ar' => 'مادة تجريبية',
            'name_en' => 'Test Course',
            'year' => 2,
            'semester' => 1,
            'credit_hours' => 3,
            'course_type' => 'required',
        ], $extra);
    }

    /**
     * الفصل رقمٌ عالميّ ١..٨ لا رقمٌ داخل السنة.
     *
     * الحدّ القديم `between:1,3` كان يرفض كل مادة خارج السنة الأولى —
     * ولم يظهر قطّ لأن نموذج الإضافة كان معطَّلًا بالتعليق منذ كُتب،
     * فظلّ المسار غير مستعمَل حتى فُتح التبويب.
     */
    public function test_every_semester_of_the_four_years_is_accepted(): void
    {
        $this->admin();

        foreach ([1 => 1, 2 => 4, 3 => 5, 4 => 8] as $year => $semester) {
            $this->postJson('/api/v1/staff/courses', $this->payload([
                'code' => 'SEM '.$semester,
                'year' => $year,
                'semester' => $semester,
            ]))->assertCreated()->assertJsonPath('data.semester', $semester);
        }
    }

    public function test_a_semester_beyond_eight_is_still_rejected(): void
    {
        $this->admin();

        $this->postJson('/api/v1/staff/courses', $this->payload(['semester' => 9]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('semester');
    }

    public function test_page_is_derived_from_the_year(): void
    {
        $this->admin();

        $this->postJson('/api/v1/staff/courses', $this->payload(['year' => 3]))
            ->assertCreated()
            ->assertJsonPath('data.page', 'year3.html');
    }

    public function test_an_elective_lands_on_the_electives_page(): void
    {
        $this->admin();

        $this->postJson('/api/v1/staff/courses', $this->payload([
            'year' => 4,
            'course_type' => 'elective',
        ]))->assertCreated()->assertJsonPath('data.page', 'electives.html');
    }

    public function test_moving_a_course_between_years_moves_its_page(): void
    {
        $this->admin();

        $key = $this->postJson('/api/v1/staff/courses', $this->payload(['year' => 2]))
            ->assertCreated()
            ->json('data.key');

        /*
         * لولا التحديث التلقائي لبقيت page على year2.html بعد النقل،
         * فتظلّ المادة معروضة في سنتها القديمة للطالب وحده.
         */
        $this->patchJson("/api/v1/staff/courses/{$key}", ['year' => 4])
            ->assertOk()
            ->assertJsonPath('data.page', 'year4.html');
    }

    public function test_an_explicit_page_is_still_respected(): void
    {
        $this->admin();

        $this->postJson('/api/v1/staff/courses', $this->payload(['page' => 'year1.html']))
            ->assertCreated()
            ->assertJsonPath('data.page', 'year1.html');
    }

    public function test_a_new_course_lands_last_in_its_semester(): void
    {
        $this->admin();

        $first = $this->postJson('/api/v1/staff/courses', $this->payload([
            'code' => 'AAA 100',
        ]))->assertCreated()->json('data.sort_order');

        $second = $this->postJson('/api/v1/staff/courses', $this->payload([
            'code' => 'BBB 200',
        ]))->assertCreated()->json('data.sort_order');

        $this->assertGreaterThan($first, $second);
    }

    public function test_reorder_renumbers_the_whole_list_in_one_request(): void
    {
        $this->admin();

        $keys = [];

        foreach (['AAA 100', 'BBB 200', 'CCC 300'] as $code) {
            $keys[] = $this->postJson('/api/v1/staff/courses', $this->payload(['code' => $code]))
                ->assertCreated()
                ->json('data.key');
        }

        /* عكس الترتيب: أول ما كان آخرًا. */
        $reversed = array_reverse($keys);

        $this->putJson('/api/v1/staff/courses/reorder', ['keys' => $reversed])
            ->assertOk()
            ->assertJsonPath('data.count', 3);

        foreach ($reversed as $index => $key) {
            $this->assertSame($index, Course::where('key', $key)->value('sort_order'), $key);
        }
    }

    public function test_reorder_rejects_a_key_that_does_not_exist(): void
    {
        $this->admin();

        $this->putJson('/api/v1/staff/courses/reorder', ['keys' => ['c_NOPE']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('keys.0');
    }

    public function test_reorder_is_closed_to_students(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'student']));

        $this->putJson('/api/v1/staff/courses/reorder', ['keys' => []])->assertForbidden();
    }

    /** الترتيب الذي يضبطه الطاقم يجب أن يصل الطالب لا أن يقف في اللوحة. */
    public function test_the_public_catalog_follows_the_saved_order(): void
    {
        $this->admin();

        $keys = [];

        foreach (['AAA 100', 'BBB 200', 'CCC 300'] as $code) {
            $keys[] = $this->postJson('/api/v1/staff/courses', $this->payload(['code' => $code]))
                ->assertCreated()
                ->json('data.key');
        }

        $reversed = array_reverse($keys);
        $this->putJson('/api/v1/staff/courses/reorder', ['keys' => $reversed])->assertOk();

        $order = array_column(
            $this->getJson('/api/v1/courses?year=2&semester=1')->assertOk()->json('data'),
            'key',
        );

        $this->assertSame($reversed, $order);
    }

    public function test_login_stamps_last_login_without_touching_updated_at(): void
    {
        $user = User::factory()->create([
            'email' => 'plan@ptchub.test',
            'password' => 'Secret!Pass123',
            'role' => 'student',
        ]);

        $this->assertNull($user->last_login_at);

        $before = $user->updated_at;

        $this->postJson('/api/v1/auth/login', [
            'email' => 'plan@ptchub.test',
            'password' => 'Secret!Pass123',
        ])->assertOk();

        $user->refresh();

        $this->assertNotNull($user->last_login_at);

        /* الدخول ليس تعديلًا على الحساب — خلطهما يُفقد الحقلين معنييهما. */
        $this->assertEquals(
            $before->toDateTimeString(),
            $user->updated_at->toDateTimeString(),
        );
    }

    public function test_the_users_list_carries_last_login(): void
    {
        User::factory()->create([
            'email' => 'seen@ptchub.test',
            'password' => 'Secret!Pass123',
            'role' => 'student',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'seen@ptchub.test',
            'password' => 'Secret!Pass123',
        ])->assertOk();

        $this->admin();

        $row = collect($this->getJson('/api/v1/staff/users')->assertOk()->json('data'))
            ->firstWhere('email', 'seen@ptchub.test');

        $this->assertNotNull($row['last_login_at'] ?? null);
    }

    public function test_a_never_logged_in_user_reports_null(): void
    {
        User::factory()->create(['email' => 'quiet@ptchub.test', 'role' => 'student']);

        $this->admin();

        $row = collect($this->getJson('/api/v1/staff/users')->assertOk()->json('data'))
            ->firstWhere('email', 'quiet@ptchub.test');

        $this->assertNull($row['last_login_at']);
    }

    /**
     * إعادة إضافة مادة محذوفة تُردّ بـ422 تشرح، لا بـ500 غامضة.
     *
     * الحذف ناعم، فالصفّ وفهرسه الفريد باقيان في القاعدة بينما نطاق
     * SoftDeletes يخفيهما عن فحص التكرار. وقاعدة unique لا تنقذ لأن `key`
     * محقَّقة `nullable` عند الإنشاء واللوحة لا ترسلها أصلًا — makeKey
     * يولّدها من الرمز نفسه. فكان الاصطدام يقع في القاعدة: SQLSTATE 23000.
     */
    public function test_recreating_a_soft_deleted_course_is_rejected_with_a_clear_message(): void
    {
        $this->admin();

        $key = $this->postJson('/api/v1/staff/courses', $this->payload())
            ->assertCreated()->json('data.key');

        $this->deleteJson('/api/v1/staff/courses/'.$key)->assertOk();

        // الرمز نفسه بلا key صريحة — كما ترسله اللوحة تمامًا
        $response = $this->postJson('/api/v1/staff/courses', $this->payload())
            ->assertStatus(422);

        $this->assertTrue($response->json('trashed'));
        $this->assertStringContainsString('سلة المحذوفات', (string) $response->json('message'));
    }
}
