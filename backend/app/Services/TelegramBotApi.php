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

    /*
     * $keyboard اختياري: مصفوفة أزرار inline بصيغة تيليجرام القياسية
     * [[['text' => '...', 'callback_data' => '...'], ...], ...] —
     * تُستخدم فقط لرسالة "القائمة الذكية" حاليًا (buildMenuKeyboard
     * بالـwebhook)، وباقي الرسائل تتجاهلها (null افتراضيًا).
     */
    public function sendMessage(int|string $chatId, string $text, ?array $keyboard = null): void
    {
        if (! $this->isConfigured()) {
            return;
        }

        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ];

        if ($keyboard !== null) {
            $payload['reply_markup'] = json_encode(['inline_keyboard' => $keyboard]);
        }

        Http::timeout(10)->post(
            "https://api.telegram.org/bot{$this->token}/sendMessage",
            $payload
        );
    }

    /*
     * لازم تُستدعى لكل ضغطة زر inline (callback_query) حتى تختفي
     * دوّامة التحميل عن الزر بواجهة تيليجرام — حتى لو ما بدنا نعرض
     * أي "toast" فعلي للطالب ($text فاضي افتراضيًا مقبول).
     */
    public function answerCallbackQuery(string $callbackQueryId, string $text = ''): void
    {
        if (! $this->isConfigured()) {
            return;
        }

        Http::timeout(10)->post(
            "https://api.telegram.org/bot{$this->token}/answerCallbackQuery",
            array_filter([
                'callback_query_id' => $callbackQueryId,
                'text' => $text !== '' ? $text : null,
            ], static fn ($v) => $v !== null)
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

    /*
     * إرسال ملف محلي كمستند (sendDocument) — يُستخدم لإرسال الكود
     * المعدَّل من "مصحّح أكواد" كملف بدل نص طويل يعمل سكرول بالمحادثة
     * (multipart upload حقيقي، لا رابط، لأنه الملف مولَّد لحظيًا ومش
     * له رابط عام أصلًا).
     */
    public function sendDocument(int|string $chatId, string $localPath, string $filename, ?string $caption = null): void
    {
        if (! $this->isConfigured() || ! is_readable($localPath)) {
            return;
        }

        $request = Http::timeout(20)->attach(
            'document',
            file_get_contents($localPath),
            $filename
        );

        $request->post(
            "https://api.telegram.org/bot{$this->token}/sendDocument",
            array_filter([
                'chat_id' => $chatId,
                'caption' => $caption,
            ], static fn ($v) => $v !== null)
        );
    }
}
