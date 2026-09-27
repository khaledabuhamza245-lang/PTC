<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/*
 * معالجة طابور المهام المؤجّلة (رسائل تيليجرام الجماعية حاليًا — راجع
 * App\Jobs\SendTelegramMessage) — بنفس فكرة AiAssistantController::
 * processPending بالضبط: الاستضافة الحالية بلا SSH ولا artisan CLI
 * مباشر، فما نقدر نشغّل `php artisan queue:work` كعملية دائمة بالخلفية.
 *
 * ⚠ محاولة أولى استخدمت Artisan::call('queue:work', ...) وفشلت بخطأ 500
 * على الاستضافة الحقيقية. الأمر queue:work مصمَّم أصلًا كعملية طرفية
 * (CLI) دائمة — بيحاول يضبط معالجات إشارات النظام (pcntl) ومنطق daemon
 * كامل، وهاد تعارض مع تشغيله من داخل طلب ويب عادي (PHP-FPM). الحل:
 * تجاوزنا أمر queue:work بالكامل، واستخدمنا Illuminate\Queue\Worker::
 * process() مباشرة — نفس الدالة الداخلية يلي queue:work نفسه يستدعيها
 * لكل مهمة، بس بحلقة يدوية بسيطة نتحكم بتوقفها نحن.
 *
 * ⚠ محاولة ثانية (هذه النسخة): بعد إصلاح الـ500، صار الرد 200 لكن
 * processed:0 دايمًا رغم وجود صفوف فعلية بجدول jobs — يعني pop() ما لقى
 * أي صف مؤهّل. أضفت حقل "debug" صريح بالرد يفحص بالضبط: اسم اتصال
 * الطابور الفعلي، اسم الجدول، اسم قائمة الانتظار (queue) المتوقّعة، وعدد
 * الصفوف الكلي والمؤهّل بالجدول مباشرة (بدون المرور عبر DatabaseQueue) —
 * حتى نعرف بالضبط أين الفجوة (اسم queue مختلف، اتصال DB مختلف، توقيت
 * available_at، أو غير ذلك) من نتيجة تنفيذ واحدة بس، بدل التخمين.
 */
class QueueController extends Controller
{
    private const MAX_SECONDS = 45;

    public function processPending(Request $request)
    {
        @set_time_limit(60);

        if (! env('QUEUE_CRON_TOKEN') || $request->query('token') !== env('QUEUE_CRON_TOKEN')) {
            abort(403);
        }

        try {
            $connectionName = config('queue.default');

            if ($connectionName === 'sync') {
                return response()->json(['status' => 'sync-mode', 'processed' => 0]);
            }

            $table = config("queue.connections.{$connectionName}.table", 'jobs');
            $queueName = config("queue.connections.{$connectionName}.queue", 'default');
            $now = time();

            $debug = [
                'connection' => $connectionName,
                'table' => $table,
                'queue' => $queueName,
                'now' => $now,
                'rows_total' => DB::table($table)->count(),
                'rows_matching_queue' => DB::table($table)->where('queue', $queueName)->count(),
                'rows_eligible' => DB::table($table)
                    ->where('queue', $queueName)
                    ->whereNull('reserved_at')
                    ->where('available_at', '<=', $now)
                    ->count(),
            ];

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
                $job = $connection->pop($queueName);

                if (! $job) {
                    break;
                }

                $worker->process($connectionName, $job, $options);
                $processed++;
            }

            return response()->json(['status' => 'ok', 'processed' => $processed, 'debug' => $debug]);
        } catch (\Throwable $error) {
            Log::error('queue.process-pending failed', [
                'error' => $error->getMessage(),
                'trace' => $error->getTraceAsString(),
            ]);

            return response()->json([
                'status' => 'error',
                'error' => $error->getMessage(),
            ], 200);
        }
    }
}
