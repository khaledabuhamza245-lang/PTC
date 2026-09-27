<?php

namespace App\Jobs;

use App\Services\TelegramBotApi;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/*
 * إرسال رسالة تيليجرام واحدة كمهمة طابور مستقلة — بدل إرسال كل رسائل بث
 * جماعي (محتوى جديد، سؤال "مساعدة الطلاب"، تذكير محاضرة...) بحلقة
 * متزامنة (foreach + sendMessage مباشر) تُعلّق الطلب الأصلي (زر "حفظ"
 * بلوحة الطاقم، أو إرسال سؤال الطالب) لحد ما تخلص إرسال كل الرسائل
 * واحدة واحدة.
 *
 * مع QUEUE_CONNECTION=sync (الوضع الحالي بالاستضافة، راجع .env) هالمهمة
 * تُنفَّذ فورًا بنفس الطلب — بلا أي تغيير بالسلوك الحالي، صفر خطر. بمجرد
 * ما يتحول QUEUE_CONNECTION=database (خطوة لاحقة تحتاج تعديل .env يدويًا
 * + تفعيل مسار /telegram/process-queue الجديد بخدمة الـping الخارجية)،
 * هالمهام بتتخزن بجدول jobs وتُعالَج بالخلفية دفعة دفعة — فيتحرر الطلب
 * الأصلي فورًا بدل ما ينتظر عشرات نداءات HTTP الخارجية لتيليجرام.
 *
 * tries=2 (محاولتين): فشل اتصال عابر (Timeout، انقطاع شبكة مؤقت لحظة
 * الإرسال) بيستاهل فرصة ثانية تلقائية بعد فترة قصيرة (backoff) بدل ما
 * تُفقد الرسالة نهائيًا من أول عثرة. ما في try/catch داخلي هون عمدًا —
 * أي استثناء بيهرب لآلية إعادة المحاولة القياسية بلارافيل، وبعد فشل
 * المحاولتين (حالات دائمة فعليًا: حظر البوت، رقم محادثة محذوف...) لارافيل
 * بينقل المهمة تلقائيًا لجدول failed_jobs — فهذا الجدول نفسه يصير سجل
 * "رسائل ما وصلت" جاهز للمراجعة، بدل ما تُبتلع الأخطاء بصمت.
 */
class SendTelegramMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $backoff = 15;

    public int $timeout = 20;

    public function __construct(
        private readonly int|string $chatId,
        private readonly string $text,
        private readonly ?array $keyboard = null,
    ) {
    }

    public function handle(TelegramBotApi $bot): void
    {
        $bot->sendMessage($this->chatId, $this->text, $this->keyboard);
    }
}
