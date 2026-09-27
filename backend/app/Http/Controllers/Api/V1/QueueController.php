<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/*
 * معالجة طابور المهام المؤجّلة (رسائل تيليجرام الجماعية حاليًا — راجع
 * App\Jobs\SendTelegramMessage) — بنفس فكرة AiAssistantController::
 * processPending بالضبط: الاستضافة الحالية بلا SSH ولا artisan CLI
 * مباشر، فما نقدر نشغّل `php artisan queue:work` كعملية دائمة بالخلفية.
 * الحل العملي: مسار HTTP يشغّل دفعة معالجة قصيرة (queue:work
 * --stop-when-empty) وترجع، تستدعيه نفس خدمة الـ"ping" المجانية الخارجية
 * كل دقيقة (بالإضافة لمسار /ai/process-pending الموجود أصلًا).
 *
 * ⚠ هالمسار بلا أي تأثير إطلاقًا طالما QUEUE_CONNECTION=sync بـ.env
 * (الإعداد الحالي) — كل المهام أصلًا تُنفَّذ فورًا وقت إرسالها، فجدول
 * jobs يبقى فاضي دائمًا وهالمسار بس يرجع "لا يوجد شي" بسرعة. ما في أي
 * خطر بتفعيل هالمسار قبل تغيير .env — إنما فائدته الحقيقية (معالجة
 * بالخلفية فعليًا) ما تبلش إلا بعد تعديل .env يدويًا على السيرفر:
 * QUEUE_CONNECTION=database + QUEUE_CRON_TOKEN=<رمز سرّي من اختيارك>.
 */
class QueueController extends Controller
{
    public function processPending(Request $request)
    {
        @set_time_limit(60);

        if (! env('QUEUE_CRON_TOKEN') || $request->query('token') !== env('QUEUE_CRON_TOKEN')) {
            abort(403);
        }

        /*
         * نفس الاحتياط الموجود بـAiAssistantController::processPending —
         * خدمة الـping الخارجية قد توقف نفسها تلقائيًا لو صادفت عدد
         * كبير من الردود 500، فأي استثناء غير متوقع هون لازم يُلتقَط
         * ويرجع 200 دايمًا.
         */
        try {
            if (config('queue.default') === 'sync') {
                return response()->json(['status' => 'sync-mode', 'processed' => 0]);
            }

            Artisan::call('queue:work', [
                '--stop-when-empty' => true,
                '--max-time' => 50,
                '--tries' => 1,
                '--quiet' => true,
            ]);

            return response()->json(['status' => 'ok']);
        } catch (\Throwable $error) {
            Log::error('queue.process-pending failed', ['error' => $error->getMessage()]);

            return response()->json(['status' => 'error'], 200);
        }
    }
}
