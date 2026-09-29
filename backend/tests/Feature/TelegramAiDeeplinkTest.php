<?php

namespace Tests\Feature;

use App\Models\AiQuestion;
use App\Models\Course;
use App\Models\CourseFile;
use App\Models\TelegramAiDeeplink;
use App\Models\TelegramLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * خطوة ١١٣: تحويل زرّي "لخّصلي"/"بطاقات مراجعة" من شات الموقع لشات
 * بوت تيليجرام. يغطي هذا الاختبار المسار الكامل: توليد رابط تيليجرام
 * من نقطة API الموقع، ثم استهلاكه من داخل /api/v1/telegram/webhook —
 * لكلتا الحالتين (حساب مربوط أصلًا، وحساب غير مربوط مع استئناف تلقائي
 * بعد الربط) — بلا أي تكرار لمنطق Gemini نفسه (يُختبر بشكل غير مباشر
 * عبر التأكد أن الرد يصل، لا عبر محاكاة Gemini هنا).
 */
class TelegramAiDeeplinkTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.telegram.bot_token' => 'test-bot-token',
            'services.telegram.bot_username' => 'PTCHubBot',
            'services.telegram.webhook_secret' => self::SECRET,
        ]);

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);
    }

    private function makeCourseFile(): CourseFile
    {
        $course = Course::create([
            'key' => 'c_QA113', 'code' => 'QA113', 'name_ar' => 'مادة اختبار',
            'name_en' => 'QA Course', 'year' => 1, 'semester' => 1, 'is_active' => true,
        ]);

        return CourseFile::create([
            'course_id' => $course->id,
            'title' => 'ملف اختبار',
            'kind' => 'file',
            'storage_disk' => 'gdrive',
            'storage_path' => 'fake-path',
            'is_published' => true,
            'visibility' => 'public',
            'status' => 'ready',
            'ai_summarizable' => true,
        ]);
    }

    private function postCallbackOrText(int $chatId, array $message): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])
            ->postJson('/api/v1/telegram/webhook', [
                'update_id' => random_int(1, 999999999),
                'message' => $message,
            ]);
    }

    private function postStart(int $chatId, string $param): \Illuminate\Testing\TestResponse
    {
        return $this->postCallbackOrText($chatId, [
            'chat' => ['id' => $chatId],
            'text' => '/start '.$param,
            'from' => ['first_name' => 'طالب'],
        ]);
    }

    public function test_generating_the_telegram_link_requires_a_summarizable_published_file(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $courseFile = $this->makeCourseFile();

        Sanctum::actingAs($student);

        $response = $this->postJson("/api/v1/ai/course-files/{$courseFile->id}/telegram-link", ['mode' => 'summary']);

        $response->assertOk();
        $url = $response->json('data.url');
        $this->assertStringStartsWith('https://t.me/PTCHubBot?start=ai_', $url);

        $token = str_replace('https://t.me/PTCHubBot?start=ai_', '', $url);
        $this->assertDatabaseHas('telegram_ai_deeplinks', [
            'token' => $token,
            'user_id' => $student->id,
            'course_file_id' => $courseFile->id,
            'mode' => 'summary',
        ]);
    }

    public function test_generating_the_link_is_rejected_for_a_non_summarizable_file(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $courseFile = $this->makeCourseFile();
        $courseFile->update(['ai_summarizable' => false]);

        Sanctum::actingAs($student);

        $this->postJson("/api/v1/ai/course-files/{$courseFile->id}/telegram-link", ['mode' => 'summary'])
            ->assertNotFound();
    }

    public function test_opening_the_link_when_already_linked_runs_the_summary_immediately(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $courseFile = $this->makeCourseFile();

        TelegramLink::create([
            'user_id' => $student->id,
            'telegram_chat_id' => 900001,
            'telegram_first_name' => 'طالب',
            'linked_at' => now(),
        ]);

        $deeplink = TelegramAiDeeplink::create([
            'token' => 'tokenabc123',
            'user_id' => $student->id,
            'course_file_id' => $courseFile->id,
            'mode' => 'summary',
            'expires_at' => now()->addMinutes(10),
        ]);

        $this->postStart(900001, 'ai_tokenabc123')->assertOk();

        // التوكن استُهلك (استخدام واحد فقط)
        $this->assertDatabaseMissing('telegram_ai_deeplinks', ['id' => $deeplink->id]);

        // سؤال AiQuestion انُشئ فعليًا لهذا الملف عبر نفس منطق الموقع (performSummarize)
        $this->assertDatabaseHas('ai_questions', [
            'referenced_course_file_id' => $courseFile->id,
            'user_id' => $student->id,
        ]);

        // البوت ردّ برسالة ما (إما جاهز فورًا أو "جاري التحضير") — بأي الحالتين أرسل شيئًا
        Http::assertSent(fn ($request) => str_contains($request->url(), '/sendMessage'));
    }

    public function test_opening_the_link_when_not_linked_yet_asks_to_link_then_auto_resumes(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $courseFile = $this->makeCourseFile();

        TelegramAiDeeplink::create([
            'token' => 'tokenxyz789',
            'user_id' => $student->id,
            'course_file_id' => $courseFile->id,
            'mode' => 'flashcards',
            'expires_at' => now()->addMinutes(10),
        ]);

        $this->postStart(900002, 'ai_tokenxyz789')->assertOk();

        // ما في أي AiQuestion بعد — الحساب مش مربوط بعد
        $this->assertDatabaseMissing('ai_questions', ['referenced_course_file_id' => $courseFile->id]);

        $link = TelegramLink::where('user_id', $student->id)->first();
        $this->assertNotNull($link);
        $this->assertNull($link->telegram_chat_id);
        $this->assertSame('ai_deeplink', $link->pending_action['action'] ?? null);
        $this->assertSame($courseFile->id, $link->pending_action['course_file_id'] ?? null);
        $this->assertSame('flashcards', $link->pending_action['mode'] ?? null);

        $sentSoFar = collect(Http::recorded())->count();

        // الآن الطالب يكمل الربط العادي (نفس مسار TelegramLinkController::store)
        $link->update(['link_token' => 'normal-link-token', 'token_expires_at' => now()->addMinutes(10)]);

        $this->postStart(900002, 'normal-link-token')->assertOk();

        $link->refresh();
        $this->assertNotNull($link->telegram_chat_id);
        $this->assertNull($link->pending_action);

        // استؤنف التلخيص تلقائيًا بعد الربط — سؤال جديد انُشئ الآن
        $this->assertDatabaseHas('ai_questions', [
            'referenced_course_file_id' => $courseFile->id,
            'user_id' => $student->id,
        ]);

        $this->assertGreaterThan($sentSoFar, collect(Http::recorded())->count());
    }

    public function test_an_expired_or_unknown_token_gets_a_friendly_message_and_no_question(): void
    {
        $this->postStart(900003, 'ai_does-not-exist')->assertOk();

        $this->assertDatabaseCount('ai_questions', 0);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/sendMessage')
            && str_contains($request->data()['text'] ?? '', 'منتهي أو غير صالح'));
    }
}
