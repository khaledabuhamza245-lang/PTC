<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\TelegramLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * اختبار تكاملي حقيقي عبر مسار /api/v1/telegram/webhook الفعلي (لا عبر
 * الخدمة مباشرة كـ MyCourseStatusServiceTest) — يثبّت أن التوصيل كاملًا
 * (المسار → التحقق من التوقيع السري → توجيه callback_data ببادئة
 * "plan:" → الخدمة → قاعدة البيانات → الرد على تيليجرام) يعمل فعليًا
 * من طرف لطرف، لا فقط أجزاءه المعزولة. أهم اختبار لهذه الميزة كلها.
 */
class TelegramPlanCallbackTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.telegram.bot_token' => 'test-bot-token',
            'services.telegram.webhook_secret' => self::SECRET,
        ]);

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);
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

    private function linkedUser(int $chatId): User
    {
        $user = User::factory()->create(['year' => 1]);

        TelegramLink::create([
            'user_id' => $user->id,
            'telegram_chat_id' => $chatId,
            'telegram_first_name' => 'طالب',
            'linked_at' => now(),
        ]);

        return $user;
    }

    private function postCallback(int $chatId, int $messageId, string $data): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])
            ->postJson('/api/v1/telegram/webhook', [
                'update_id' => random_int(1, 999999999),
                'callback_query' => [
                    'id' => 'cbq1',
                    'data' => $data,
                    'message' => [
                        'message_id' => $messageId,
                        'chat' => ['id' => $chatId],
                    ],
                ],
            ]);
    }

    public function test_setting_a_course_to_completed_via_the_bot_writes_the_same_table_the_website_reads(): void
    {
        $course = $this->course('AAA 100');
        $user = $this->linkedUser(555001);

        $this->postCallback(555001, 10, "plan:s:{$course->id}:c")->assertOk();

        $row = DB::table('my_courses')->where('user_id', $user->id)->where('course_id', $course->id)->first();

        $this->assertNotNull($row);
        $this->assertSame('completed', $row->status);
        $this->assertNotNull($row->completed_at);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'editMessageText'));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'answerCallbackQuery'));
    }

    public function test_setting_status_to_none_removes_or_drops_the_enrollment(): void
    {
        $course = $this->course('AAA 100');
        $user = $this->linkedUser(555002);
        $user->myCourses()->attach($course->id, ['status' => 'registered', 'source' => 'manual']);

        $this->postCallback(555002, 11, "plan:s:{$course->id}:n")->assertOk();

        $this->assertSame(0, DB::table('my_courses')->where('user_id', $user->id)->where('course_id', $course->id)->count());
    }

    public function test_bulk_complete_a_term_marks_every_required_course_in_it_completed(): void
    {
        $user = $this->linkedUser(555003);
        $a = $this->course('AAA 100', ['year' => 1, 'semester' => 1]);
        $b = $this->course('AAA 101', ['year' => 1, 'semester' => 1]);
        $this->course('AAA 102', ['year' => 1, 'semester' => 2]); // فصل آخر: لا يجب أن يتأثر

        $this->postCallback(555003, 12, 'plan:bt:1:1')->assertOk();

        $this->assertSame('completed', DB::table('my_courses')->where('user_id', $user->id)->where('course_id', $a->id)->value('status'));
        $this->assertSame('completed', DB::table('my_courses')->where('user_id', $user->id)->where('course_id', $b->id)->value('status'));
        $this->assertSame(0, DB::table('my_courses')->where('user_id', $user->id)->count() - 2); // فقط الاثنان تأثرا
    }

    /**
     * نقطة الدخول الفعلية: طالب يكتب "خطتي" (لا زر) — يجب أن ترسل رسالة
     * الملخّص المعتادة (بلا تغيير) ثم رسالة إضافية بقائمة السنوات
     * التفاعلية الجديدة، بلا أي خطأ فادح بالتحويل بين الاثنتين.
     */
    public function test_typing_the_plan_command_sends_the_summary_then_the_interactive_years_menu(): void
    {
        $this->course('AAA 100');
        $this->linkedUser(555010);

        $response = $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])
            ->postJson('/api/v1/telegram/webhook', [
                'update_id' => random_int(1, 999999999),
                'message' => [
                    'chat' => ['id' => 555010],
                    'text' => 'خطتي',
                    'from' => ['first_name' => 'طالب'],
                ],
            ]);

        $response->assertOk();

        $sendMessageCalls = collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), '/sendMessage'))
            ->count();

        // رسالة الملخّص + رسالة قائمة السنوات = نداءان على الأقل.
        $this->assertGreaterThanOrEqual(2, $sendMessageCalls);
    }

    public function test_an_unlinked_chat_gets_a_toast_and_no_database_write(): void
    {
        $course = $this->course('AAA 100');

        $this->postCallback(999999, 13, "plan:s:{$course->id}:c")->assertOk();

        $this->assertSame(0, DB::table('my_courses')->count());
    }

    public function test_a_forged_elective_or_placeholder_course_id_is_rejected(): void
    {
        $user = $this->linkedUser(555004);
        $placeholder = $this->course('EEEX 35XX', ['course_type' => 'placeholder']);

        $this->postCallback(555004, 14, "plan:s:{$placeholder->id}:c")->assertOk();

        $this->assertSame(0, DB::table('my_courses')->where('user_id', $user->id)->count());
    }

    public function test_wrong_webhook_secret_is_rejected_with_403_and_no_write(): void
    {
        $course = $this->course('AAA 100');
        $user = $this->linkedUser(555005);

        $response = $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => 'wrong-secret'])
            ->postJson('/api/v1/telegram/webhook', [
                'callback_query' => [
                    'id' => 'cbq1',
                    'data' => "plan:s:{$course->id}:c",
                    'message' => ['message_id' => 15, 'chat' => ['id' => 555005]],
                ],
            ]);

        $response->assertStatus(403);
        $this->assertSame(0, DB::table('my_courses')->where('user_id', $user->id)->count());
    }
}
