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
 * tries=1 عمدًا: فشل إرسال لطالب واحد (حظر البوت، رقم محادثة محذوف...)
 * حالة متوقعة وليست عابرة — لا داعي لإعادة المحاولة تلقائيًا، ونلتقطها
 * بأنفسنا بلا رمي استثناء يوقف باقي المهام بالطابور.
 */
class SendTelegramMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 20;

    public function __construct(
        private readonly int|string $chatId,
        private readonly string $text,
        private readonly ?array $keyboard = null,
    ) {
    }

    public function handle(TelegramBotApi $bot): void
    {
        try {
            $bot->sendMessage($this->chatId, $this->text, $this->keyboard);
        } catch (\Throwable $error) {
            report($error);
        }
    }
}
