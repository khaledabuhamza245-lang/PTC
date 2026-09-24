<?php

namespace App\Services\Ai;

use App\Models\CourseFile;
use App\Models\CourseFileAiMeta;
use App\Services\GeminiFileService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/*
 * يجهّز ملف مساق (رابط Google Drive خارجي) لأول مرة يُسأل عنه: يحمّله
 * من درايف، يرفعه لملفات Gemini، ويخزّن الرابط الناتج بجدول
 * course_file_ai_meta ليُعاد استخدامه فورًا بكل سؤال لاحق عن نفس
 * الملف — لأي طالب، لا الطالب نفسه فقط — بلا أي تحميل أو رفع إضافي.
 *
 * يعمل فقط مع روابط Google Drive الفعلية؛ روابط يوتيوب أو أي مصدر
 * آخر تُرفَض بهدوء (null) — فيديو لا نص/PDF يصلح لهذا المسار. إخفاق
 * هذي الدالة ليس عطلًا بالضرورة، قد يكون ببساطة رابطًا غير مدعوم أو
 * ملفًا كبيرًا جدًا يرفضه درايف بلا تسجيل دخول فعلي.
 *
 * هذي العملية أبطأ بكثير من استدعاء ذكاء اصطناعي عادي (تحميل + رفع)
 * — لا تُستدعى أبدًا من مسار /ai/ask الفوري، فقط من processPending()
 * بمهلتها الأطول.
 */
class CourseFileGeminiPreparer
{
    private const DOWNLOAD_TIMEOUT_SECONDS = 90;

    /*
     * عدد الصفحات بكل جزء لما يُقسَّم ملف تجاوز حد Gemini الأقصى
     * للتوكنز (راجع rebuildAsChunks). كانت ٤٠ صفحة/جزء — خُفِّضت إلى
     * ١٥ (خطوة ٧١) بعد ما أثبت سجلّ الأخطاء الفعلي أن ملف "تجارب
     * المساق كاملة" (~١٠٩ صفحة) كان يُسقِط PdfPageSplitter بخطأ
     * "Allowed memory size ... exhausted" أثناء استخراج/معالجة جزء
     * بحجم ٤٠ صفحة على استضافة حدّها ١٢٨ ميغا فقط للعملية كاملة — لا
     * فقط الصفحات نفسها، بل بنية الـPDF الداخلية (خطوط، صور مضمّنة)
     * التي تتضخّم بالذاكرة مع عدد الصفحات. ١٥ صفحة/جزء تعطي هامش أمان
     * أكبر بكثير (~٧-٨ أجزاء لملف بهذا الحجم) بثمن بسيط: مزيد من
     * جولات processPending المتتالية لإكمال التلخيص الكامل — مقبول
     * تمامًا لأن كل جزء يُلخَّص مرة واحدة فقط ويُخزَّن (chunks_json)
     * ويُعاد استخدامه لكل طالب لاحق (راجع تعليق rebuildAsChunks).
     */
    private const PAGES_PER_CHUNK = 15;

    public static function fresh(CourseFile $file): ?CourseFileAiMeta
    {
        $meta = CourseFileAiMeta::where('course_file_id', $file->id)->first();

        if ($meta && (!$meta->expires_at || $meta->expires_at->isFuture())) {
            return $meta;
        }

        return self::prepare($file);
    }

    private static function prepare(CourseFile $file): ?CourseFileAiMeta
    {
        $downloaded = self::download($file);
        if (!$downloaded) {
            return null;
        }

        [$bytes, $mimeType] = $downloaded;
        $tempPath = storage_path('app/ai-tmp-' . $file->id . '-' . uniqid() . '.bin');
        file_put_contents($tempPath, $bytes);
        unset($bytes);

        try {
            $uploaded = app(GeminiFileService::class)->uploadFile(
                $tempPath,
                $file->title ?: "course_file_{$file->id}",
                $mimeType
            );
        } catch (\Throwable $e) {
            Log::warning('CourseFileGeminiPreparer: Gemini upload failed: ' . $e->getMessage());
            @unlink($tempPath);
            return null;
        }

        @unlink($tempPath);

        if (empty($uploaded['uri']) || empty($uploaded['name'])) {
            return null;
        }

        return CourseFileAiMeta::updateOrCreate(
            ['course_file_id' => $file->id],
            [
                'google_file_uri' => $uploaded['uri'],
                'google_file_name' => $uploaded['name'],
                /* النوع اللي Gemini نفسه أكّده وقت الرفع — لا نخمّنه
                   مرة ثانية وقت الإجابة، هذا بالضبط ما كان يسبب رفض
                   Gemini للطلب (400) لو اختلف التخمين عن الحقيقة. */
                'google_mime_type' => $uploaded['mimeType'] ?? $mimeType,
                'chunks_json' => null,
                'expires_at' => now()->addHours(47),
            ]
        );
    }

    /*
     * يُستدعى فقط بعد ما يفشل ملف مُجهَّز مسبقًا (بالطريقة العادية
     * أعلاه) بخطأ "تجاوز الحد الأقصى للتوكنز" وقت توليد الإجابة —
     * راجع AiAssistantController::answerWithFullRetry. يعيد تحميل
     * الملف من جديد، يقسّمه لأجزاء أصغر بـPdfPageSplitter، ويرفع كل
     * جزء لملفات Gemini على حدة. التلخيص الفعلي لكل جزء (ودمجها
     * بالنهاية) يصير لاحقًا بجولات processPending منفصلة — هذي الدالة
     * فقط تجهّز الأجزاء وترفعها.
     *
     * ترجع null بهدوء لو الملف أصلًا مش PDF بالبنية البسيطة يلي يدعمها
     * PdfPageSplitter (مشفّر، أو ببنية xref/object streams حديثة) —
     * بهاي الحالة يبقى الملف على حاله (فشل نهائي برسالة "الملف كبير
     * جدًا" الصريحة الموجودة أصلًا، بلا أي محاولة تقسيم غير آمنة).
     */
    public static function rebuildAsChunks(CourseFile $file): ?CourseFileAiMeta
    {
        /*
         * محاولة رفع حد الذاكرة لهذي العملية تحديدًا (خطوة ٧١) — بعض
         * الاستضافات المشتركة تسمح بـini_set محلي أعلى من حد php.ini
         * الافتراضي (128M) حتى لو ما تسمح بتعديل php.ini نفسه؛ لو
         * الاستضافة ترفض هذا التعديل (بعضها يقفله كليًا)، السطر ببساطة
         * لا يُغيّر شيئًا بصمت (@) بلا أي عطل — والتخفيض بـ
         * PAGES_PER_CHUNK أعلاه يبقى خط الدفاع الأساسي والمضمون بغضّ
         * النظر عن نتيجة هذا السطر.
         */
        @ini_set('memory_limit', '256M');

        $downloaded = self::download($file);
        if (!$downloaded) {
            return null;
        }

        [$bytes, $mimeType] = $downloaded;

        /* نكتب الملف الأصلي لملف مؤقت على القرص فورًا ونتخلّى عن نسخة
           الذاكرة — PdfPageSplitter يقرأ من القرص عند الحاجة بس (لا
           يحتفظ بنسخة كاملة بالذاكرة)، وهذا بالضبط ما تجنّب عطل
           "Allowed memory size exhausted" الحقيقي اللي واجهناه بملف
           ٤٣ ميغا على استضافة بحد ذاكرة ١٢٨ ميغا فقط. */
        $sourcePath = storage_path('app/ai-tmp-' . $file->id . '-src-' . uniqid() . '.bin');
        file_put_contents($sourcePath, $bytes);
        unset($bytes);

        $splitter = new PdfPageSplitter($sourcePath);
        if (!$splitter->isSplittable()) {
            Log::warning("CourseFileGeminiPreparer: file {$file->id} لا يمكن تقسيمه (بنية PDF غير مدعومة للتقسيم الآمن).");
            @unlink($sourcePath);
            return null;
        }

        $totalPages = $splitter->pageCount();
        if ($totalPages < 2) {
            // صفحة واحدة تتجاوز الحد لوحدها — التقسيم لن يساعد أصلًا.
            @unlink($sourcePath);
            return null;
        }

        $numChunks = (int) max(2, ceil($totalPages / self::PAGES_PER_CHUNK));
        $chunkSize = (int) ceil($totalPages / $numChunks);

        $parts = [];
        $index = 0;

        for ($start = 0; $start < $totalPages; $start += $chunkSize) {
            $end = min($start + $chunkSize, $totalPages);
            $chunkPath = storage_path('app/ai-tmp-' . $file->id . '-chunk' . $index . '-' . uniqid() . '.pdf');

            try {
                // يكتب الجزء مباشرة لملف — بلا أي سلسلة PHP وسيطة كبيرة
                // بالذاكرة (راجع تعليق extractRangeToFile بالمُقسِّم).
                $splitter->extractRangeToFile($start, $end, $chunkPath);
            } catch (\Throwable $e) {
                Log::warning("CourseFileGeminiPreparer: فشل استخراج جزء [{$start},{$end}) للملف {$file->id}: " . $e->getMessage());
                @unlink($chunkPath);
                @unlink($sourcePath);
                return null;
            }

            try {
                $uploaded = app(GeminiFileService::class)->uploadFile(
                    $chunkPath,
                    ($file->title ?: "course_file_{$file->id}") . " (جزء " . ($index + 1) . ")",
                    'application/pdf'
                );
            } catch (\Throwable $e) {
                Log::warning("CourseFileGeminiPreparer: فشل رفع جزء {$index} للملف {$file->id}: " . $e->getMessage());
                @unlink($chunkPath);
                @unlink($sourcePath);
                return null;
            }

            @unlink($chunkPath);

            if (empty($uploaded['uri']) || empty($uploaded['name'])) {
                @unlink($sourcePath);
                return null;
            }

            $parts[] = [
                'index' => $index,
                'google_file_uri' => $uploaded['uri'],
                'google_mime_type' => $uploaded['mimeType'] ?? 'application/pdf',
                /* تلخيص هذا الجزء لوحده — يُملأ لاحقًا جولة بجولة
                   بـAiAssistantController::handleChunkedFile. */
                'summary' => null,
            ];

            $index++;
        }

        @unlink($sourcePath);

        return CourseFileAiMeta::updateOrCreate(
            ['course_file_id' => $file->id],
            [
                /* الملف الكامل الأصلي لم يعد يُستخدم مباشرة — الإجابة
                   تُبنى من الأجزاء فقط (راجع handleChunkedFile). نُبقي
                   google_file_uri/name/mime_type على قيمتهم القديمة
                   بدل تفريغها: العمود google_file_uri غير قابل للـNULL
                   بقاعدة البيانات الفعلية، وتفريغه كان يسبب فشل هذا
                   الحفظ بصمت كل جولة (SQLSTATE 23000) فيرجع التقسيم
                   يعيد نفسه من الصفر بلا نهاية بدل ما يكمل. هذه الحقول
                   غير مستخدَمة أصلًا بمسار الملفات المُقسَّمة
                   (handleChunkedFile يعتمد فقط على chunks_json). */
                'chunks_json' => json_encode(['total' => count($parts), 'parts' => $parts], JSON_UNESCAPED_UNICODE),
                'expires_at' => now()->addHours(47),
            ]
        );
    }

    /** @return array{0:string,1:string}|null [bytes, mimeType] */
    private static function download(CourseFile $file): ?array
    {
        $url = (string) $file->external_url;

        /*
         * رابط مجلد Google Drive (يحتوي عدة ملفات) لا ملف واحد — هذه
         * الميزة تدعم ملفًا واحدًا فقط لكل CourseFile (endpoint التحميل
         * أدناه أصلًا مخصَّص لملف مفرد، ويرجع صفحة HTML لا محتوى حقيقي
         * لو استُخدم مع رابط مجلد، فيفشل التجهيز دائمًا بلا أي تفسير
         * واضح بالسجلّ). تمييزه هنا يوفّر تشخيصًا فوريًا من السجلّ بدل
         * رسالة "رابط غير مدعوم أو كبير جدًا" العامة المضلِّلة — هذا
         * بالضبط ما اتضح أنه سبب فشل ملف "تجارب المساق كاملة" الحقيقي:
         * رابطه بلوحة التحكم كان مجلدًا فيه عدة PDF لا ملفًا واحدًا.
         * الحل الفعلي إداري: استبدال الرابط بملف PDF واحد مباشر (أو
         * تقسيم المحتوى لعدة سجلات CourseFile، كل واحد برابط ملف مفرد)
         * — لا يوجد إصلاح برمجي هنا، لأن هذا سلوك متوقَّع لا عطل.
         */
        if (str_contains($url, '/folders/')) {
            Log::warning("CourseFileGeminiPreparer: file {$file->id} رابطه مجلد Google Drive (فيه عدة ملفات) لا ملف واحد — استبدل الرابط بملف PDF مباشر من لوحة التحكم.");
            return null;
        }

        $driveId = self::extractDriveId($url);
        if (!$driveId) {
            Log::info("CourseFileGeminiPreparer: file {$file->id} ليس رابط Google Drive قابلًا للتحضير.");
            return null;
        }

        $downloadUrl = "https://drive.usercontent.google.com/download?id={$driveId}&export=download&confirm=t";

        try {
            $response = Http::timeout(self::DOWNLOAD_TIMEOUT_SECONDS)->get($downloadUrl);
        } catch (\Throwable $e) {
            Log::warning('CourseFileGeminiPreparer: download failed: ' . $e->getMessage());
            return null;
        }

        $contentType = $response->header('Content-Type') ?? '';
        if (!$response->successful() || str_contains($contentType, 'text/html')) {
            /* على الأغلب صفحة تأكيد درايف لملف كبير جدًا أو محظور
               المشاركة العامة — لا محتوى الملف نفسه. */
            Log::warning("CourseFileGeminiPreparer: استجابة غير متوقعة للملف {$file->id} (drive id {$driveId}), content-type={$contentType}");
            return null;
        }

        $mimeType = $file->mime_type ?: ($contentType ?: 'application/pdf');

        return [$response->body(), $mimeType];
    }

    private static function extractDriveId(string $url): ?string
    {
        if (preg_match('#/d/([a-zA-Z0-9_-]{15,})#', $url, $m)) {
            return $m[1];
        }
        if (preg_match('#[?&]id=([a-zA-Z0-9_-]{15,})#', $url, $m)) {
            return $m[1];
        }
        return null;
    }
}