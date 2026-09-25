<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/*
 * أصغر غلاف ممكن حول Telegram Bot API — استدعاء HTTP عادي، بنفس
 * أسلوب استخدام Http::  الموجود أصلًا بـ TelegramUploadController.
 * لا مكتبة خارجية (SDK) — API تيليجرام نفسه بسيط بما يكفي.
 */
class TelegramBotApi
{
    private string $token;

    public function __construct()
    {
        $this->token = (string) config('services.telegram.bot_token', '');
    }

    public function isConfigured(): bool
    {
        return $this->token !== '';
    }

    /*
     * كل رسائل البوت بـparse_mode=HTML — أي نص ديناميكي (عنوان مادة،
     * عنوان محتوى، ملخّص من الذكاء الاصطناعي...) لازم يمر من هون قبل
     * ما يُحقن بنص الرسالة، وإلا أي & أو < أو > بداخله ممكن يكسر
     * تفسير تيليجرام للرسالة كاملة أو يظهرها مشوَّهة.
     */
    public static function escapeHtml(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }

    public function sendMessage(int|string $chatId, string $text): void
    {
        if (! $this->isConfigured()) {
            return;
        }

        Http::timeout(10)->post(
            "https://api.telegram.org/bot{$this->token}/sendMessage",
            [
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
            ]
        );
    }

    /*
     * تحميل ملف أرسله المستخدم للبوت (صورة/PDF) لملف محلي مؤقت.
     * خطوتين بالضبط كما توثّق Telegram نفسها: getFile يرجّع مسار
     * نسبي، وبعدين رابط تحميل منفصل بنفس التوكن. يرجّع null بهدوء
     * عند أي فشل (حجم غير معقول، ملف منتهي الصلاحية...) — الاستدعاء
     * هو من يقرر الرسالة المناسبة للطالب.
     */
    public function downloadFile(string $fileId): ?string
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $meta = Http::timeout(15)->get(
            "https://api.telegram.org/bot{$this->token}/getFile",
            ['file_id' => $fileId]
        );

        $remotePath = $meta->successful() ? $meta->json('result.file_path') : null;

        if (! $remotePath) {
            return null;
        }

        $download = Http::timeout(30)->get(
            "https://api.telegram.org/file/bot{$this->token}/{$remotePath}"
        );

        if (! $download->successful()) {
            return null;
        }

        $localPath = tempnam(sys_get_temp_dir(), 'tgbot_') . '_' . basename($remotePath);
        file_put_contents($localPath, $download->body());

        return $localPath;
    }
}
