<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/*
 * معالجة طابور المهام المؤجّلة (رسائل تيليجرام الجماعية حاليًا — راجع
 * App\Jobs\SendTelegramMessage) — بنفس فكرة AiAssistantController::
 * processPending بالضبط: الاستضافة الحالية بلا SSH ولا artisan CLI
 * مباشر، فما نقدر نشغّل `php artisan queue:work` كعملية دائمة بالخلفية.
 *
 * ⚠ محاولة أولى استخدمت Artisan::call('queue:work', ...) وفشلت بخطأ
 * 500 على الاستضافة الحقيقية (راجع سجل تنفيذ cron-job.org). الأمر
 * queue:work مصمَّم أصلًا كعملية طرفية (CLI) دائمة — بيحاول يضبط معالجات
 * إشارات النظام (pcntl signals) ومنطق daemon كامل، وهاد غالبًا بيتعارض
 * مع بيئة استضافة مشتركة مقيَّدة تشغّله من داخل طلب ويب عادي (PHP-FPM)
 * لا من الطرفية. الحل: تجاوزنا أمر queue:work بالكامل، واستخدمنا
 * Illuminate\Queue\Worker::process() مباشرة — نفس الدالة الداخلية يلي
 * queue:work نفسه يستدعيها لكل مهمة، بس بحلقة يدوية بسيطة نتحكم بتوقفها
 * نحن (بالوقت أو بفراغ الطابور)، بلا أي داعي لمعالجة إشارات أو منطق
 * Daemon — آمن تمامًا بسياق طلب HTTP عادي.
 *
 * ⚠ هالمسار بلا أي تأثير إطلاقًا طالما QUEUE_CONNECTION=sync بـ.env —
 * كل المهام أصلًا تُنفَّذ فورًا وقت إرسالها، فجدول jobs يبقى فاضي دائمًا
 * وهالمسار بس يرجع "لا يوجد شي" بسرعة.
 */
class QueueController extends Controller
{
    private const MAX_SECONDS = 45;

    private const QUEUE_NAME = 'default';

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
            $connectionName = config('queue.default');

            if ($connectionName === 'sync') {
                return response()->json(['status' => 'sync-mode', 'processed' => 0]);
            }

            $connection = Queue::connection($connectionName);

            /** @var \Illuminate\Queue\Worker $worker */
            $worker = app('queue.worker');

            $options = new WorkerOptions;
            $options->maxTries = 1;
            $options->timeout = 20;
            $options->sleep = 0;

            $processed = 0;
            $start = microtime(true);

            while ((microtime(true) - $start) < self::MAX_SECONDS) {
                $job = $connection->pop(self::QUEUE_NAME);

                if (! $job) {
                    break;
                }

                $worker->process($connectionName, $job, $options);
                $processed++;
            }

            return response()->json(['status' => 'ok', 'processed' => $processed]);
        } catch (\Throwable $error) {
            Log::error('queue.process-pending failed', [
                'error' => $error->getMessage(),
                'trace' => $error->getTraceAsString(),
            ]);

            return response()->json(['status' => 'error'], 200);
        }
    }
}
