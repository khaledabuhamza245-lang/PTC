<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\TelegramBotApi;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use ZipArchive;

/*
 * نسخ احتياطي دوري تلقائي لقاعدة البيانات كاملة — خطوة 108.
 *
 * لماذا PHP خالصة لا mysqldump: الاستضافة الحالية بلا SSH، وshell_exec()
 * غالبًا معطَّل أو محظور على استضافة مشتركة اقتصادية (قيد أمان شائع من
 * المضيف نفسه) — فلا يمكن الاعتماد عليه. البديل: SHOW CREATE TABLE +
 * SELECT عادي لكل جدول، نفس ما يفعله phpMyAdmin's Export لكن آليًا،
 * بلا أي أمر نظام خارجي.
 *
 * لماذا التسليم عبر بوت تيليجرام لا تخزين على نفس السيرفر: "نسخة
 * احتياطية" تُخزَّن بنفس مكان البيانات الأصلية لا تحمي من أي حادثة
 * تصيب الاستضافة نفسها (تعليق حساب، عطل كامل، حذف بالخطأ من لوحة
 * التحكم). محادثة تيليجرام منفصلة تمامًا = نسخة خارج نطاق أي حادثة
 * كهذه، بلا أي بنية تحتية إضافية (البوت مُفعَّل أصلًا بالمشروع).
 *
 * الحماية برمز سرّي بالرابط (نفس نمط QueueController::processPending)
 * لأن المستدعي آلي (cron-job.org) لا جلسة تسجيل دخول بشرية.
 */
class BackupController extends Controller
{
    public function run(Request $request)
    {
        @set_time_limit(90);

        if (! env('BACKUP_CRON_TOKEN') || $request->query('token') !== env('BACKUP_CRON_TOKEN')) {
            abort(403);
        }

        $chatId = env('BACKUP_TELEGRAM_CHAT_ID');
        $bot = new TelegramBotApi;

        if (! $chatId || ! $bot->isConfigured()) {
            return response()->json([
                'status' => 'not-configured',
                'message' => 'اضبط BACKUP_TELEGRAM_CHAT_ID (وTELEGRAM_BOT_TOKEN) بملف .env أولًا.',
            ]);
        }

        $sqlPath = null;
        $zipPath = null;

        try {
            $sqlPath = $this->dumpDatabase();

            $stamp = now()->format('Y-m-d_H-i');
            $zipName = "ptc_backup_{$stamp}.zip";
            $zipPath = sys_get_temp_dir().'/'.$zipName;

            $zip = new ZipArchive;
            $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            $zip->addFile($sqlPath, "ptc_backup_{$stamp}.sql");
            $zip->close();

            $sizeMb = round(filesize($zipPath) / 1024 / 1024, 2);

            $bot->sendDocument(
                $chatId,
                $zipPath,
                $zipName,
                '🗄 نسخة احتياطية تلقائية لقاعدة البيانات — '.now()->format('Y-m-d H:i')." (~{$sizeMb}MB)"
            );

            return response()->json(['status' => 'ok', 'size_mb' => $sizeMb]);
        } catch (\Throwable $error) {
            Log::error('backup.run failed', [
                'error' => $error->getMessage(),
                'trace' => $error->getTraceAsString(),
            ]);

            // تنبيه فوري بنفس المحادثة لو النسخ فشل — لا صمت عن فشل صامت.
            $bot->sendMessage(
                $chatId,
                TelegramBotApi::escapeHtml('⚠️ فشل النسخ الاحتياطي التلقائي: '.$error->getMessage())
            );

            return response()->json(['status' => 'error', 'error' => $error->getMessage()]);
        } finally {
            if ($sqlPath && is_file($sqlPath)) {
                @unlink($sqlPath);
            }
            if ($zipPath && is_file($zipPath)) {
                @unlink($zipPath);
            }
        }
    }

    /**
     * يبني ملف .sql كامل (بنية + بيانات لكل الجداول) بلا أي اعتماد على
     * mysqldump أو shell_exec — قاعدة بيانات المشروع صغيرة (كتالوج
     * مساقات، طلاب، جداول محاضرات...)، فتحميل كل جدول دفعة واحدة بالذاكرة
     * آمن وكافٍ حاليًا.
     */
    private function dumpDatabase(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ptc_dump_').'.sql';
        $handle = fopen($path, 'w');

        fwrite($handle, '-- PTC Hub backup — '.now()->toDateTimeString()."\n");
        fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\n\n");

        $pdo = DB::connection()->getPdo();
        $driver = DB::connection()->getDriverName();

        /*
         * فرع sqlite موجود فقط لأن بيئة الاختبارات المحلية تستخدم sqlite
         * بالذاكرة (راجع phpunit.xml) — الإنتاج الفعلي دايمًا mysql، وهو
         * الفرع الوحيد الذي يهمّ فعليًا هناك. الفرعان يسمحان باختبار
         * منطق التفريغ (dump) نفسه فعليًا لا مجرد تمريره صوريًا.
         */
        if ($driver === 'sqlite') {
            $tables = collect(DB::select(
                "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"
            ))->pluck('name');
        } else {
            $tables = collect(DB::select('SHOW TABLES'))
                ->map(fn ($row) => array_values((array) $row)[0]);
        }

        foreach ($tables as $table) {
            if ($driver === 'sqlite') {
                $createSql = DB::selectOne(
                    "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?",
                    [$table]
                )->sql;
            } else {
                $createSql = DB::select("SHOW CREATE TABLE `{$table}`")[0]->{'Create Table'};
            }

            fwrite($handle, "DROP TABLE IF EXISTS `{$table}`;\n{$createSql};\n\n");

            $rows = DB::table($table)->get();

            foreach ($rows as $row) {
                $data = (array) $row;
                $columns = implode('`, `', array_keys($data));
                $values = implode(', ', array_map(
                    fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v),
                    array_values($data)
                ));

                fwrite($handle, "INSERT INTO `{$table}` (`{$columns}`) VALUES ({$values});\n");
            }

            fwrite($handle, "\n");
        }

        fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($handle);

        return $path;
    }
}
