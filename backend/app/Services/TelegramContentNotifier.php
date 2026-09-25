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

        $message = $this->buildMessage($course, $file);

        foreach ($chatIds as $chatId) {
            try {
                $this->bot->sendMessage($chatId, $message);
            } catch (\Throwable $error) {
                report($error);
            }
        }
    }

    /*
     * نفس تسميات الأنواع الموجودة أصلًا بالفرونت إند
     * (admin.html::FILE_KIND_LABELS) — بنفس النص العربي بالضبط حتى ما
     * يقرأ الطالب تسمية مختلفة هون عن يلي يشوفه بلوحة "بناء المادة".
     */
    private const KIND_LABELS = [
        'youtube' => 'يوتيوب', 'vid' => 'فيديو', 'drive' => 'درايف',
        'assignment' => 'تعيين', 'exercise' => 'تدريب', 'exam' => 'اختبار',
        'book' => 'مرجع', 'software' => 'برنامج', 'github' => 'GitHub',
        'pdf' => 'PDF', 'doc' => 'مستند', 'image' => 'صورة',
        'link' => 'رابط', 'other' => 'أخرى',
    ];

    private function buildMessage(Course $course, CourseFile $file): string
    {
        $courseName = $this->esc($course->name_ar ?? $course->name_en ?? $course->code ?? 'المادة');
        $title = $this->esc((string) $file->title);
        $kindLabel = self::KIND_LABELS[$file->kind] ?? null;

        $lines = ['🔔 محتوى جديد بمادة "' . $courseName . '"', ''];

        $lines[] = '📄 <b>' . $title . '</b>' . ($kindLabel ? ' (' . $this->esc($kindLabel) . ')' : '');

        // الوصف يظهر فقط لو الآدمن كتب واحد فعليًا وقت إضافة المحتوى —
        // حقل description اختياري بنموذج "بناء المادة".
        $description = trim((string) $file->description);
        if ($description !== '') {
            $lines[] = $this->esc($description);
        }

        $lines[] = '';

        // الرابط المباشر للمحتوى نفسه (يوتيوب/درايف/أي رابط خارجي) —
        // يظهر فقط لو المحتوى فعلًا من نوع فيه رابط خارجي محفوظ.
        $externalUrl = trim((string) $file->external_url);
        if ($externalUrl !== '') {
            $lines[] = '🔗 الرابط المباشر: ' . $externalUrl;
        }

        $siteLink = rtrim((string) config('app.frontend_url'), '/')
            . '/course.html?course=' . urlencode((string) $course->key)
            . '&content=' . (int) $file->id;

        $lines[] = '🌐 لمشاهدته من الموقع: ' . $siteLink;

        return implode("\n", $lines);
    }

    private function esc(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}
