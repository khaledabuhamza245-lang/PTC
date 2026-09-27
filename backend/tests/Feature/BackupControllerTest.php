<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * يثبّت App\Http\Controllers\Api\V1\BackupController (خطوة ١٠٨، نسخ
 * احتياطي دوري لقاعدة البيانات يُرسَل عبر بوت تيليجرام — راجع
 * claude/step108_automated_db_backup_via_telegram.md بمشروع التوثيق):
 * بلا رمز صحيح = 403، بلا إعداد چات/بوت = رد "not-configured" بلا أي
 * محاولة اتصال، وبكل الإعدادات صحيحة = فعليًا يستدعي sendDocument
 * ببيانات فعلية (مزيَّفة عبر Http::fake() — لا اتصال إنترنت حقيقي أثناء
 * الاختبارات).
 */
class BackupControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        putenv('BACKUP_CRON_TOKEN');
        putenv('BACKUP_TELEGRAM_CHAT_ID');

        parent::tearDown();
    }

    public function test_wrong_token_is_rejected(): void
    {
        putenv('BACKUP_CRON_TOKEN=correct-token');

        $this->getJson('/api/v1/backup/run?token=wrong')->assertForbidden();
    }

    public function test_missing_token_is_rejected(): void
    {
        putenv('BACKUP_CRON_TOKEN=correct-token');

        $this->getJson('/api/v1/backup/run')->assertForbidden();
    }

    public function test_reports_not_configured_when_chat_id_or_bot_token_missing(): void
    {
        putenv('BACKUP_CRON_TOKEN=correct-token');
        putenv('BACKUP_TELEGRAM_CHAT_ID');
        config(['services.telegram.bot_token' => '']);

        $this->getJson('/api/v1/backup/run?token=correct-token')
            ->assertOk()
            ->assertJson(['status' => 'not-configured']);
    }

    public function test_dumps_database_and_sends_it_via_telegram_when_fully_configured(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        putenv('BACKUP_CRON_TOKEN=correct-token');
        putenv('BACKUP_TELEGRAM_CHAT_ID=999999');
        config(['services.telegram.bot_token' => 'test-token']);

        $response = $this->getJson('/api/v1/backup/run?token=correct-token')
            ->assertOk()
            ->assertJson(['status' => 'ok']);

        $this->assertIsNumeric($response->json('size_mb'));

        // multipart/form-data (attach()) لا يُفكَّك تلقائيًا كمصفوفة عبر
        // Illuminate\Http\Client\Request — نتحقق من الجسم الخام مباشرة.
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/sendDocument')
                && str_contains($request->body(), '999999');
        });
    }
}
