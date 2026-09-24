<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * يحرس نموذج «تواصل معنا».
 *
 * الاختبارات تشتغل على ناقل Brevo الحقيقي مع تزييف طبقة HTTP وحدها، كما
 * في BrevoMailTest وللسبب نفسه: ما نريد التأكد منه هو شكل الحمولة التي
 * تصل Brevo، وMail::fake يقفز فوق الناقل فلا يفحص منه شيئًا — وهنا
 * تحديدًا يقع أخطر انحدار ممكن: أن يخرج replyTo من الحمولة فتذهب ردود
 * صاحب الموقع إلى صاحب الموقع.
 */
class ContactMessageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'mail.default' => 'brevo',
            'mail.mailers.brevo.key' => 'xkeysib-test-key',
            'mail.from.address' => 'noreply@ptchub.test',
            'mail.from.name' => 'PTC Hub',
            'mail.contact.inbox' => 'inbox@ptchub.test',
        ]);

        RateLimiter::clear('contact-ip:127.0.0.1');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'أحمد محمد',
            'email' => 'student@ptchub.com',
            'subject' => 'سؤال عن المساقات الاختيارية',
            'message' => 'السلام عليكم، كيف أعرف المساقات الاختيارية المتاحة لي هذا الفصل؟',
        ], $overrides);
    }

    private function fakeBrevo(int $status = 201): void
    {
        Http::fake([
            'api.brevo.com/*' => Http::response(['messageId' => '<abc@brevo>'], $status),
        ]);
    }

    public function test_a_visitor_can_send_a_message_without_logging_in(): void
    {
        $this->fakeBrevo();

        $this->postJson('/api/v1/contact', $this->payload())
            ->assertOk()
            ->assertJson(['message' => 'وصلتنا رسالتك.']);

        Http::assertSentCount(1);
    }

    public function test_the_message_goes_to_the_site_inbox_and_replies_go_to_the_student(): void
    {
        $this->fakeBrevo();

        $this->postJson('/api/v1/contact', $this->payload())->assertOk();

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $request->url() === 'https://api.brevo.com/v3/smtp/email'
                // المُرسِل هو الموقع لا الطالب: Brevo لا تقبل في sender
                // إلا عنوانًا متحقَّقًا في الحساب.
                && $body['sender']['email'] === 'noreply@ptchub.test'
                && $body['to'][0]['email'] === 'inbox@ptchub.test'
                && $body['replyTo']['email'] === 'student@ptchub.com'
                && $body['replyTo']['name'] === 'أحمد محمد'
                && str_contains($body['subject'], 'أحمد محمد')
                && str_contains($body['subject'], 'سؤال عن المساقات الاختيارية');
        });
    }

    public function test_the_inbox_falls_back_to_the_sender_address_when_unset(): void
    {
        config(['mail.contact.inbox' => null]);

        $this->fakeBrevo();

        $this->postJson('/api/v1/contact', $this->payload())->assertOk();

        Http::assertSent(
            fn ($request) => $request->data()['to'][0]['email'] === 'noreply@ptchub.test',
        );
    }

    public function test_the_body_carries_the_student_details_in_arabic(): void
    {
        $this->fakeBrevo();

        $this->postJson('/api/v1/contact', $this->payload())->assertOk();

        Http::assertSent(function ($request) {
            $html = $request->data()['htmlContent'];

            return str_contains($html, 'dir="rtl"')
                && str_contains($html, 'استفسار جديد من الموقع')
                && str_contains($html, 'أحمد محمد')
                && str_contains($html, 'mailto:student@ptchub.com')
                && str_contains($html, 'المساقات الاختيارية المتاحة لي');
        });
    }

    /**
     * الأسطر الجديدة تصل مقروءة.
     *
     * بلا nl2br تُطوى الرسالة كلها في فقرة واحدة، فقائمةٌ كتبها الطالب
     * في أسطر تصل جملةً واحدة ملتصقة.
     */
    public function test_line_breaks_survive_into_the_email(): void
    {
        $this->fakeBrevo();

        $this->postJson('/api/v1/contact', $this->payload([
            'message' => "السطر الأول من الرسالة\nالسطر الثاني من الرسالة",
        ]))->assertOk();

        Http::assertSent(
            fn ($request) => str_contains($request->data()['htmlContent'], '<br'),
        );
    }

    /**
     * متن الرسالة مصدره الإنترنت المفتوح، ووجهته صندوق صاحب الموقع.
     * فوسمٌ يمرّ بلا تهريب يجعل النموذج قناة حقن HTML إلى بريده.
     */
    public function test_html_inside_the_message_is_escaped_not_rendered(): void
    {
        $this->fakeBrevo();

        $this->postJson('/api/v1/contact', $this->payload([
            'message' => 'انظر هنا <script>alert(1)</script> وشكرًا لكم جميعًا',
        ]))->assertOk();

        Http::assertSent(function ($request) {
            $html = $request->data()['htmlContent'];

            return ! str_contains($html, '<script>alert(1)</script>')
                && str_contains($html, '&lt;script&gt;');
        });
    }

    public function test_the_subject_line_cannot_smuggle_html_either(): void
    {
        $this->fakeBrevo();

        $this->postJson('/api/v1/contact', $this->payload([
            'name' => '<b>مخترق</b>',
            'subject' => '<img src=x onerror=alert(1)>',
        ]))->assertOk();

        Http::assertSent(function ($request) {
            $html = $request->data()['htmlContent'];

            return ! str_contains($html, '<img src=x')
                && ! str_contains($html, '<b>مخترق</b>');
        });
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidPayloads(): array
    {
        return [
            'بلا اسم' => [['name' => ''], 'name'],
            'اسم أقصر من حرفين' => [['name' => 'أ'], 'name'],
            'بلا بريد' => [['email' => ''], 'email'],
            'بريد غير صحيح' => [['email' => 'not-an-email'], 'email'],
            'بلا موضوع' => [['subject' => ''], 'subject'],
            'بلا رسالة' => [['message' => ''], 'message'],
            'رسالة أقصر من عشرة أحرف' => [['message' => 'قصيرة'], 'message'],
            'رسالة أطول من ألفي حرف' => [['message' => str_repeat('ا', 2001)], 'message'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidPayloads')]
    public function test_it_rejects_bad_input(array $overrides, string $field): void
    {
        $this->fakeBrevo();

        $this->postJson('/api/v1/contact', $this->payload($overrides))
            ->assertStatus(422)
            ->assertJsonValidationErrors($field);

        Http::assertNothingSent();
    }

    /**
     * الفخّ يُبتلع لا يُرفض: ردّ 422 يعلّم البوت أن الحقل فخّ فيتجنّبه في
     * المحاولة التالية، والنجاح الكاذب يجعله ينصرف راضيًا.
     */
    public function test_a_filled_honeypot_looks_like_success_but_sends_nothing(): void
    {
        $this->fakeBrevo();

        $this->postJson('/api/v1/contact', $this->payload(['website' => 'http://spam.example']))
            ->assertOk()
            ->assertJson(['message' => 'وصلتنا رسالتك.']);

        Http::assertNothingSent();
    }

    public function test_a_visitor_is_throttled_after_the_hourly_limit(): void
    {
        config(['api.rate_limit.contact_guest' => 2]);

        $this->fakeBrevo();

        $this->postJson('/api/v1/contact', $this->payload())->assertOk();
        $this->postJson('/api/v1/contact', $this->payload())->assertOk();
        $this->postJson('/api/v1/contact', $this->payload())->assertStatus(429);

        Http::assertSentCount(2);
    }

    /**
     * حدّ المصادَق منفصل عن حدّ الزائر ومفتاحه معرّفه لا عنوانه: شبكة
     * الجامعة تضع عشرات الطلاب خلف IP واحد، فحدٌّ على العنوان وحده يجعل
     * طالبًا واحدًا يستهلك حصة قاعة كاملة.
     */
    public function test_a_signed_in_student_gets_their_own_bucket(): void
    {
        config(['api.rate_limit.contact_guest' => 1]);

        $this->fakeBrevo();

        // الزائر يستهلك حصته من هذا العنوان أولًا.
        $this->postJson('/api/v1/contact', $this->payload())->assertOk();
        $this->postJson('/api/v1/contact', $this->payload())->assertStatus(429);

        $this->actingAs(User::factory()->create())
            ->postJson('/api/v1/contact', $this->payload())
            ->assertOk();
    }

    /**
     * سقوط Brevo يظهر ولا يُبتلع: «وصلتنا رسالتك» ثم لا تصل رسالة يترك
     * الطالب ينتظر ردًّا لن يأتي.
     */
    public function test_a_failed_send_reports_the_failure_instead_of_faking_success(): void
    {
        $this->fakeBrevo(400);

        $this->postJson('/api/v1/contact', $this->payload())
            ->assertStatus(502)
            ->assertJsonPath('message', 'تعذّر إرسال رسالتك الآن. حاول بعد قليل.');
    }
}
