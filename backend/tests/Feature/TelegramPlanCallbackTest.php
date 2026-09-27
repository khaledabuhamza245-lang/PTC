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

    /**
     * قسم المساقات الاختيارية (خطوة ١٠٢): مادة مُختارة أصلًا تظهر بحالتها
     * الحالية، وزر "اختيار مادة جديدة" يظهر فقط إن وُجدت خانة مفتوحة.
     */
    public function test_electives_menu_lists_chosen_electives_and_shows_pick_button_only_when_slot_open(): void
    {
        $term = \App\Models\Term::create([
            'code' => 't1', 'label' => 'الفصل الأول', 'academic_year' => '2025-2026', 'semester' => 1,
        ]);
        $user = $this->linkedUser(555020);
        $user->update(['year' => 1, 'current_term_id' => $term->id]);

        $chosen = $this->course('ELEC 100', ['course_type' => 'elective']);
        $user->myCourses()->attach($chosen->id, ['status' => 'registered']);

        $this->postCallback(555020, 20, 'plan:e')->assertOk();

        // لا توجد خانة مفتوحة (لا يوجد placeholder لسنة1/فصل1) فزر
        // الاختيار الجديد يجب ألا يظهر ضمن لوحة الأزرار المُرسلة، بينما
        // المادة المُختارة أصلًا تظهر كزر قابل للضغط.
        $editCall = collect(Http::recorded())
            ->first(fn ($pair) => str_contains($pair[0]->url(), 'editMessageText'));

        $this->assertNotNull($editCall);
        $keyboard = json_decode($editCall[0]->data()['reply_markup'] ?? '{}', true);
        $flatCallbacks = collect($keyboard['inline_keyboard'] ?? [])->flatten(1)->pluck('callback_data');

        $this->assertTrue($flatCallbacks->contains("plan:c:{$chosen->id}"));
        $this->assertFalse($flatCallbacks->contains('plan:ea'));
    }

    /** الآن نضيف placeholder مطابقًا فيظهر زر الاختيار الجديد. */
    public function test_electives_menu_shows_pick_button_when_a_matching_placeholder_exists(): void
    {
        $term = \App\Models\Term::create([
            'code' => 't1', 'label' => 'الفصل الأول', 'academic_year' => '2025-2026', 'semester' => 1,
        ]);
        $user = $this->linkedUser(555021);
        $user->update(['year' => 1, 'current_term_id' => $term->id]);

        $this->course('PH 100', ['course_type' => 'placeholder', 'year' => 1, 'semester' => 1]);

        $this->postCallback(555021, 21, 'plan:e')->assertOk();

        $editCall = collect(Http::recorded())
            ->first(fn ($pair) => str_contains($pair[0]->url(), 'editMessageText'));

        $keyboard = json_decode($editCall[0]->data()['reply_markup'] ?? '{}', true);
        $flatCallbacks = collect($keyboard['inline_keyboard'] ?? [])->flatten(1)->pluck('callback_data');

        $this->assertTrue($flatCallbacks->contains('plan:ea'));
    }

    /**
     * اختيار مادة اختيارية متاحة عبر plan:c ثم plan:s فعليًا يُلحقها
     * ويضبط حالتها — نفس مسار ensureStatus()←attach() الذي يتحقق من
     * الخانة المفتوحة من طرف الخادم بغضّ النظر عمّا أظهرته الواجهة.
     */
    public function test_picking_an_available_elective_then_setting_its_status_attaches_it(): void
    {
        $term = \App\Models\Term::create([
            'code' => 't1', 'label' => 'الفصل الأول', 'academic_year' => '2025-2026', 'semester' => 1,
        ]);
        $user = $this->linkedUser(555022);
        $user->update(['year' => 1, 'current_term_id' => $term->id]);

        $this->course('PH 100', ['course_type' => 'placeholder', 'year' => 1, 'semester' => 1]);
        $elective = $this->course('ELEC 200', ['course_type' => 'elective']);

        $this->postCallback(555022, 22, "plan:s:{$elective->id}:r")->assertOk();

        $row = DB::table('my_courses')->where('user_id', $user->id)->where('course_id', $elective->id)->first();

        $this->assertNotNull($row);
        $this->assertSame('registered', $row->status);
    }

    /**
     * بلا خانة مفتوحة، محاولة اختيار مادة اختيارية جديدة تُرفض بلا أي
     * كتابة بقاعدة البيانات — attach() يتحقق من الشرط دائمًا، بغضّ
     * النظر عن الزر الذي وصل منه الطلب.
     */
    public function test_setting_status_for_an_elective_with_no_open_slot_is_rejected_with_no_write(): void
    {
        $user = $this->linkedUser(555023);
        $user->update(['year' => 1]);

        $elective = $this->course('ELEC 300', ['course_type' => 'elective']);

        $this->postCallback(555023, 23, "plan:s:{$elective->id}:r")->assertOk();

        $this->assertSame(0, DB::table('my_courses')->where('user_id', $user->id)->count());
    }

    /** مادة اختيارية مُختارة أصلًا تُفتح عبر plan:c بلا أي مانع. */
    public function test_opening_an_already_chosen_elective_via_c_shows_its_edit_view(): void
    {
        $user = $this->linkedUser(555024);
        $elective = $this->course('ELEC 400', ['course_type' => 'elective']);
        $user->myCourses()->attach($elective->id, ['status' => 'completed']);

        $this->postCallback(555024, 24, "plan:c:{$elective->id}")->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'editMessageText'));
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
