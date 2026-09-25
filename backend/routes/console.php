<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('sanctum:prune-expired --hours=24')->daily();

/*
 * تذكيرات محاضرات تيليجرام (SendTelegramLectureReminders) — هاي السطر
 * بس يفيد لو المستخدم مستقبلًا فعّل Cron حقيقي بصيغة "artisan schedule:run"
 * كل دقيقة. حاليًا (بدون SSH) الأسهل تشغيل الأمر مباشرة من Cron لوحة
 * الاستضافة كل ٥ دقائق — راجع telegram_bot_step_reminders_cpanel_cron_setup.txt.
 */
Schedule::command('telegram:send-lecture-reminders')->everyFiveMinutes();
