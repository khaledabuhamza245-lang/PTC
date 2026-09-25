<?php

namespace App\Services;

use App\Models\Course;
use App\Models\CourseFile;
use Illuminate\Support\Facades\DB;

/*
 * أقوى ميزة استباقية بالبوت: تنبيه فوري لكل طالب مربوط حسابه ومسجّل
 * بمادة معينة، فور ما يضيف الطاقم محتوى جديد فيها (CourseFileController
 * @ staff::store هو المستدعي الوحيد حاليًا).
 *
 * ⚠ تنفيذ متزامن (sync) عمدًا بمرحلته الحالية — الاستضافة الحالية
 * بلا queue worker حقيقي (راجع QUEUE_CONNECTION=sync بـ.env.example).
 * مقبول الآن لأن عدد الحسابات المربوطة فعليًا قليل جدًا (بوت تجريبي).
 * قبل أي إطلاق حقيقي لعدد كبير من الطلاب، هاي أول نقطة لازم تتحول
 * لطابور حقيقي (Laravel Queue + Cron يشغّل queue:work دوريًا) حتى ما
 * يتعلّق زر "حفظ" بلوحة تحكم الطاقم بانتظار إرسال عشرات الرسائل.
 *
 * فشل الإرسال لطالب واحد (رقم محادثة محذوف، حظر البوت...) ما يوقف
 * إرسال الباقي — كل رسالة بمحاولتها الخاصة المعزولة.
 */
class TelegramContentNotifier
{
    public function __construct(private TelegramBotApi $bot)
    {
    }

    public function notifyNewFile(CourseFile $file): void
    {
        if (! $file->is_published || ! $this->bot->isConfigured()) {
            return;
        }

        $course = $file->course ?? Course::find($file->course_id);

        if (! $course) {
            return;
        }

        $chatIds = DB::table('my_courses')
            ->join('telegram_links', 'telegram_links.user_id', '=', 'my_courses.user_id')
            ->where('my_courses.course_id', $course->id)
            ->whereNotIn('my_courses.status', ['completed', 'dropped'])
            ->whereNotNull('telegram_links.telegram_chat_id')
            ->pluck('telegram_links.telegram_chat_id');

        if ($chatIds->isEmpty()) {
            return;
        }

        $courseName = $course->name_ar ?? $course->name_en ?? $course->code ?? 'المادة';

        $link = rtrim((string) config('app.frontend_url'), '/')
            . '/course.html?course=' . urlencode((string) $course->key)
            . '&content=' . (int) $file->id;

        $message = "🔔 محتوى جديد بمادة \"{$courseName}\"\n\n📄 {$file->title}\n\n{$link}";

        foreach ($chatIds as $chatId) {
            try {
                $this->bot->sendMessage($chatId, $message);
            } catch (\Throwable $error) {
                report($error);
            }
        }
    }
}
