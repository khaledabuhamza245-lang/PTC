<?php

namespace App\Console\Commands;

use App\Models\ScheduleLecture;
use App\Services\TelegramBotApi;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
 * تذكير تلقائي قبل كل محاضرة بـ١٥ دقيقة — لكل طالب مربوط حسابه بتيليجرام
 * ومفعّل التذكيرات (telegram_links.reminders_enabled). ما في queue worker
 * حقيقي ولا artisan schedule:run فعليًا شغّال (لا SSH بالاستضافة الحالية)،
 * فهاد الأمر مصمَّم يُستدعى مباشرة من Cron Job بلوحة cPanel كل ٥ دقائق —
 * راجع telegram_bot_step_reminders_cpanel_cron_setup.txt للتعليمات الكاملة
 * يلي لازم المستخدم يسوّيها يدويًا مرة وحدة بلوحة الاستضافة.
 *
 * التوقيت: نافذة ٥ دقائق (منيو بين ١١ و١٥ دقيقة قبل بداية المحاضرة) —
 * تتماشى مع تكرار الـCron كل ٥ دقائق فتضمن إطلاق التذكير مرة واحدة بالضبط
 * بدون الحاجة لجدول قفل معقّد، بالإضافة لحماية Cache::add() الذرّية
 * (dedupe) كطبقة حماية إضافية لو الـCron نفّذ مرتين بالغلط بنفس الدقيقة.
 */
class SendTelegramLectureReminders extends Command
{
    protected $signature = 'telegram:send-lecture-reminders';

    protected $description = 'يبعت تذكير تيليجرام لكل طالب مربوط قبل ١٥ دقيقة من بداية محاضرته حسب جدوله الأسبوعي.';

    private const LEAD_MINUTES_MAX = 15;

    private const LEAD_MINUTES_MIN = 10;

    public function handle(TelegramBotApi $bot): int
    {
        if (! $bot->isConfigured()) {
            $this->info('بوت تيليجرام غير مفعّل حاليًا (بدون TELEGRAM_BOT_TOKEN) — تجاوزت.');

            return self::SUCCESS;
        }

        $now = Carbon::now(config('app.timezone'));
        $today = (int) $now->dayOfWeek; // Carbon: 0=الأحد...6=السبت — بنفس ترميز عمود days بالجدول.

        $lectures = ScheduleLecture::query()->get();
        $sent = 0;

        foreach ($lectures as $lecture) {
            $days = is_array($lecture->days) ? $lecture->days : [];

            if (! in_array($today, $days, true)) {
                continue;
            }

            $startTime = (string) $lecture->start_time;

            if (! preg_match('/^\d{2}:\d{2}/', $startTime)) {
                continue;
            }

            $startAt = $now->copy()->setTimeFromTimeString($startTime);
            $minutesUntil = $now->diffInMinutes($startAt, false);

            if ($minutesUntil > self::LEAD_MINUTES_MAX || $minutesUntil <= self::LEAD_MINUTES_MIN) {
                continue;
            }

            $link = DB::table('telegram_links')
                ->where('user_id', $lecture->user_id)
                ->where('reminders_enabled', true)
                ->whereNotNull('telegram_chat_id')
                ->first();

            if (! $link) {
                continue;
            }

            $dedupeKey = "lecture-reminder:{$lecture->id}:{$now->toDateString()}";

            if (! Cache::add($dedupeKey, true, $now->copy()->endOfDay())) {
                continue; // تم إرسال هذا التذكير أصلًا اليوم.
            }

            $typeLabel = $lecture->type === 'online' ? '🌐 اونلاين' : '🏫 حضوري';
            $instructor = trim((string) $lecture->instructor);

            $message = "⏰ <b>تذكير محاضرة</b>\n\n".
                '📚 '.TelegramBotApi::escapeHtml((string) $lecture->name)."\n".
                ($instructor !== '' ? '👤 '.TelegramBotApi::escapeHtml($instructor)."\n" : '').
                '🕐 '.TelegramBotApi::escapeHtml($startTime).' — '.$typeLabel."\n\n".
                'بتبدأ بعد ١٥ دقيقة تقريبًا 🙂';

            $bot->sendMessage($link->telegram_chat_id, $message);
            $sent++;
        }

        $this->info("تم إرسال {$sent} تذكير(ات).");

        return self::SUCCESS;
    }
}
