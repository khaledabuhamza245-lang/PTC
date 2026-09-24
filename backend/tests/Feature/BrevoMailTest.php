<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

/**
 * يحرس نقل Brevo وقالب الرسالة العربي.
 *
 * الاختبارات تشتغل على الناقل الحقيقي (MAIL_MAILER=brevo) مع تزييف طبقة
 * HTTP وحدها — لأن ما نريد التأكد منه هو شكل الحمولة التي تصل Brevo،
 * وتزييف Mail كاملًا يقفز فوق الناقل فلا يفحص منه شيئًا.
 */
class BrevoMailTest extends TestCase
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
        ]);
    }

    private function requestReset(string $email = 'student@ptchub.com'): void
    {
        User::factory()->create(['email' => $email]);

        $this->postJson('/api/v1/auth/forgot-password', ['email' => $email])
            ->assertOk();
    }

    public function test_the_payload_matches_what_brevo_expects(): void
    {
        Http::fake(['api.brevo.com/*' => Http::response(['messageId' => '<abc@brevo>'], 201)]);

        $this->requestReset();

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $request->url() === 'https://api.brevo.com/v3/smtp/email'
                && $request->method() === 'POST'
                && $request->header('api-key') === ['xkeysib-test-key']
                && $body['sender']['email'] === 'noreply@ptchub.test'
                && $body['to'][0]['email'] === 'student@ptchub.com'
                && $body['subject'] !== ''
                && ! empty($body['htmlContent']);
        });
    }

    public function test_a_rejected_message_throws_instead_of_passing_silently(): void
    {
        Http::fake(['api.brevo.com/*' => Http::response(['message' => 'Invalid sender'], 400)]);

        $this->expectException(TransportException::class);

        $this->withoutExceptionHandling();
        $this->requestReset();
    }

    public function test_the_arabic_template_carries_the_frontend_reset_link(): void
    {
        config(['app.frontend_url' => 'https://ptchub.test']);

        Http::fake(['api.brevo.com/*' => Http::response(['messageId' => '<abc@brevo>'], 201)]);

        $this->requestReset();

        Http::assertSent(function ($request) {
            $html = $request->data()['htmlContent'];

            return str_contains($html, 'dir="rtl"')
                && str_contains($html, 'إعادة تعيين كلمة السر')
                && str_contains($html, 'https://ptchub.test/login.html?token=')
                // صلاحية الرابط مذكورة صراحةً — الطالب الذي يفتحه متأخرًا
                // يفهم لماذا رُفض بدل أن يظنّ الموقع عطب.
                && str_contains($html, 'الرابط يصلح 60 دقيقة');
        });
    }
}
