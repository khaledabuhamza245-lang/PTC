<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ضمانات أمنية على مستوى الإعداد — تسقط لو أُزيلت بالخطأ.
 */
class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('api');
        RateLimiter::clear('auth');
    }

    public function test_sanctum_tokens_expire(): void
    {
        $this->assertNotNull(
            config('sanctum.expiration'),
            'توكنات Sanctum يجب أن تنتهي صلاحيتها — null يعني صلاحية أبدية'
        );
        $this->assertGreaterThan(0, config('sanctum.expiration'));
    }

    public function test_expired_token_is_rejected(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('web')->plainTextToken;

        // صالح الآن
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/me')
            ->assertOk();

        // وبعد تجاوز مدة الصلاحية
        $this->travel(config('sanctum.expiration') + 1)->minutes();

        // RequestGuard يخزّن المستخدم بعد أول تحقق، والتطبيق يعيش عبر
        // طلبات الاختبار كلها — بعكس الإنتاج حيث كل طلب عملية مستقلة.
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/me')
            ->assertUnauthorized();
    }

    public function test_api_routes_are_rate_limited(): void
    {
        $limit = config('api.rate_limit.guest');
        $this->assertGreaterThan(0, $limit);

        for ($i = 0; $i < $limit; $i++) {
            $this->getJson('/api/v1/courses')->assertOk();
        }

        $this->getJson('/api/v1/courses')->assertStatus(429);
    }

    public function test_one_user_hitting_the_limit_does_not_block_another(): void
    {
        // المفتاح معرّف المستخدم لا الـ IP. لو كان الـ IP لاستهلك طلاب
        // الجامعة الجالسون خلف عنوان واحد حصة بعضهم، ولأسقط أحدهم الخدمة
        // عن الباقين. الفحص سلوكي: نستنفد حصة مستخدم ثم نتأكد أن غيره
        // ما زال يعمل من نفس العنوان.
        $a = User::factory()->create();
        $b = User::factory()->create();
        $limit = config('api.rate_limit.user');

        for ($i = 0; $i < $limit; $i++) {
            $this->actingAs($a)->getJson('/api/v1/me')->assertOk();
        }

        $this->actingAs($a)->getJson('/api/v1/me')->assertStatus(429);

        $this->app['auth']->forgetGuards();
        $this->actingAs($b)->getJson('/api/v1/me')
            ->assertOk('استهلاك مستخدم يجب ألا يمس حصة مستخدم آخر');
    }

    public function test_password_spraying_is_limited_per_ip(): void
    {
        // بريد مختلف كل محاولة: الحدّ لكل (بريد، IP) وحده لا يوقف هذا،
        // فلا بد من حدّ ثانٍ لكل IP.
        $perIp = config('api.rate_limit.auth_per_ip');

        for ($i = 0; $i < $perIp; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => "spray{$i}@example.test",
                'password' => 'wrong-password',
            ])->assertStatus(422);
        }

        $this->postJson('/api/v1/auth/login', [
            'email' => 'spray-final@example.test',
            'password' => 'wrong-password',
        ])->assertStatus(429);
    }

    /**
     * التسجيل في مساق غير منشور ممنوع.
     *
     * صفّ myCourses ليس تخطيطًا وحده: هو تعريف «طالب المساق» الذي تُبنى
     * عليه رؤية إعلانات audience=course وملفات course_students. فلو جاز
     * التسجيل في مسودّة لم تُنشَر، قرأ الطالب إعلاناتها.
     */
    public function test_student_cannot_enroll_in_an_inactive_course(): void
    {
        $course = Course::create([
            'key' => 'c_HID101',
            'code' => 'HID 101',
            'name_ar' => 'مساق مخفي',
            'name_en' => 'Hidden Course',
            'year' => 1,
            'semester' => 1,
            'credit_hours' => 3,
            'course_type' => 'required',
            'is_active' => false,
        ]);

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/my-courses/'.$course->key)->assertNotFound();

        $this->assertSame(
            [],
            $user->myCourses()->pluck('courses.id')->all(),
            'مساق غير منشور يجب ألا يدخل خطة الطالب'
        );
    }

    /**
     * تصفير كلمة السر من لوحة الأدمن يُسقط جلسات صاحب الحساب.
     *
     * هذا العلاج الوحيد الذي تعرضه اللوحة لحساب مخترَق. وسانكتم لا يربط
     * التوكن بتجزئة كلمة السر، فبقاؤه حيًّا يعني أن المهاجم يظلّ داخلًا
     * مدة صلاحية التوكن كاملة بعد أن يطمئن الأدمن والطالب معًا.
     */
    public function test_admin_password_reset_revokes_the_target_tokens(): void
    {
        $student = User::factory()->create([
            'email' => 'victim@example.test',
            'password' => 'Student@12345',
        ]);
        $stolen = $student->createToken('web')->plainTextToken;

        // التوكن يعمل قبل التصفير
        $this->withToken($stolen)->getJson('/api/v1/me')->assertOk();

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $this->putJson('/api/v1/admin/users/'.$student->id, [
            'first_name' => $student->first_name,
            'father_name' => $student->father_name,
            'last_name' => $student->last_name,
            'email' => $student->email,
            'password' => 'BrandNew@6789',
            'password_confirmation' => 'BrandNew@6789',
        ])->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withToken($stolen)->getJson('/api/v1/me')
            ->assertUnauthorized('التوكن المسروق يجب أن يسقط مع تصفير كلمة السر');
    }
}
