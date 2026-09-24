<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AuthFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_accepts_email_with_different_letter_case(): void
    {
        User::factory()->create([
            'email' => 'admin@ptchub.com',
            'password' => 'Admin@123456',
            'role' => 'admin',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'ADMIN@PTCHUB.COM',
            'password' => 'Admin@123456',
        ])->assertOk()
            ->assertJsonPath('data.user.email', 'admin@ptchub.com')
            ->assertJsonStructure(['data' => ['token']]);
    }

    public function test_forgot_password_normalizes_email_and_sends_notification(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => 'student@ptchub.com']);

        $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'STUDENT@PTCHUB.COM',
        ])->assertOk();

        /*
         * الصنف صنفُنا لا صنف لارافيل، و assertSentTo يطابق الاسم بدقّة
         * لا بالوراثة — فهذا السطر وحده يحرس أن القالب العربي هو
         * المستعمَل. لو رُدَّ الصنف الافتراضي يومًا سقط الاختبار.
         */
        Notification::assertSentTo($user, ResetPasswordNotification::class);
    }

    /**
     * رابط أُبطل أو انتهت صلاحيته يُقال عنه ذلك بالعربية.
     *
     * والشرط الثاني هو الحارس الحقيقي: رسالة لارافيل الإنجليزية
     * «This password reset token is invalid» تحوي لفظ password، وكانت
     * الواجهة تخمّن معنى الرسالة بالكلمات المفتاحية فتعرضها «كلمة السر
     * قصيرة». فأطال الطالب كلمة سرّه مرارًا بلا فائدة والعلّة في الرابط.
     * حذف lang/ar/passwords.php يُعيد الفخّ، وهذا السطر يمنعه.
     */
    public function test_an_invalid_reset_token_is_reported_in_arabic_without_the_word_password(): void
    {
        User::factory()->create(['email' => 'student@ptchub.com']);

        $response = $this->postJson('/api/v1/auth/reset-password', [
            'token' => 'a-token-that-was-never-issued',
            'email' => 'student@ptchub.com',
            'password' => 'LongEnough!2026',
            'password_confirmation' => 'LongEnough!2026',
        ])->assertStatus(422);

        $message = $response->json('message');

        $this->assertStringContainsString('رابط', $message);
        $this->assertStringNotContainsStringIgnoringCase('password', $message);
    }
}
