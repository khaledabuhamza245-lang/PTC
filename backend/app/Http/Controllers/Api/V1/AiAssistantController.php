<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AiAnswerCache;
use App\Models\AiConversation;
use App\Models\AiQuestion;
use App\Models\AiAttachment;
use App\Models\CourseFile;
use App\Models\CourseFileAiMeta;
use App\Services\Ai\CourseFileGeminiPreparer;
use App\Services\Ai\CourseFileMatcher;
use App\Services\Ai\StudentContextBuilder;
use App\Services\GeminiFileService;
use App\Services\PlanCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

/*
 * السؤال يُسجَّل فورًا بحالة "pending". فور تسجيله نحاول الإجابة عليه
 * *فورًا وبشكل متزامن* بمزوّد واحد فقط ومهلة قصيرة (tryImmediateAnswer)
 * — إن نجح، يرجع الرد بنفس طلب /ai/ask دون أي انتظار إضافي. إن فشل أو
 * تأخر، أو كان يحتاج تجهيز ملف مساق لأول مرة (أبطأ من أن يُحسم بمهلة
 * قصيرة)، يبقى pending وتلتقطه processPending() لاحقًا — تستدعيها
 * خدمة مجانية خارجية كل دقيقة كحد أقصى للانتظار، لا كل مرة بالضرورة.
 *
 * أكثر من "مزوّد" واحد بالتناوب (providerPool): عدة مفاتيح Gemini
 * مجانية + OpenRouter كمزوّد إضافي أخير. نقطة البداية بالتدوير تُحسَب
 * من معرّف السؤال، فتفرّق تلقائيًا بين أسئلة طلاب مختلفين وصلت بنفس
 * اللحظة على مزوّدين مختلفين.
 *
 * "اشرحلي ملف كذا" (قسم ٥): CourseFileMatcher يطابق اسم ملف مذكور
 * بنص السؤال بملف حقيقي منشور، من أي صفحة بالموقع لا صفحة المادة
 * فقط. أول مرة يُسأل عن ملف بعينه، CourseFileGeminiPreparer يحمّله
 * من درايف ويرفعه لـGemini (بطيء، يحدث فقط بالدُفعة الدورية لا الرد
 * الفوري) ويخزّن رابطه لإعادة الاستخدام الفوري لاحقًا من أي طالب.
 */
class AiAssistantController extends Controller
{
    private const DAILY_LIMIT = 30;
    private const BATCH_SIZE = 3;
    private const MAX_FILE_SIZE = 50 * 1024 * 1024;
    private const ALLOWED_MIME_TYPES = [
        'application/pdf', 'application/json', 'text/plain', 'text/csv', 'text/markdown',
        'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/bmp',
    ];

    private const IMMEDIATE_TIMEOUT_SECONDS = 12;
    private const BATCH_TIMEOUT_SECONDS = 18;

    /*
     * ملفات كبيرة/معقّدة تحتاج Gemini وقتًا أطول من ١٨ ثانية ليقرأها
     * ويولّد ملخّصها — بمهلة قصيرة كهذي، أول محاولة فاشلة (Timeout)
     * كانت تعني فشلًا نهائيًا فوريًا لأن أسئلة الملفات تُجاب بمزوّد
     * واحد فقط بلا تدوير (راجع primaryGeminiProvider أدناه). ٦٠ ثانية
     * تعطي مساحة كافية لملفات أكبر بكثير من أي ملف واجهناه لحد الآن،
     * بلا أي تأثير على سرعة الأسئلة النصية العادية (تبقى بمهلتها
     * القصيرة الأصلية BATCH_TIMEOUT_SECONDS).
     */
    private const BATCH_FILE_TIMEOUT_SECONDS = 60;

    private const GEMINI_MODEL = 'gemini-3.5-flash-lite';

    /* رسالة صريحة وصادقة لملف يتجاوز فعليًا حد Gemini الأقصى (رموز/توكنز)
       — لا فائدة من إعادة المحاولة هنا مهما طال الانتظار أو تكررت
       الدورات، الحجم ثابت. راجع callGemini لمكان اكتشاف هذا الخطأ تحديدًا. */
    private const FILE_TOO_LARGE_MESSAGE =
        'هذا الملف كبير جدًا على قدرة المساعد الحالية (تجاوز الحد الأقصى لتحليله دفعة واحدة). جرّب تسأل عن جزء محدد من محتواه بدل الملف كامل.';

    /* كلمات ترجّح أن السؤال عن ملف بعينه — تُستخدم فقط لصياغة رسالة
       "ما لقيت الملف" الصريحة حين لا تنجح المطابقة، لا لأي قرار آخر. */
    private const FILE_INTENT_HINTS = ['ملف', 'شابتر', 'chapter', 'pdf', 'محاضرة', 'ملزمة'];

    private function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = str_replace(['أ', 'إ', 'آ', 'ٱ'], 'ا', $text);
        $text = str_replace('ة', 'ه', $text);
        $text = str_replace('ى', 'ي', $text);
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', '', $text);
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }

    /*
     * الحدّ اليومي يتصفّر مع منتصف الليل بالضبط (بتوقيت السيرفر)، لا
     * بعد ٢٤ ساعة من أول سؤال (نافذة متحركة كانت تعطي كل طالب توقيت
     * تصفير مختلف حسب أول سؤال سأله، بلا علاقة بمنتصف الليل الفعلي).
     * المفتاح نفسه يحمل تاريخ اليوم، فبمجرد ما يتغيّر التاريخ يصير
     * مفتاحًا جديدًا فارغًا تلقائيًا — بلا أي مهمة تصفير مجدولة.
     */
    private function dailyUsageKey(int $userId): string
    {
        return 'ai-assistant-usage:' . $userId . ':' . now()->toDateString();
    }

    private function dailyUsageCount(int $userId): int
    {
        return (int) Cache::get($this->dailyUsageKey($userId), 0);
    }

    private function incrementDailyUsage(int $userId): void
    {
        $key = $this->dailyUsageKey($userId);
        Cache::put($key, $this->dailyUsageCount($userId) + 1, now()->endOfDay());
    }

    /*
     * تسجيل بسيط لاستهلاك كل مزوّد (كل مفتاح Gemini + OpenRouter) على
     * حدة، يوميًا — بلا حاجة لأي أداة مراقبة خارجية أو وصول SSH.
     * يُحفَظ بنفس آلية Cache المستخدمة لعدّاد الطالب (dailyUsageKey)
     * ويتصفّر تلقائيًا مع منتصف الليل لنفس السبب (المفتاح يحمل التاريخ).
     * "gemini_1/2/3" = ترتيب المفاتيح بـ.env (الأساسي، الثاني، الثالث)
     * بغض النظر عن أي حساب Google أنشئ منه كل مفتاح.
     */
    private function providerLabel(array $provider): string
    {
        if ($provider['type'] === 'openrouter') {
            return 'openrouter';
        }

        $key = $provider['key'] ?? null;
        if ($key === config('services.gemini.key')) {
            return 'gemini_1';
        }
        if ($key === env('GEMINI_API_KEY_2')) {
            return 'gemini_2';
        }
        if ($key === env('GEMINI_API_KEY_3')) {
            return 'gemini_3';
        }

        return 'gemini_unknown';
    }

    private function providerUsageKey(string $label): string
    {
        return 'ai-provider-usage:' . $label . ':' . now()->toDateString();
    }

    /*
     * $result أحد: ok | rate_limited | failed. تُحفَظ الثلاثة كأعداد
     * منفصلة تحت نفس المفتاح اليومي عشان تفرّق فورًا بين "استُخدم
     * وقُبل" و"ضرب الحد اليومي عند Google" و"فشل بسبب آخر (شبكة، خطأ
     * غير متوقع...)".
     */
    private function incrementProviderUsage(string $label, string $result): void
    {
        $key = $this->providerUsageKey($label);
        $counts = Cache::get($key, ['ok' => 0, 'rate_limited' => 0, 'failed' => 0]);
        $counts[$result] = ($counts[$result] ?? 0) + 1;
        Cache::put($key, $counts, now()->endOfDay());
    }

    private function providerUsageSnapshot(string $label): array
    {
        return Cache::get($this->providerUsageKey($label), ['ok' => 0, 'rate_limited' => 0, 'failed' => 0]);
    }

    public function uploadAttachment(Request $request, GeminiFileService $files)
    {
        $data = $request->validate([
            'file' => [
                'required',
                'file',
                'max:' . (int) ceil(self::MAX_FILE_SIZE / 1024),
                'mimetypes:' . implode(',', self::ALLOWED_MIME_TYPES),
            ],
        ]);

        AiAttachment::where('expires_at', '<', now())->delete();

        $key = 'ai-file-upload:' . $request->user()->id;
        if (RateLimiter::tooManyAttempts($key, 10)) {
            return response()->json(['message' => 'رفعت عددًا كبيرًا من الملفات خلال فترة قصيرة. جرّب بعد قليل.'], 429);
        }
        RateLimiter::hit($key, 60);

        $uploaded = $request->file('file');

        try {
            $file = $files->uploadFile(
                $uploaded->getRealPath(),
                $uploaded->getClientOriginalName(),
                $uploaded->getMimeType()
            );
        } catch (\Throwable $e) {
            \Log::warning('Gemini direct attachment upload failed: ' . $e->getMessage());
            return response()->json(['message' => 'تعذّر رفع الملف حاليًا، جرّب مرة أخرى.'], 503);
        }

        $attachment = AiAttachment::create([
            'user_id' => $request->user()->id,
            'display_name' => $uploaded->getClientOriginalName(),
            'mime_type' => $file['mimeType'] ?? $uploaded->getMimeType(),
            'size_bytes' => (int) ($file['sizeBytes'] ?? $uploaded->getSize()),
            'provider_name' => $file['name'],
            'provider_uri' => $file['uri'],
            'status' => 'ready',
            'expires_at' => isset($file['expirationTime'])
                ? \Carbon\Carbon::parse($file['expirationTime'])
                : now()->addHours(48),
        ]);

        return response()->json([
            'attachment' => [
                'id' => $attachment->id,
                'name' => $attachment->display_name,
                'mime_type' => $attachment->mime_type,
                'size_bytes' => $attachment->size_bytes,
            ],
        ], 201);
    }

    public function startAttachmentUpload(Request $request, GeminiFileService $files)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:180'],
            'mime_type' => ['required', 'string', 'max:120', 'in:' . implode(',', self::ALLOWED_MIME_TYPES)],
            'size' => ['required', 'integer', 'min:1', 'max:' . self::MAX_FILE_SIZE],
        ]);

        AiAttachment::where('expires_at', '<', now())->delete();

        $key = 'ai-file-upload:' . $request->user()->id;
        if (RateLimiter::tooManyAttempts($key, 10)) {
            return response()->json(['message' => 'رفعت عددًا كبيرًا من الملفات خلال فترة قصيرة. جرّب بعد قليل.'], 429);
        }
        RateLimiter::hit($key, 60);

        try {
            $uploadUrl = $files->startUpload($data['name'], $data['mime_type'], $data['size']);
        } catch (\Throwable $e) {
            \Log::warning('Gemini attachment upload session failed: ' . $e->getMessage());
            return response()->json(['message' => 'تعذّر تجهيز رفع الملف حاليًا.'], 503);
        }

        $attachment = AiAttachment::create([
            'user_id' => $request->user()->id,
            'display_name' => $data['name'],
            'mime_type' => $data['mime_type'],
            'size_bytes' => $data['size'],
            'provider_name' => 'pending-' . \Illuminate\Str::uuid(),
            'provider_uri' => '',
            'status' => 'uploading',
            'expires_at' => now()->addHours(48),
        ]);

        return response()->json([
            'attachment_id' => $attachment->id,
            'upload_url' => $uploadUrl,
            'headers' => [
                'Content-Type' => $data['mime_type'],
                'X-Goog-Upload-Offset' => '0',
                'X-Goog-Upload-Command' => 'upload, finalize',
            ],
        ], 201);
    }

    public function completeAttachmentUpload(Request $request, GeminiFileService $files)
    {
        $data = $request->validate([
            'attachment_id' => ['required', 'integer'],
            'provider_name' => ['required', 'string', 'max:100'],
        ]);

        $attachment = AiAttachment::where('id', $data['attachment_id'])
            ->where('user_id', $request->user()->id)
            ->where('status', 'uploading')
            ->firstOrFail();

        try {
            $file = $files->getFile($data['provider_name']);
        } catch (\Throwable $e) {
            $attachment->update(['status' => 'failed']);
            \Log::warning('Gemini attachment verification failed: ' . $e->getMessage());
            try { $files->deleteFile($data['provider_name']); } catch (\Throwable) {}
            return response()->json([
                'message' => 'تعذّر تجهيز هذا الملف للمساعد. جرّب رفعه مرة أخرى.',
            ], 422);
        }

        $providerMime = $file['mimeType'] ?? null;
        $providerSize = (int) ($file['sizeBytes'] ?? 0);
        $providerUri = $file['uri'] ?? null;
        $providerName = $file['name'] ?? null;

        if (!$providerUri || !$providerName || $providerName !== $data['provider_name']
            || $providerMime !== $attachment->mime_type
            || $providerSize !== $attachment->size_bytes
            || !in_array($providerMime, self::ALLOWED_MIME_TYPES, true)) {
            $attachment->update(['status' => 'failed']);
            try { $files->deleteFile($data['provider_name']); } catch (\Throwable) {}
            return response()->json(['message' => 'الملف المرفوع لا يطابق البيانات المتوقعة.'], 422);
        }

        $attachment->update([
            'provider_name' => $providerName,
            'provider_uri' => $providerUri,
            'status' => 'ready',
            'expires_at' => isset($file['expirationTime']) ? \Carbon\Carbon::parse($file['expirationTime']) : now()->addHours(48),
        ]);

        return response()->json([
            'attachment' => [
                'id' => $attachment->id,
                'name' => $attachment->display_name,
                'mime_type' => $attachment->mime_type,
                'size_bytes' => $attachment->size_bytes,
            ],
        ]);
    }

    public function deleteAttachment(Request $request, GeminiFileService $files, AiAttachment $aiAttachment)
    {
        if ($aiAttachment->user_id !== $request->user()->id) abort(404);

        try {
            if (str_starts_with($aiAttachment->provider_name, 'files/')) {
                $files->deleteFile($aiAttachment->provider_name);
            }
        } catch (\Throwable) {
            // Gemini deletes temporary files automatically; local metadata can still be removed.
        }

        $aiAttachment->delete();
        return response()->json(['ok' => true]);
    }

    public function ask(Request $request, PlanCalculator $calculator)
    {
        $data = $request->validate([
            'message' => ['nullable', 'string', 'max:800'],
            'course_name' => ['nullable', 'string', 'max:150'],
            'conversation_id' => ['nullable', 'integer'],
            'attachments' => ['nullable', 'array', 'max:4'],
            'attachments.*' => ['integer'],
        ]);

        $user = $request->user();
        $attachmentIds = array_values(array_unique($data['attachments'] ?? []));
        $attachments = collect();
        if ($attachmentIds) {
            $attachments = AiAttachment::where('user_id', $user->id)
                ->where('status', 'ready')
                ->whereIn('id', $attachmentIds)
                ->where(function ($q) {
                    $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
                })
                ->get();
            if ($attachments->count() !== count($attachmentIds)) {
                return response()->json(['message' => 'أحد المرفقات غير صالح أو انتهت صلاحيته.'], 422);
            }
        }
        if (trim($data['message'] ?? '') === '' && $attachments->isEmpty()) {
            return response()->json(['message' => 'اكتب سؤالك أو أرفق ملفًا أولًا.'], 422);
        }

        $messageText = trim($data['message'] ?? '');

        if ($this->dailyUsageCount($user->id) >= self::DAILY_LIMIT) {
            return response()->json([
                'message' => 'وصلت الحد الأقصى للأسئلة اليوم (' . self::DAILY_LIMIT . ' سؤالًا). سيتم تجديد حدّك تلقائيًا عند الساعة 12 صباحًا.',
            ], 429);
        }

        $conversation = null;

        if (!empty($data['conversation_id'])) {
            $conversation = AiConversation::where('id', $data['conversation_id'])
                ->where('user_id', $user->id)
                ->first();
        }

        if (!$conversation) {
            $conversation = AiConversation::create([
                'user_id' => $user->id,
                'title' => \Illuminate\Support\Str::limit($messageText ?: $attachments->first()?->display_name ?: 'محادثة جديدة', 60),
                'messages' => [],
            ]);
        }

        /*
         * "اشرحلي ملف كذا": يعمل من أي صفحة، مو بس صفحة المادة —
         * course_name (إن وُجد) يُستخدم فقط كتلميح لتضييق البحث أولًا،
         * ويُعاد البحث بكل المواد تلقائيًا لو فشل ضمن الأولوية.
         */
        $referencedFile = $messageText !== ''
            ? CourseFileMatcher::find($messageText, $data['course_name'] ?? null)
            : null;

        /*
         * ما في ذكر صريح لملف بهذا السؤال؟ لو المحادثة نفسها "مثبَّت"
         * عليها ملف سابق (من سؤال سابق بنفس المحادثة أو زر "لخّصلي")،
         * نرفقه تلقائيًا — الطالب ما بيحتاج يكرر اسم الملف بكل سؤال
         * متابعة طالما ضل بنفس المحادثة.
         */
        if (!$referencedFile && $conversation->pinned_course_file_id) {
            $referencedFile = CourseFile::find($conversation->pinned_course_file_id);
        }

        /* أي ملف يُحسم لهذا السؤال (بالذكر الصريح أو بالتثبيت) يصير هو
           الملف المثبَّت على المحادثة من الآن — يبقى ساريًا للأسئلة
           الجاية لحد ما يبدأ محادثة جديدة أو يُلخَّص ملف تاني. */
        if ($referencedFile && $conversation->pinned_course_file_id !== $referencedFile->id) {
            $conversation->update(['pinned_course_file_id' => $referencedFile->id]);
        }

        /* سؤال عن ملف لا يُخدَم من ذاكرة التخزين المؤقت العامة — إجابة
           مخزَّنة سابقًا لا علاقة لها بمحتوى ملف بعينه.
           الذاكرة المؤقتة خاصة بكل طالب على حدة (user_id) لا مشتركة
           بين الجميع — الإجابة نفسها تتضمّن اسم الطالب وبياناته
           الشخصية بفضل التخصيص، فمشاركتها بين طلاب مختلفين تسرّب
           بيانات طالب لطالب آخر. */
        $hash = hash('sha256', $this->normalize($messageText));
        $cached = ($attachments->isEmpty() && !$referencedFile)
            ? AiAnswerCache::where('question_hash', $hash)->where('user_id', $user->id)->first()
            : null;

        if ($cached) {
            $cached->increment('hit_count');

            $question = AiQuestion::create([
                'user_id' => $user->id,
                'conversation_id' => $conversation->id,
                'message' => $messageText,
                'course_name' => $data['course_name'] ?? null,
                'status' => 'done',
                'reply' => $cached->reply,
            ]);

            $this->appendToConversation($conversation, $data['message'], $cached->reply);

            return response()->json([
                'question_id' => $question->id,
                'conversation_id' => $conversation->id,
                'reply' => $cached->reply,
                'remaining' => max(0, self::DAILY_LIMIT - $this->dailyUsageCount($user->id)),
            ], 200);
        }

        $this->incrementDailyUsage($user->id);

        $question = AiQuestion::create([
            'user_id' => $user->id,
            'conversation_id' => $conversation->id,
            'message' => $messageText,
            'course_name' => $data['course_name'] ?? null,
            'status' => 'pending',
            'referenced_course_file_id' => $referencedFile?->id,
        ]);

        if ($attachments->isNotEmpty()) {
            $question->attachments()->sync($attachments->pluck('id')->all());
        }

        /*
         * ملف يحتاج تجهيزًا لأول مرة (تحميل من درايف + رفع لـGemini)
         * أبطأ من أن يُحسم بالمهلة القصيرة — يُستثنى من المسار الفوري
         * صراحة، حتى لو كان بلا مرفقات، ويُترك لـprocessPending.
         */
        $fileNeedsPrep = $referencedFile
            ? !$this->hasFreshMeta($referencedFile->id)
            : false;

        if ($attachments->isEmpty() && !$fileNeedsPrep) {
            $question->setRelation('user', $user);
            if ($referencedFile) {
                $question->setRelation('referencedCourseFile', $referencedFile);
            }

            $immediate = $this->tryImmediateAnswer($question, $calculator);

            if ($immediate !== null) {
                $this->appendToConversation($conversation, $data['message'], $immediate);

                return response()->json([
                    'question_id' => $question->id,
                    'conversation_id' => $conversation->id,
                    'reply' => $immediate,
                    'remaining' => max(0, self::DAILY_LIMIT - $this->dailyUsageCount($user->id)),
                ], 200);
            }
        }

        return response()->json([
            'question_id' => $question->id,
            'conversation_id' => $conversation->id,
            'remaining' => max(0, self::DAILY_LIMIT - $this->dailyUsageCount($user->id)),
        ], 202);
    }

    /* نص التنسيق الصارم المطلوب من Gemini لبطاقات المراجعة — يُقرأ
       فورًا بالواجهة (renderAssistantContent بـapp.js) عبر أسطر تبدأ
       بـ"س:" و"ج:" مفصولة بـ"---"؛ أي انحراف عن هذا الشكل يخلي
       الفرونت-إند يتراجع تلقائيًا لعرض النص العادي بدل بطاقات (لا
       يفشل بصمت ولا يكسر شيء لو Gemini ما التزم بالضبط). */
    private const FLASHCARDS_FORMAT_INSTRUCTION =
        ". اكتب كل بطاقة بالضبط بهذا الشكل، بدون أي مقدمة أو خاتمة أو أي نص خارج هذا النمط:\n"
        . "س: نص السؤال\nج: نص الجواب مختصر بجملة أو جملتين كحد أقصى\n---\n"
        . 'كرر هذا النمط لكل بطاقة، على أن يكون عندك بين 6 و12 بطاقة تغطي أهم المفاهيم بالملف.';

    /*
     * زر "لخّصلي" / "بطاقات مراجعة" على أيقونة ملف (بدل ما يكتب الطالب
     * اسمه بالنص) — يحدّد الملف بمعرّفه مباشرة، بلا أي مطابقة اسم أو
     * تخمين. يفتح محادثة جديدة دايمًا ويثبّت الملف عليها فورًا، فأي
     * سؤال متابعة بنفس المحادثة يرفق نفس الملف تلقائيًا (راجع منطق
     * التثبيت بدالة ask أعلاه).
     *
     * $mode: 'summary' (افتراضي، السلوك الأصلي) أو 'flashcards' —
     * فرق بسيط بنص السؤال المُرسَل فقط، بلا أي تغيير على بقية تدفّق
     * الإجابة (فوري/مؤجل، حدّ يومي، تثبيت الملف... كله كما هو).
     */
    public function summarizeFile(Request $request, CourseFile $courseFile, PlanCalculator $calculator)
    {
        /*
         * ai_summarizable: تحكّم صريح من الطاقم (لوحة التحكم) بإمكانية
         * تلخيص/تحويل هذا الملف بالذات — لا يكفي الاعتماد على إخفاء
         * الزر بالواجهة فقط، لأن استدعاء هذا المسار مباشرة (بلا مرور
         * على الزر) كان سيتجاوزه قبل هذا الفحص. راجع CourseFile::$casts
         * وStaff\CourseFileController::defaultAiSummarizable للقيمة
         * الافتراضية حسب نوع الملف.
         */
        abort_unless($courseFile->is_published && $courseFile->status === 'ready' && $courseFile->ai_summarizable, 404);

        $mode = $request->input('mode', 'summary');
        if (!in_array($mode, ['summary', 'flashcards'], true)) {
            $mode = 'summary';
        }

        $user = $request->user();

        if ($this->dailyUsageCount($user->id) >= self::DAILY_LIMIT) {
            return response()->json([
                'message' => 'وصلت الحد الأقصى للأسئلة اليوم (' . self::DAILY_LIMIT . ' سؤالًا). سيتم تجديد حدّك تلقائيًا عند الساعة 12 صباحًا.',
            ], 429);
        }

        $conversation = AiConversation::create([
            'user_id' => $user->id,
            'title' => \Illuminate\Support\Str::limit($courseFile->title ?: ($mode === 'flashcards' ? 'بطاقات مراجعة' : 'ملخّص ملف'), 60),
            'messages' => [],
            'pinned_course_file_id' => $courseFile->id,
        ]);

        $messageText = $mode === 'flashcards'
            ? 'حوّل أهم نقاط هذا الملف إلى بطاقات مراجعة سريعة (Flashcards) لملف: ' . $courseFile->title . self::FLASHCARDS_FORMAT_INSTRUCTION
            : 'لخّصلي هذا الملف واشرح أهم النقاط فيه: ' . $courseFile->title;

        $this->incrementDailyUsage($user->id);

        $question = AiQuestion::create([
            'user_id' => $user->id,
            'conversation_id' => $conversation->id,
            'message' => $messageText,
            'course_name' => $courseFile->course?->name_ar,
            'status' => 'pending',
            'referenced_course_file_id' => $courseFile->id,
        ]);

        $fileNeedsPrep = !$this->hasFreshMeta($courseFile->id);

        if (!$fileNeedsPrep) {
            $question->setRelation('user', $user);
            $question->setRelation('referencedCourseFile', $courseFile);

            $immediate = $this->tryImmediateAnswer($question, $calculator);

            if ($immediate !== null) {
                $this->appendToConversation($conversation, $messageText, $immediate);

                return response()->json([
                    'question_id' => $question->id,
                    'conversation_id' => $conversation->id,
                    'message_text' => $messageText,
                    'reply' => $immediate,
                    'remaining' => max(0, self::DAILY_LIMIT - $this->dailyUsageCount($user->id)),
                ], 200);
            }
        }

        return response()->json([
            'question_id' => $question->id,
            'conversation_id' => $conversation->id,
            'message_text' => $messageText,
            'remaining' => max(0, self::DAILY_LIMIT - $this->dailyUsageCount($user->id)),
        ], 202);
    }

    private function appendToConversation(AiConversation $conversation, ?string $rawMessage, string $reply): void
    {
        $messages = $conversation->messages ?? [];
        $messages[] = ['role' => 'user', 'text' => $rawMessage ?? ''];
        $messages[] = ['role' => 'assistant', 'text' => $reply];
        $conversation->update(['messages' => $messages]);
    }

    private function hasFreshMeta(int $courseFileId): bool
    {
        return CourseFileAiMeta::where('course_file_id', $courseFileId)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->exists();
    }

    public function result(Request $request, AiQuestion $aiQuestion)
    {
        if ($aiQuestion->user_id !== $request->user()->id) {
            abort(404);
        }

        return response()->json([
            'status' => $aiQuestion->status,
            'reply' => $aiQuestion->reply,
            'message' => $aiQuestion->error_message,
        ]);
    }

    /*
     * لعرض "٥ من ٣٠ اليوم" بالواجهة بلا حاجة لسؤال فعلي — العدّاد
     * نفسه يتصفّر تلقائيًا مع منتصف الليل (راجع dailyUsageKey أعلاه).
     */
    public function usage(Request $request)
    {
        $used = $this->dailyUsageCount($request->user()->id);

        return response()->json([
            'used' => $used,
            'limit' => self::DAILY_LIMIT,
            'remaining' => max(0, self::DAILY_LIMIT - $used),
            'resets_at' => now()->endOfDay()->addSecond()->toIso8601String(),
        ]);
    }

    public function conversations(Request $request)
    {
        $conversations = AiConversation::where('user_id', $request->user()->id)
            ->latest('updated_at')
            ->limit(50)
            ->get(['id', 'title', 'updated_at']);

        return response()->json(['data' => $conversations]);
    }

    public function conversationMessages(Request $request, AiConversation $aiConversation)
    {
        if ($aiConversation->user_id !== $request->user()->id) {
            abort(404);
        }

        return response()->json([
            'id' => $aiConversation->id,
            'title' => $aiConversation->title,
            'messages' => $aiConversation->messages ?? [],
        ]);
    }

    public function feedback(Request $request, AiConversation $aiConversation)
    {
        if ($aiConversation->user_id !== $request->user()->id) {
            abort(404);
        }

        $data = $request->validate([
            'message_index' => ['required', 'integer', 'min:0'],
            'feedback' => ['required', 'in:up,down'],
        ]);

        $messages = $aiConversation->messages ?? [];

        if (!isset($messages[$data['message_index']])) {
            abort(404);
        }

        $messages[$data['message_index']]['feedback'] =
            ($messages[$data['message_index']]['feedback'] ?? null) === $data['feedback']
                ? null
                : $data['feedback'];

        $aiConversation->update(['messages' => $messages]);

        return response()->json(['feedback' => $messages[$data['message_index']]['feedback']]);
    }

    public function processPending(Request $request, PlanCalculator $calculator)
    {
        /* دُفعة قد تحتوي حتى BATCH_SIZE أسئلة ملفات، كل وحدة تحتمل حتى
           BATCH_FILE_TIMEOUT_SECONDS ثانية — لازم مهلة تنفيذ كافية
           لإكمال الدُفعة كلها بلا قتل السكربت بمنتصف الطريق. رفعتها من
           ٢١٠ لـ٢٦٠ لإعطاء هامش أمان إضافي بعد ما صار ملف واحد بالدُفعة
           ممكن يحتاج تجهيزًا جديدًا كاملًا (تحميل من درايف حتى
           DOWNLOAD_TIMEOUT_SECONDS ثانية) بجانب سؤالين آخرين فيهم ملف
           (كل وحدة حتى BATCH_FILE_TIMEOUT_SECONDS) — بلا هامش كافٍ كان
           هذا التزامن بالذات (نادر لكن ممكن) يقترب جدًا من حد ٢١٠. */
        @set_time_limit(260);

        if (!env('AI_CRON_TOKEN') || $request->query('token') !== env('AI_CRON_TOKEN')) {
            abort(403);
        }

        /*
         * خدمة التحديث الدوري المجانية اللي بتدق هالمسار كل دقيقة قد
         * توقف نفسها تلقائيًا لو صار أكثر من عدد معيّن من الردود
         * الفاشلة (500) بلا علمنا — لهيك أي استثناء غير متوقع هنا
         * (قاعدة بيانات، إلخ) لازم يُلتقَط ويرجع 200 دايمًا، لا يترك
         * لارافيل يرجّع 500 خام قد يعطّل الخدمة الخارجية نفسها.
         */
        try {
            /* الأسئلة النصية العادية دايمًا سريعة (مهلتها أصلًا قصيرة) —
               تبقى ٣ دقائق كسقف واقعي. أسئلة الملفات قد تمر بعدة جولات
               متتالية (تجهيز، ثم تقسيم لأجزاء عند الحاجة، ثم تلخيص كل
               جزء بجولة منفصلة، ثم دمج نهائي) — كل جولة ~دقيقة.
               ١٠ دقائق كانت كافية لملف بـ٢-٣ أجزاء بس، لكن ملفًا أكبر
               (مثلًا كتاب ٤٠٠+ صفحة، بمعدّل PAGES_PER_CHUNK=40 صفحة/جزء)
               يحتاج ١٠ أجزاء أو أكثر + جولة دمج أخيرة = ١١ جولة، فكان
               سقف ١٠ دقائق يفشّله بمنتصف الطريق دائمًا مهما نجحت كل
               جولة على حدة — فشل "بسبب الوقت" لا "بسبب الحجم" فعليًا،
               رغم إنه كان يظهر بنفس رسالة الفشل النهائي. ٢٠ دقيقة تعطي
               هامشًا واقعيًا لملفات أكبر بكثير (حتى ~١٨ جزءًا). لازم
               تطابق MAX_POLL_ATTEMPTS_FILE بـapp.js (الواجهة) بنفس
               القيمة، وإلا الواجهة تستسلم قبل الخادم بلا داعٍ. */
            AiQuestion::where('status', 'pending')
                ->whereNull('referenced_course_file_id')
                ->where('created_at', '<', now()->subMinutes(3))
                ->update([
                    'status' => 'failed',
                    'error_message' => 'تعذّر الوصول للمساعد بالوقت المناسب، حاول مرة أخرى.',
                ]);

            AiQuestion::where('status', 'pending')
                ->whereNotNull('referenced_course_file_id')
                ->where('created_at', '<', now()->subMinutes(20))
                ->update([
                    'status' => 'failed',
                    'error_message' => 'تعذّر الوصول للمساعد بالوقت المناسب، حاول مرة أخرى.',
                ]);

            $providers = $this->providerPool();
            $processed = 0;

            /*
             * تجهيز ملف جديد كليًا (تحميل من درايف + رفعه لـGemini، أو
             * إعادة تحميله وتقسيمه لأجزاء) قد يستغرق لوحده عشرات
             * الثواني. لو أكثر من سؤال بنفس الدُفعة (حتى BATCH_SIZE=3)
             * يحتاج هذا التجهيز بنفس اللحظة (طلاب مختلفين سألوا عن
             * ملفين كبيرين مختلفين معًا)، مجموع أوقاتهم قد يقترب من
             * مهلة تنفيذ السكربت الكلية ويعرّض الدُفعة كاملة لخطر
             * التوقف بمنتصف الطريق. نسمح بتجهيز واحد جديد فقط بكل جولة
             * — أي سؤال آخر يحتاج تجهيزًا هذي الجولة يبقى pending
             * بأمان ويُجهَّز بالجولة القادمة (خلال دقيقة تقريبًا)، بدل
             * المخاطرة باستقرار الدُفعة كلها. لا يؤثر هذا على أسئلة
             * ملفات جاهزة مسبقًا (لا تحتاج تجهيزًا) — تُجاب كلها بنفس
             * الجولة كالمعتاد.
             */
            $freshPrepUsed = false;

            if ($providers) {
                $questions = AiQuestion::where('status', 'pending')
                    ->oldest()
                    ->limit(self::BATCH_SIZE)
                    ->with(['attachments', 'user', 'referencedCourseFile'])
                    ->get();

                foreach ($questions as $question) {
                    $this->answerWithFullRetry($question, $providers, $calculator, $freshPrepUsed);
                    $processed++;
                }
            }

            return response()->json(['processed' => $processed]);
        } catch (\Throwable $e) {
            \Log::error('processPending: unexpected failure: ' . $e->getMessage());
            return response()->json(['processed' => 0, 'error' => true]);
        }
    }

    /*
     * صفحة مراقبة بسيطة (JSON) لاستهلاك اليوم لكل مفتاح — تُفتح مباشرة
     * من المتصفح بنفس رابط cron المحمي بنفس رمز AI_CRON_TOKEN، بلا أي
     * حاجة لوحة تحكم أو استضافة إضافية:
     * /api/v1/ai/provider-usage?token=...
     */
    public function providerUsage(Request $request)
    {
        if (!env('AI_CRON_TOKEN') || $request->query('token') !== env('AI_CRON_TOKEN')) {
            abort(403);
        }

        $labels = ['gemini_1', 'gemini_2', 'gemini_3', 'openrouter'];
        $providers = $this->providerPool();
        $activeLabels = array_map(fn ($p) => $this->providerLabel($p), $providers);

        $data = [];
        foreach ($labels as $label) {
            $counts = $this->providerUsageSnapshot($label);
            $data[$label] = [
                'active' => in_array($label, $activeLabels, true),
                'ok' => $counts['ok'],
                'rate_limited' => $counts['rate_limited'],
                'failed' => $counts['failed'],
                'total' => $counts['ok'] + $counts['rate_limited'] + $counts['failed'],
            ];
        }

        return response()->json([
            'date' => now()->toDateString(),
            'resets_at' => now()->endOfDay()->addSecond()->toIso8601String(),
            'providers' => $data,
        ]);
    }

    private function providerPool(): array
    {
        $providers = [];

        foreach (array_filter([
            config('services.gemini.key'),
            env('GEMINI_API_KEY_2'),
            env('GEMINI_API_KEY_3'),
        ]) as $key) {
            $providers[] = ['type' => 'gemini', 'key' => $key];
        }

        if (config('services.openrouter.key')) {
            $providers[] = ['type' => 'openrouter'];
        }

        return $providers;
    }

    private function rotatedProviders(array $providers, int $seed): array
    {
        if (count($providers) <= 1) {
            return $providers;
        }

        $start = $seed % count($providers);

        return array_merge(array_slice($providers, $start), array_slice($providers, 0, $start));
    }

    /*
     * ملف Gemini (مرفق طالب أو ملف مساق مُجهَّز) مربوط بمفتاح الـAPI
     * المحدد اللي رفعه فعليًا — GeminiFileService يستخدم دائمًا المفتاح
     * الأساسي (services.gemini.key) للرفع وللتحقق، بلا أي علاقة بتدوير
     * المزوّدين هنا. لو سؤال فيه ملف أُجيب عليه بمفتاح مختلف بالتدوير
     * (key2/key3)، الإشارة لملف مرفوع بمفتاح غير، فيفشل الطلب دائمًا —
     * هذا بالضبط سبب فشل ميزة "اشرحلي ملف كذا" ومرفقات الطلاب بشكل
     * متقطّع (تنجح فقط صدفةً لو التدوير وقع على المفتاح الأساسي). أي
     * سؤال فيه ملف لازم يُجاب حصرًا بنفس المفتاح الأساسي، بلا تدوير
     * ولا OpenRouter (أصلًا لا يدعم الملفات).
     */
    private function primaryGeminiProvider(array $providers): ?array
    {
        $primaryKey = config('services.gemini.key');

        foreach ($providers as $provider) {
            if ($provider['type'] === 'gemini' && $provider['key'] === $primaryKey) {
                return $provider;
            }
        }

        return null;
    }

    private function tryImmediateAnswer(AiQuestion $question, PlanCalculator $calculator): ?string
    {
        /* ملف مُقسَّم لأجزاء (كان أكبر من حد Gemini الأقصى للتوكنز) —
           لا يُجاب عنه إلا عبر الدُفعة (processPending/handleChunkedFile)
           التي تمرّ بعدة جولات؛ لا تحاول المسار الفوري إطلاقًا، وإلا
           رح يرد بلا أي اطّلاع فعلي على محتوى الملف. */
        if ($question->referenced_course_file_id) {
            $meta = CourseFileAiMeta::where('course_file_id', $question->referenced_course_file_id)->first();
            if ($meta && !empty($meta->chunks_json)) {
                return null;
            }
        }

        $providers = $this->providerPool();
        if (!$providers) {
            return null;
        }

        $systemInstruction = $this->buildSystemInstruction($question, $calculator);
        $filePart = $this->referencedFilePart($question);

        $provider = $filePart
            ? $this->primaryGeminiProvider($providers)
            : $this->rotatedProviders($providers, $question->id)[0];

        if (!$provider) {
            return null;
        }

        $result = $this->callProvider($provider, $question, $systemInstruction, self::IMMEDIATE_TIMEOUT_SECONDS, $filePart);

        if (!$result['ok']) {
            /* الملف يتجاوز الحد الأقصى لعدد الرموز (tokens) اللي يقدر
               Gemini يقرأه بطلب واحد — إعادة المحاولة لن تُغيّر شيئًا
               (حجم الملف ثابت)، فنفشل السؤال فورًا برسالة صريحة بدل ما
               نتركه يعلَّق لحد ما تلتقطه processPending لاحقًا بلا داعٍ. */
            if (!empty($result['too_large'])) {
                $question->update([
                    'status' => 'failed',
                    'error_message' => self::FILE_TOO_LARGE_MESSAGE,
                ]);
            }

            return null;
        }

        $question->update(['status' => 'done', 'reply' => $result['text']]);
        $this->rememberAnswer($question, $result['text']);

        return $result['text'];
    }

    private function answerWithFullRetry(AiQuestion $question, array $providers, PlanCalculator $calculator, bool &$freshPrepUsed): void
    {
        /*
         * ملف مرتبط بالسؤال (referencedCourseFile) يُجهَّز هنا إن لم
         * يكن جاهزًا أصلًا. التجهيز (تحميل من درايف + رفع لـGemini)
         * والإجابة (استدعاء generateContent) بمجموعهما أبطأ من أن
         * يُضمَن اكتمالهما بطلب HTTP واحد على استضافة مشتركة بمهلة
         * تنفيذ صارمة قد لا تحترم set_time_limit(90) أصلًا — فلو صار
         * التجهيز هنا وفشل الطلب يكمل توليد الجواب بنفس الجولة، ممكن
         * يُقتَل السكربت بمنتصف الطريق ويضل السؤال pending للأبد لحد ما
         * يُلغى بعد ٣ دقائق بلا أي محاولة إجابة فعلية أصلًا.
         *
         * الحل: فصل الجولتين. لو الملف يحتاج تجهيزًا الآن، جهّزه بس
         * هالجولة وارجع فورًا (السؤال يضل pending)، والجولة الدُفعية
         * الجاية (خلال دقيقة تقريبًا) تلاقي التجهيز جاهزًا مسبقًا وتكمل
         * توليد الجواب مباشرة بسرعة، بلا انتظار تحميل من جديد.
         */
        $filePart = null;
        if ($question->referenced_course_file_id && $question->referencedCourseFile) {
            $meta = $this->hasFreshMeta($question->referencedCourseFile->id)
                ? CourseFileAiMeta::where('course_file_id', $question->referencedCourseFile->id)->first()
                : null;

            if (!$meta) {
                /* راجع تعليق $freshPrepUsed بـprocessPending أعلاه —
                   تجهيز واحد جديد فقط مسموح بكل جولة دُفعة. */
                if ($freshPrepUsed) {
                    return; // يبقى pending بأمان، يُجهَّز بالجولة القادمة
                }
                $freshPrepUsed = true;

                $prepared = CourseFileGeminiPreparer::fresh($question->referencedCourseFile);

                if (!$prepared) {
                    $question->update([
                        'status' => 'failed',
                        'error_message' => 'ما قدرت أجهّز هذا الملف للمساعد حاليًا (قد يكون رابط غير مدعوم أو كبيرًا جدًا). جرّب تسأل بلا تحديد الملف.',
                    ]);
                }

                /* سواء نجح التجهيز أو فشل، ما نكمل لتوليد الجواب بهالجولة —
                   لو نجح، الجولة الجاية تكمل بسرعة على الملف الجاهز. */
                return;
            }

            /* ملف كان أكبر من حد Gemini الأقصى للتوكنز فاتقسّم لأجزاء
               (راجع CourseFileGeminiPreparer::rebuildAsChunks) — يُجاب
               عنه بمسار منفصل تمامًا (جزء بجزء ثم دمج نهائي)، لا
               بمسار الملف الواحد أدناه. */
            if (!empty($meta->chunks_json)) {
                $this->handleChunkedFile($question, $meta, $calculator);
                return;
            }

            $filePart = [
                'uri' => $meta->google_file_uri,
                /* النوع اللي Gemini نفسه أكّده وقت الرفع — لا تخمين
                   جديد هنا، هذا بالضبط ما كان يسبب رفض 400. */
                'mimeType' => $meta->google_mime_type ?: ($question->referencedCourseFile->mime_type ?: 'application/pdf'),
            ];
        }

        $systemInstruction = $this->buildSystemInstruction($question, $calculator);
        $hasFile = $filePart !== null || $question->attachments->isNotEmpty();

        if ($hasFile) {
            $primary = $this->primaryGeminiProvider($providers);
            $ordered = $primary ? [$primary] : [];
        } else {
            $ordered = $this->rotatedProviders($providers, $question->id);
        }

        /* الأسئلة المرفق فيها ملف تحتاج مهلة أطول بكثير من النصية
           العادية — راجع تعليق BATCH_FILE_TIMEOUT_SECONDS أعلاه. */
        $timeoutSeconds = $hasFile ? self::BATCH_FILE_TIMEOUT_SECONDS : self::BATCH_TIMEOUT_SECONDS;

        $wasRateLimited = false;
        $tooLarge = false;

        foreach ($ordered as $provider) {
            $result = $this->callProvider($provider, $question, $systemInstruction, $timeoutSeconds, $filePart);

            if ($result['rate_limited']) {
                $wasRateLimited = true;
            }
            if (!empty($result['too_large'])) {
                $tooLarge = true;
            }

            if (!$result['ok']) {
                continue;
            }

            $question->update(['status' => 'done', 'reply' => $result['text']]);
            $this->rememberAnswer($question, $result['text']);

            $conversation = $question->conversation_id ? AiConversation::find($question->conversation_id) : null;
            if ($conversation) {
                $messages = $conversation->messages ?? [];
                $messages[] = [
                    'role' => 'user',
                    'text' => $question->message,
                    'attachments' => $question->attachments->map(fn ($a) => [
                        'id' => $a->id, 'name' => $a->display_name, 'mime_type' => $a->mime_type, 'size_bytes' => $a->size_bytes,
                    ])->values()->all(),
                ];
                $messages[] = ['role' => 'assistant', 'text' => $result['text']];
                $conversation->update(['messages' => $messages]);
            }

            return;
        }

        if ($tooLarge) {
            /* أول مرة يتجاوز فيها ملف مساق (لا مرفق طالب) الحد الأقصى،
               نجرّب تقسيمه لأجزاء أصغر بدل الاستسلام فورًا — الجولة
               الجاية تلخّص الأجزاء واحدًا واحدًا (handleChunkedFile). */
            if (
                $question->referenced_course_file_id
                && $question->referencedCourseFile
                && isset($meta) && $meta && empty($meta->chunks_json)
            ) {
                /* راجع تعليق $freshPrepUsed بـprocessPending أعلاه —
                   إعادة التحميل والتقسيم هنا "تجهيز جديد" بنفس معنى
                   الفرع أعلاه، يخضع لنفس حد "واحد بكل جولة". */
                if ($freshPrepUsed) {
                    return; // يبقى pending بأمان، تُجرَّب إعادة التقسيم بالجولة القادمة
                }
                $freshPrepUsed = true;

                $rebuilt = CourseFileGeminiPreparer::rebuildAsChunks($question->referencedCourseFile);
                if ($rebuilt) {
                    return; // يبقى pending — الجولة الجاية تبلّش تلخّص الأجزاء
                }
            }

            /* تجاوز حجم الملف الحد الأقصى ولا يمكن تقسيمه (أو التقسيم
               نفسه فشل) — راجع تعليق FILE_TOO_LARGE_MESSAGE
               وtryImmediateAnswer أعلاه؛ نفس المنطق هنا لمسار الدُفعة. */
            $question->update([
                'status' => 'failed',
                'error_message' => self::FILE_TOO_LARGE_MESSAGE,
            ]);
            return;
        }

        $question->increment('attempts');

        if ($wasRateLimited && $question->attempts < 5) {
            return;
        }

        $question->update([
            'status' => 'failed',
            'error_message' => 'تعذّر الوصول للمساعد حاليًا، حاول لاحقًا.',
        ]);
    }

    /*
     * ملف مساق فاق حجمه حد Gemini الأقصى للتوكنز فاتقسّم لأجزاء أصغر
     * (راجع CourseFileGeminiPreparer::rebuildAsChunks). كل جزء يُلخَّص
     * لحاله بجولة processPending منفصلة (جزء واحد بالجولة، لا كل
     * الأجزاء دفعة وحدة، احترامًا لمهلة تنفيذ السكربت)، وبعد ما تخلص
     * كل الأجزاء، جولة أخيرة تدمجها بتلخيص واحد متكامل — هاي الوحيدة
     * يلي فعليًا تجاوب على سؤال الطالب (status=done).
     *
     * التلخيصات الجزئية تُخزَّن بـcourse_file_ai_meta.chunks_json —
     * أي طالب آخر يسأل عن نفس الملف لاحقًا يستفيد منها فورًا (لا حاجة
     * لإعادة تلخيص الأجزاء من جديد)، تمامًا متل تخزين الملف الجاهز
     * بالمسار العادي.
     */
    private function handleChunkedFile(AiQuestion $question, CourseFileAiMeta $meta, PlanCalculator $calculator): void
    {
        $data = json_decode((string) $meta->chunks_json, true);
        $parts = $data['parts'] ?? null;

        if (!$parts) {
            $question->update([
                'status' => 'failed',
                'error_message' => self::FILE_TOO_LARGE_MESSAGE,
            ]);
            return;
        }

        $provider = $this->primaryGeminiProvider($this->providerPool());
        if (!$provider) {
            return; // يبقى pending، يُحاول الجولة الجاية
        }

        $pendingIndex = null;
        foreach ($parts as $i => $part) {
            if ($part['summary'] === null) {
                $pendingIndex = $i;
                break;
            }
        }

        if ($pendingIndex !== null) {
            $part = $parts[$pendingIndex];
            $partInstruction = 'هذا جزء رقم ' . ($pendingIndex + 1) . ' من ' . count($parts)
                . ' من ملف أكبر بعنوان: ' . $question->referencedCourseFile->title
                . '. لخّص محتوى هذا الجزء تحديدًا بدقة، واذكر أهم النقاط والمفاهيم فيه بلا أي مقدمة أو خاتمة —'
                . ' سيُدمج هذا التلخيص لاحقًا مع تلخيصات بقية الأجزاء بتلخيص نهائي واحد.';

            $filePart = ['uri' => $part['google_file_uri'], 'mimeType' => $part['google_mime_type']];
            $result = $this->callProvider($provider, $question, $partInstruction, self::BATCH_FILE_TIMEOUT_SECONDS, $filePart);

            if ($result['ok']) {
                $parts[$pendingIndex]['summary'] = $result['text'];
                $meta->update(['chunks_json' => json_encode(['total' => count($parts), 'parts' => $parts], JSON_UNESCAPED_UNICODE)]);
            }

            /* سواء نجح تلخيص هذا الجزء أو فشل، ما نكمل لسؤال الطالب —
               الجولة الجاية تجرّب الجزء نفسه (لو فشل) أو التالي. */
            return;
        }

        // كل الأجزاء عندها تلخيص جاهز — ندمجها بطلب نصي أخير خفيف (بلا أي ملف).
        $combined = 'فيما يلي تلخيصات أجزاء منفصلة من ملف بعنوان "' . $question->referencedCourseFile->title . "\"، بالترتيب:\n\n";
        foreach ($parts as $i => $part) {
            $combined .= '-- الجزء ' . ($i + 1) . " --\n" . $part['summary'] . "\n\n";
        }
        $combined .= 'ادمج هذي التلخيصات الجزئية بتلخيص واحد متكامل ومترابط للملف كاملًا، بلا تكرار وبلا ذكر أنها كانت أجزاء منفصلة.';

        /*
         * سؤال "بطاقات مراجعة" الأصلي (راجع summarizeFile أعلاه) يضيف
         * FLASHCARDS_FORMAT_INSTRUCTION لنص $question->message المخزَّن
         * — لكن هذا المسار (دمج الأجزاء) يبني نصًّا جديدًا كليًا
         * ($combined) لا يمرّ إطلاقًا على $question->message، فكانت
         * تعليمة التنسيق (س:/ج:/---) تُفقَد تمامًا بالجولة النهائية،
         * فيرجع الدمج نصًّا عاديًا حتى لو كل جزء لُخِّص بنجاح — بالضبط
         * السبب اللي وثّقناه بالفحص الحي (خطوة ٧٥، الجولة الثانية).
         * الحل: لو السؤال الأصلي كان طلب بطاقات مراجعة (نتعرّف عليه من
         * نص الرسالة المخزَّنة نفسها، بلا أي عمود جديد بقاعدة البيانات)،
         * نعيد إلحاق نفس تعليمة التنسيق هنا كمان قبل طلب الدمج.
         */
        if (str_contains($question->message ?? '', 'بطاقات مراجعة')) {
            $combined .= self::FLASHCARDS_FORMAT_INSTRUCTION;
        }

        $systemInstruction = $this->buildSystemInstruction($question, $calculator);
        $result = $this->callProvider($provider, $question, $systemInstruction, self::BATCH_FILE_TIMEOUT_SECONDS, null, $combined);

        if (!$result['ok']) {
            $question->increment('attempts');
            if ($question->attempts < 5) {
                return; // نعطي محاولات إضافية — دمج نصي بسيط، الفشل هنا غالبًا مؤقت
            }
            $question->update([
                'status' => 'failed',
                'error_message' => 'تعذّر الوصول للمساعد حاليًا، حاول لاحقًا.',
            ]);
            return;
        }

        $question->update(['status' => 'done', 'reply' => $result['text']]);
        $this->rememberAnswer($question, $result['text']);

        $conversation = $question->conversation_id ? AiConversation::find($question->conversation_id) : null;
        if ($conversation) {
            $messages = $conversation->messages ?? [];
            $messages[] = ['role' => 'user', 'text' => $question->message];
            $messages[] = ['role' => 'assistant', 'text' => $result['text']];
            $conversation->update(['messages' => $messages]);
        }
    }

    /*
     * جزء الملف الجاهز مسبقًا فقط (لا يُجهَّز شيء هنا) — يُستخدم من
     * المسار الفوري، الذي أصلًا يُستبعَد عنه أي سؤال يحتاج تجهيزًا
     * جديدًا (راجع fileNeedsPrep بدالة ask).
     */
    private function referencedFilePart(AiQuestion $question): ?array
    {
        if (!$question->referenced_course_file_id) {
            return null;
        }

        $file = $question->relationLoaded('referencedCourseFile')
            ? $question->referencedCourseFile
            : $question->referencedCourseFile()->first();

        if (!$file) {
            return null;
        }

        $meta = CourseFileAiMeta::where('course_file_id', $file->id)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->first();

        if (!$meta) {
            return null;
        }

        return ['uri' => $meta->google_file_uri, 'mimeType' => $meta->google_mime_type ?: ($file->mime_type ?: 'application/pdf')];
    }

    private function rememberAnswer(AiQuestion $question, string $text): void
    {
        if ($question->referenced_course_file_id || $question->attachments()->exists()) {
            return; /* إجابة مرتبطة بملف بعينه — لا تُخزَّن كإجابة عامة قابلة لإعادة الاستخدام لأي سؤال مشابه نصيًا. */
        }

        AiAnswerCache::updateOrCreate(
            [
                'question_hash' => hash('sha256', $this->normalize($question->message)),
                'user_id' => $question->user_id,
            ],
            ['question_sample' => $question->message, 'reply' => $text]
        );
    }

    private function buildSystemInstruction(AiQuestion $question, PlanCalculator $calculator): string
    {
        $base = 'أنت مساعد مذاكرة لطلاب هندسة أنظمة الحاسوب بكلية فلسطين التقنية. '
            . 'أجب بالعربية الفصحى المبسّطة ما لم يطلب المستخدم غير ذلك، بإيجاز ووضوح، '
            . 'وركّز على شرح المفاهيم البرمجية والهندسية بأمثلة عملية عند الإمكان. '
            . 'لا تجب عن أسئلة خارج نطاق الدراسة الهندسية أو التقنية.'
            . ($question->course_name ? ' الطالب حاليًا يستعرض مادة: ' . $question->course_name . '.' : '');

        $file = $question->relationLoaded('referencedCourseFile')
            ? $question->referencedCourseFile
            : ($question->referenced_course_file_id ? $question->referencedCourseFile()->first() : null);

        if ($file) {
            $base .= " ملف مرفق بهذا السؤال تحديدًا بعنوان: {$file->title}. اعتمد على محتواه الفعلي بإجابتك، لا على عنوانه فقط.";
        } elseif ($this->looksLikeFileRequest($question->message)) {
            /* يبدو أنه يسأل عن ملف بعينه لكن لم تنجح أي مطابقة — تعليمة
               صريحة بعدم الادّعاء أو اختلاق محتوى ملف غير موجود. */
            $base .= ' يبدو أن الطالب يسأل عن ملف بعينه، لكن لم يُعثر على ملف منشور بهذا الاسم بالضبط — قل له صراحةً إنك لم تجد ملفًا بهذا الاسم، واطلب منه التأكد من العنوان أو المادة، ولا تخترع أي محتوى لملف غير موجود.';
        }

        $user = $question->relationLoaded('user') ? $question->user : $question->user()->first();

        if (!$user) {
            return $base;
        }

        return $base . "\n\n" . StudentContextBuilder::build($user, $calculator);
    }

    private function looksLikeFileRequest(?string $message): bool
    {
        if (!$message) {
            return false;
        }

        $normalized = $this->normalize($message);
        foreach (self::FILE_INTENT_HINTS as $hint) {
            if (str_contains($normalized, $this->normalize($hint))) {
                return true;
            }
        }

        return false;
    }

    private function callProvider(array $provider, AiQuestion $question, string $systemInstruction, int $timeoutSeconds, ?array $filePart, ?string $messageOverride = null): array
    {
        $label = $this->providerLabel($provider);

        $result = $provider['type'] === 'openrouter'
            ? $this->callOpenRouter($question, $systemInstruction, $timeoutSeconds, $filePart)
            : $this->callGemini($provider['key'], $question, $systemInstruction, $timeoutSeconds, $filePart, $messageOverride);

        $this->incrementProviderUsage($label, $result['ok'] ? 'ok' : ($result['rate_limited'] ? 'rate_limited' : 'failed'));
        \Log::info("AI provider [{$label}] question #{$question->id}: " . ($result['ok'] ? 'ok' : ($result['rate_limited'] ? 'rate_limited' : 'failed')));

        return $result;
    }

    private function callGemini(string $apiKey, AiQuestion $question, string $systemInstruction, int $timeoutSeconds, ?array $filePart, ?string $messageOverride = null): array
    {
        try {
            /* messageOverride: نص جاهز يحل محل رسالة السؤال المخزّنة —
               تحديدًا لخطوة دمج تلخيصات الأجزاء (handleChunkedFile)،
               بلا أي تعديل فعلي على $question->message المحفوظة
               (لازم تبقى كما هي لعرضها بالمحادثة). */
            $parts = [['text' => $messageOverride ?? ($question->message ?: 'حلّل المرفق المرفوع وأجبني بما يفيدني دراسيًا.')]];
            foreach ($question->attachments as $attachment) {
                $parts[] = ['file_data' => [
                    'mime_type' => $attachment->mime_type,
                    'file_uri' => $attachment->provider_uri,
                ]];
            }
            if ($filePart) {
                $parts[] = ['file_data' => [
                    'mime_type' => $filePart['mimeType'],
                    'file_uri' => $filePart['uri'],
                ]];
            }

            $response = Http::timeout($timeoutSeconds)
                ->withHeaders(['x-goog-api-key' => $apiKey])
                ->post(
                    'https://generativelanguage.googleapis.com/v1beta/models/' . self::GEMINI_MODEL . ':generateContent',
                    [
                        'systemInstruction' => ['parts' => [['text' => $systemInstruction]]],
                        'contents' => [['role' => 'user', 'parts' => $parts]],
                        'generationConfig' => ['maxOutputTokens' => 3000, 'temperature' => 0.6],
                    ]
                );
        } catch (\Throwable $e) {
            \Log::error('Gemini call failed (exception): ' . $e->getMessage());
            return ['ok' => false, 'text' => null, 'rate_limited' => false];
        }

        if ($response->status() === 429) {
            \Log::warning('Gemini key hit quota, trying next provider if available.');
            return ['ok' => false, 'text' => null, 'rate_limited' => true];
        }

        if (!$response->successful()) {
            $body = $response->body();
            \Log::error('Gemini call failed (status ' . $response->status() . '): ' . $body);

            /* ملف يتجاوز الحد الأقصى لعدد الرموز (tokens) اللي يقدر
               Gemini يقرأه دفعة واحدة (١٫٠٤٨٫٥٧٦ توكن) — خطأ 400 واضح
               ومباشر من جوجل نفسها، لا Timeout ولا عطل مؤقت. تمييزه
               هنا يسمح بإعطاء الطالب رسالة صريحة بدل رسالة عامة
               مضلِّلة، ويمنع إهدار محاولات إعادة لن تُغيّر شيئًا. */
            $tooLarge = $response->status() === 400
                && str_contains($body, 'exceeds the maximum number of tokens');

            return ['ok' => false, 'text' => null, 'rate_limited' => false, 'too_large' => $tooLarge];
        }

        $parts = $response->json('candidates.0.content.parts') ?? [];
        $text = collect($parts)->pluck('text')->filter()->implode('');

        if (!$text) {
            \Log::error('Gemini call returned no text. Full response: ' . $response->body());
            return ['ok' => false, 'text' => null, 'rate_limited' => false];
        }

        return ['ok' => true, 'text' => $text, 'rate_limited' => false];
    }

    /*
     * OpenRouter لا يدعم مرفقات الطالب ولا ملفات Gemini File URIs —
     * يُتخطّى فورًا لأي سؤال فيه أحدهما، بدل محاولة استدعاء عاجز عن
     * تلبيته أصلًا.
     */
    private function callOpenRouter(AiQuestion $question, string $systemInstruction, int $timeoutSeconds, ?array $filePart): array
    {
        if ($question->attachments->isNotEmpty() || $filePart) {
            return ['ok' => false, 'text' => null, 'rate_limited' => false];
        }

        try {
            $response = Http::timeout($timeoutSeconds)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . config('services.openrouter.key'),
                    'X-Title' => 'PTC Hub Assistant',
                ])
                ->post('https://openrouter.ai/api/v1/chat/completions', [
                    'model' => 'openrouter/free',
                    'messages' => [
                        ['role' => 'system', 'content' => $systemInstruction],
                        ['role' => 'user', 'content' => $question->message ?: 'مرحبًا'],
                    ],
                ]);
        } catch (\Throwable $e) {
            \Log::error('OpenRouter call failed (exception): ' . $e->getMessage());
            return ['ok' => false, 'text' => null, 'rate_limited' => false];
        }

        if ($response->status() === 429) {
            return ['ok' => false, 'text' => null, 'rate_limited' => true];
        }

        if (!$response->successful()) {
            \Log::error('OpenRouter call failed (status ' . $response->status() . '): ' . $response->body());
            return ['ok' => false, 'text' => null, 'rate_limited' => false];
        }

        $text = $response->json('choices.0.message.content');

        if (!$text) {
            return ['ok' => false, 'text' => null, 'rate_limited' => false];
        }

        return ['ok' => true, 'text' => $text, 'rate_limited' => false];
    }
}