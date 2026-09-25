<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TelegramLink;
use App\Services\TelegramBotApi;
use Illuminate\Http\Request;

/*
 * نقطة الاستقبال الوحيدة من تيليجرام (Webhook) — المرحلة الأولى.
 *
 * تيليجرام بيبعت POST لهاد الرابط لكل رسالة توصل للبوت. الحماية هون
 * بـ"سرّ" خاص (X-Telegram-Bot-Api-Secret-Token) نحدده إحنا وقت تفعيل
 * الـwebhook — مش بجلسة تسجيل دخول عادية، لأنه المستدعي هون سيرفرات
 * تيليجرام نفسها لا متصفح طالب (نفس فلسفة /ai/process-pending الحالية
 * بالموقع، حماية برمز سرّي بالرابط/الترويسة لا Sanctum).
 *
 * المرحلة الأولى تدعم أمر واحد بس: /start <token> لربط الحساب. أي
 * رسالة تانية بترجع ردّ بسيط لحد ما نبني المراحل الجاية.
 */
class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request, TelegramBotApi $bot)
    {
        $expectedSecret = (string) config('services.telegram.webhook_secret', '');

        if (
            $expectedSecret === ''
            || $request->header('X-Telegram-Bot-Api-Secret-Token') !== $expectedSecret
        ) {
            return response()->json(['ok' => false], 403);
        }

        $message = $request->input('message');
        $chatId = $message['chat']['id'] ?? null;
        $text = trim((string) ($message['text'] ?? ''));
        $telegramFirstName = (string) ($message['from']['first_name'] ?? '');

        // ردّ فارغ لأي تحديث ما فيه رسالة نصية (صور، ملصقات...) —
        // 200 دايمًا حتى ما تعيد تيليجرام إرسال نفس التحديث.
        if (! $chatId || $text === '') {
            return response()->json(['ok' => true]);
        }

        if (str_starts_with($text, '/start')) {
            $token = trim(substr($text, strlen('/start')));

            if ($token === '') {
                $bot->sendMessage(
                    $chatId,
                    'أهلًا 👋 لازم تربط حسابك أول شي من صفحة "حسابي" بموقع دليل طالب هندسة أنظمة الحاسوب.'
                );

                return response()->json(['ok' => true]);
            }

            $link = TelegramLink::query()
                ->where('link_token', $token)
                ->where('token_expires_at', '>', now())
                ->first();

            if (! $link) {
                $bot->sendMessage(
                    $chatId,
                    'هذا الرابط منتهي أو غير صالح. ارجع لصفحة "حسابي" بالموقع واطلب رابط ربط جديد.'
                );

                return response()->json(['ok' => true]);
            }

            $link->update([
                'telegram_chat_id' => $chatId,
                'telegram_first_name' => $telegramFirstName,
                'linked_at' => now(),
                'link_token' => null,
                'token_expires_at' => null,
            ]);

            $studentName = trim((string) ($link->user?->first_name ?? ''));

            $bot->sendMessage(
                $chatId,
                "تم ربط حسابك بنجاح يا {$studentName} ✅\nهاد أول نسخة تجريبية من البوت — قريبًا رح تلاقي هون خطتك ومعدلك ومحتوى مساقاتك."
            );

            return response()->json(['ok' => true]);
        }

        $bot->sendMessage(
            $chatId,
            'وصلتني رسالتك 👋 — البوت لسا بمرحلته التجريبية الأولى، وبيدعم بس ربط الحساب حاليًا.'
        );

        return response()->json(['ok' => true]);
    }
}
