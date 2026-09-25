<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TelegramLink;
use App\Services\PlanCalculator;
use App\Services\TelegramBotApi;
use Illuminate\Http\Request;

/*
 * نقطة الاستقبال الوحيدة من تيليجرام (Webhook) — المرحلة الأولى (ربط
 * الحساب) + أول أمر حقيقي (خطتي).
 *
 * تيليجرام بيبعت POST لهاد الرابط لكل رسالة توصل للبوت. الحماية هون
 * بـ"سرّ" خاص (X-Telegram-Bot-Api-Secret-Token) نحدده إحنا وقت تفعيل
 * الـwebhook — مش بجلسة تسجيل دخول عادية، لأنه المستدعي هون سيرفرات
 * تيليجرام نفسها لا متصفح طالب (نفس فلسفة /ai/process-pending الحالية
 * بالموقع، حماية برمز سرّي بالرابط/الترويسة لا Sanctum).
 *
 * أمر "خطتي" يستدعي PlanCalculator::summarize() مباشرة (نفس الخدمة
 * يلي تستخدمها صفحة "صفحتي الشخصية" بالضبط عبر PlanController) — عمدًا
 * بلا أي حساب جديد أو منفصل هون، حتى ما يصير عنا مصدرين مختلفين
 * لنفس الرقم يوم ما يتغيّر منطق الحساب بمكان ونُنسى الآخر. لهاد
 * السبب بالضبط ما بنينا أمر "معدلي" بعد — حاسبة المعدل التراكمي
 * بالموقع (gpa.html) حسابها بالكامل بالمتصفح (JS) لا بالخادم، فمافي
 * رقم جاهز نقرأه من قاعدة البيانات بأمان بدون ما نكرر نفس المنطق
 * هون — قرار مؤجَّل لمرحلة لاحقة يستاهل نقاشه لحاله.
 */
class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request, TelegramBotApi $bot, PlanCalculator $planCalculator)
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
                "تم ربط حسابك بنجاح يا {$studentName} ✅\n".
                "جرّب تكتب \"خطتي\" هلق تشوف تقدّمك نحو التخرّج، أو \"مساعدة\" تشوف كل الأوامر المتاحة."
            );

            return response()->json(['ok' => true]);
        }

        /*
         * أي أمر تاني يحتاج حساب مربوط فعليًا — نجيبه بحثًا بمعرّف
         * المحادثة، لا الاعتماد على أي شيء أرسله المستخدم نفسه.
         */
        $link = TelegramLink::query()
            ->whereNotNull('telegram_chat_id')
            ->where('telegram_chat_id', $chatId)
            ->first();

        if (! $link || ! $link->user) {
            $bot->sendMessage(
                $chatId,
                'لسا ما ربطت حسابك 🙂 روح لصفحة "إعدادات الحساب" بالموقع واضغط "اربط حسابي بتيليجرام".'
            );

            return response()->json(['ok' => true]);
        }

        $normalized = trim($text, "/ \t\n");

        if (in_array($normalized, ['خطتي', 'plan'], true)) {
            $this->replyWithPlanSummary($bot, $chatId, $link->user, $planCalculator);

            return response()->json(['ok' => true]);
        }

        if (in_array($normalized, ['مساعدة', 'help', 'أوامر'], true)) {
            $bot->sendMessage(
                $chatId,
                "الأوامر المتاحة حاليًا (نسخة تجريبية، رح تكبر تدريجيًا):\n\n".
                "📊 خطتي — تقدّمك نحو التخرّج (الساعات المعتمدة).\n".
                "❓ مساعدة — هاي القائمة."
            );

            return response()->json(['ok' => true]);
        }

        $bot->sendMessage(
            $chatId,
            "ما فهمت هالأمر 🙂 اكتب \"مساعدة\" لتشوف الأوامر المتاحة حاليًا."
        );

        return response()->json(['ok' => true]);
    }

    private function replyWithPlanSummary(
        TelegramBotApi $bot,
        int|string $chatId,
        \App\Models\User $user,
        PlanCalculator $planCalculator
    ): void {
        $summary = $planCalculator->summarize($user);

        $completed = (int) $summary['completed_hours'];
        $total = (int) $summary['total_credit_hours'];
        $remaining = (int) $summary['remaining_hours'];
        $percent = (int) $summary['percent'];
        $registered = (int) ($summary['counts']['registered'] ?? 0);

        $bot->sendMessage(
            $chatId,
            "📊 <b>تقدّمك نحو التخرّج</b>\n\n".
            "✅ أنجزت {$completed} من {$total} ساعة معتمدة ({$percent}٪)\n".
            "📚 متبقّي: {$remaining} ساعة\n".
            "🟢 مسجّل حاليًا: {$registered} مساق"
        );
    }
}
