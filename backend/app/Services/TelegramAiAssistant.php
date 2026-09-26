<?php

namespace App\Services;

use App\Http\Controllers\Api\V1\AiAssistantController;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/*
 * مساعد تيليجرام الذكي — أول نسخة، عمدًا أبسط بكثير من محرّك الموقع
 * الكامل (AiAssistantController، 1400+ سطر). ما لمسنا ذاك الملف
 * إطلاقًا (غير كلمة رؤية DAILY_LIMIT) — بدل ما نُدخل تعقيد البوت
 * (تنزيل من تيليجرام، تنظيف ملفات مؤقتة...) على أعقد وأكثر ملف حسّاس
 * بالمشروع (تاريخ موثّق من أعطال: قفل متبادل، أخطاء تقسيم، تسريب
 * موارد — راجع الخطوات ٧١/٧٥/٧٦).
 *
 * القيود المتعمّدة بهذه النسخة الأولى (بلا أي تقسيم لأجزاء):
 * - صورة واحدة أو ملف واحد بكل رسالة، لا محادثة متعددة الملفات.
 * - لا حفظ بقاعدة البيانات (لا AiQuestion ولا AiAttachment) — كل شي
 *   بذاكرة الطلب نفسه، يُرفع لـGemini ويُحذف من عنده فور الجواب.
 * - يشارك نفس سقف الاستخدام اليومي (30) مع مساعد الموقع بالضبط، عبر
 *   نفس صيغة مفتاح الـCache تمامًا (dailyUsageKey بذاك الملف) —
 *   طالب يستهلك من نفس الرصيد سواء استخدم الموقع أو البوت.
 * - ملف كبير جدًا أو معقّد لدرجة يحتاج تقسيم؟ يفشل برسالة واضحة
 *   ("جرّب صفحة أصغر") بدل محاولة بناء نفس منطق التقسيم المعقّد هون.
 *
 * "القائمة الذكية" (لاحقًا): أربع أدوات تشارك نفس السقف اليومي ونفس
 * البنية (callGemini خاصة واحدة، تعليمة نظام مختلفة لكل أداة) —
 * summarizeFile (تلخيص صورة/ملف)، askText (سؤال حر)، debugCode
 * (مصحّح أكواد)، generateQuiz (مولّد أسئلة). الأداة المختارة حاليًا
 * تُخزَّن بعمود telegram_links.mode ويقرأها الـwebhook فقط
 * (TelegramWebhookController) — هاي الخدمة نفسها ما إلها علاقة
 * بالتخزين، كل دالة هون مستقلة تمامًا عن "الوضع".
 */
class TelegramAiAssistant
{
    private const MAX_FILE_SIZE = 15 * 1024 * 1024; // 15MB — كافٍ لصورة/صفحة، وأصغر من حد الموقع عمدًا.

    public function __construct(
        private readonly GeminiFileService $files,
    ) {
    }

    public function remainingToday(User $user): int
    {
        return max(0, AiAssistantController::DAILY_LIMIT - $this->dailyUsageCount($user->id));
    }

    /**
     * @throws RuntimeException برسالة عربية جاهزة للعرض على الطالب مباشرة.
     */
    public function summarizeFile(User $user, string $localPath, string $mimeType, string $displayName): string
    {
        if ($this->dailyUsageCount($user->id) >= AiAssistantController::DAILY_LIMIT) {
            throw new RuntimeException(
                'وصلت الحد الأقصى للأسئلة اليوم (' . AiAssistantController::DAILY_LIMIT . '). سيتجدّد تلقائيًا الساعة ١٢ منتصف الليل.'
            );
        }

        $size = filesize($localPath) ?: 0;
        if ($size <= 0 || $size > self::MAX_FILE_SIZE) {
            @unlink($localPath);
            throw new RuntimeException('الملف كبير جدًا للبوت حاليًا (الحد ١٥ ميغابايت) — جرّب صورة أوضح لصفحة وحدة، أو من الموقع مباشرة للملفات الكبيرة.');
        }

        $geminiFile = null;

        try {
            $geminiFile = $this->files->uploadFile($localPath, $displayName, $mimeType);

            $text = $this->generate($geminiFile['uri'], $mimeType);

            $this->incrementDailyUsage($user->id);

            return $text;
        } finally {
            @unlink($localPath);

            if ($geminiFile && ! empty($geminiFile['name'])) {
                $this->files->deleteFile($geminiFile['name']);
            }
        }
    }

    private function generate(string $fileUri, string $mimeType): string
    {
        $systemInstruction =
            'أنت مساعد أكاديمي لطلاب هندسة أنظمة الحاسوب بكلية فلسطين التقنية. ' .
            'لخّص محتوى الصورة أو الملف المرفق بشكل واضح ومركّز يفيد الطالب '.
            'للمذاكرة، بالعربية الفصحى البسيطة، بدون مقدمات طويلة.';

        return $this->callGemini($systemInstruction, [
            ['text' => 'لخّصلي هذا المحتوى.'],
            ['file_data' => ['mime_type' => $mimeType, 'file_uri' => $fileUri]],
        ], tooLargeMessage: 'هذا الملف كبير جدًا على المساعد يقرأه دفعة وحدة. جرّب صفحة أو جزء أصغر.', emptyMessage: 'ما قدر المساعد يطلع بردّ لهذا الملف، جرّب صورة أوضح.');
    }

    /**
     * سؤال نصي حر (بلا صورة/ملف) — يشارك نفس السقف اليومي ونفس نموذج
     * Gemini المستخدَم للتلخيص، لكن بتعليمة نظام مختلفة تناسب أسئلة
     * وأجوبة قصيرة بدل تلخيص ملف. عمدًا بلا أي سياق محادثة سابقة (لا
     * حفظ بقاعدة بيانات) — كل سؤال مستقل بذاته، تمامًا كتلخيص الملف.
     *
     * @throws RuntimeException برسالة عربية جاهزة للعرض على الطالب مباشرة.
     */
    public function askText(User $user, string $question): string
    {
        if ($this->dailyUsageCount($user->id) >= AiAssistantController::DAILY_LIMIT) {
            throw new RuntimeException(
                'وصلت الحد الأقصى للأسئلة اليوم (' . AiAssistantController::DAILY_LIMIT . '). سيتجدّد تلقائيًا الساعة ١٢ منتصف الليل.'
            );
        }

        $systemInstruction =
            'أنت مساعد أكاديمي لطلاب هندسة أنظمة الحاسوب بكلية فلسطين التقنية. ' .
            'جاوب على سؤال الطالب بشكل واضح ومختصر ومفيد، بالعربية الفصحى البسيطة. ' .
            'أنت مؤهَّل تمامًا لشرح مواضيع هندسة الحاسوب المتخصصة أيضًا — مثل الدوائر المنطقية ' .
            '(Logic Gates)، مخططات التوقيت (Timing Diagrams)، معمارية الحاسوب، الأنظمة الرقمية، ' .
            'شبكات الحاسوب، وأنظمة التشغيل — لا تعتذر عن هذي المواضيع، هي صميم تخصص الطالب. ' .
            'لو السؤال غير أكاديمي إطلاقًا (مثلًا شخصي أو ترفيهي بحت)، اعتذر بلطف وقول إنك مخصص للمساعدة الأكاديمية فقط.';

        $text = $this->callGemini($systemInstruction, [
            ['text' => $question],
        ], tooLargeMessage: 'السؤال طويل جدًا، جرّب تختصره.', emptyMessage: 'ما قدر المساعد يطلع بجواب على هذا السؤال، جرّب صياغة مختلفة.');

        $this->incrementDailyUsage($user->id);

        return $text;
    }

    // عام (لا private) حتى يقدر TelegramWebhookController يتحقق من حجم ملف الكود قبل ما يحمّله أصلًا.
    public const MAX_CODE_FILE_SIZE = 300 * 1024; // 300KB — كافٍ لأي ملف كود طالب فعلي (html/css/js/php...).

    /*
     * وضع "مصحّح أكواد" بالقائمة الذكية — الطالب يبعت كود كنص عادي أو
     * كملف (html/css/js/php...)، والمساعد يرجّع جوابين منفصلين:
     * ملاحظات نصية قصيرة + الكود المعدَّل كاملًا (كملف — راجع
     * sendDebugResult بالـwebhook).
     *
     * جرّبنا أول نسخة بطلب JSON منظّم من Gemini بنداء واحد (responseSchema)
     * لفصل الحقلين، لكنها فشلت عمليًا (رد فاضي/غير قابل للتحليل) —
     * فرجعنا لنداءين نصّيين عاديين منفصلين تمامًا، بنفس أسلوب
     * callGemini() الموثوق المستخدم أصلًا بباقي الأدوات (summarizeFile/
     * askText/generateQuiz) — أبسط وأكثر ثباتًا، بس كلفته نداء Gemini
     * إضافي واحد لكل استخدام (يُحتسب كاستهلاك واحد من السقف اليومي
     * رغم النداءين، عبر incrementDailyUsage() مرة واحدة بالنهاية).
     *
     * @return array{notes: string, fixed_code: string}
     * @throws RuntimeException برسالة عربية جاهزة للعرض على الطالب مباشرة.
     */
    public function debugCode(User $user, string $code): array
    {
        if ($this->dailyUsageCount($user->id) >= AiAssistantController::DAILY_LIMIT) {
            throw new RuntimeException(
                'وصلت الحد الأقصى للأسئلة اليوم (' . AiAssistantController::DAILY_LIMIT . '). سيتجدّد تلقائيًا الساعة ١٢ منتصف الليل.'
            );
        }

        $notesInstruction =
            'أنت مساعد برمجي لطلاب هندسة أنظمة الحاسوب. الطالب رح يبعتلك كود برمجي (بأي لغة، مثل ' .
            'HTML/CSS/JavaScript/PHP/Python/C/C++/Java وغيرها، وأيضًا لغات وصف عتاد رقمي مثل ' .
            'Verilog وVHDL، أو كود Assembly — كلها ضمن تخصصك وأنت مؤهَّل تحليلها بنفس الطريقة). ' .
            'حدد الأخطاء البرمجية أو المنطقية إن وجدت بوضوح ' .
            'ونقطة نقطة، أو قول بصراحة إنه الكود سليم مع اقتراح تحسينات بسيطة إن وجدت (تسمية متغيرات، ' .
            'كفاءة...). جاوب بالعربية الفصحى البسيطة، بدون تنسيق Markdown، وبدون كتابة الكود كاملًا ' .
            'بردّك (فقط اشرح الملاحظات — الكود المعدَّل رح يُطلب منك بشكل منفصل).';

        $notes = $this->callGemini($notesInstruction, [
            ['text' => "حلّل هذا الكود:\n\n" . $code],
        ], tooLargeMessage: 'الكود طويل جدًا، جرّب تبعت جزء أصغر منه.', emptyMessage: 'ما قدر المساعد يحلل هذا الكود، جرّب تبعته مرة ثانية.');

        $codeInstruction =
            'أنت مساعد برمجي. الطالب رح يبعتلك كود برمجي. رجّع فقط وحصرًا الكود كاملًا بعد تصحيح أي ' .
            'أخطاء برمجية أو منطقية فيه — لو الكود أصلًا سليم رجّعه كما هو بدون أي تغيير. ' .
            'ممنوع تكتب أي شرح أو مقدمة أو خاتمة أو علامات Markdown مثل ```، فقط الكود نفسه.';

        $fixedCode = $this->callGemini($codeInstruction, [
            ['text' => $code],
        ], tooLargeMessage: 'الكود طويل جدًا، جرّب تبعت جزء أصغر منه.', emptyMessage: 'ما قدر المساعد يطلع بنسخة معدّلة من هذا الكود، جرّب مرة أخرى.');

        $this->incrementDailyUsage($user->id);

        return [
            'notes' => $notes,
            'fixed_code' => $this->stripMarkdownCodeFence($fixedCode),
        ];
    }

    /*
     * تنظيف احترازي: بعض النماذج بتحيط الكود بعلامات Markdown
     * (```php ... ```) حتى لو طُلب منها صراحة عدم فعل هيك — نشيلها
     * هون قبل ما نحفظ الكود بملف حتى ما يوصل الملف فيه هالعلامات.
     */
    private function stripMarkdownCodeFence(string $text): string
    {
        $trimmed = trim($text);

        if (str_starts_with($trimmed, '```')) {
            $trimmed = preg_replace('/^```[a-zA-Z0-9_+-]*\n?/', '', $trimmed, 1) ?? $trimmed;
            $trimmed = preg_replace('/```\s*$/', '', $trimmed, 1) ?? $trimmed;
        }

        return trim($trimmed);
    }

    /*
     * وضع "مولّد أسئلة" بالقائمة الذكية — الطالب يبعت اسم موضوع أو
     * مفهوم دراسي كنص، والمساعد يولّد له أسئلة اختيار من متعدد
     * للمراجعة الذاتية. نفس السقف اليومي المشترك.
     *
     * @throws RuntimeException برسالة عربية جاهزة للعرض على الطالب مباشرة.
     */
    public function generateQuiz(User $user, string $topic): string
    {
        if ($this->dailyUsageCount($user->id) >= AiAssistantController::DAILY_LIMIT) {
            throw new RuntimeException(
                'وصلت الحد الأقصى للأسئلة اليوم (' . AiAssistantController::DAILY_LIMIT . '). سيتجدّد تلقائيًا الساعة ١٢ منتصف الليل.'
            );
        }

        $systemInstruction =
            'أنت مساعد أكاديمي لطلاب هندسة أنظمة الحاسوب. الطالب رح يبعتلك اسم موضوع أو مفهوم دراسي. ' .
            'ولّد له بالضبط 5 أسئلة اختيار من متعدد (كل سؤال 4 خيارات (أ/ب/ج/د)) لمراجعة هذا الموضوع، ' .
            'واكتب بنهاية الرسالة قسم منفصل بعنوان "الإجابات الصحيحة" فيه رقم كل سؤال وحرف إجابته الصحيحة فقط. ' .
            'جاوب بالعربية الفصحى البسيطة، وبدون تنسيق Markdown (رسائل تيليجرام هون HTML لا Markdown).';

        $text = $this->callGemini($systemInstruction, [
            ['text' => 'ولّدلي أسئلة مراجعة عن: ' . $topic],
        ], tooLargeMessage: 'اسم الموضوع طويل جدًا، جرّب تختصره.', emptyMessage: 'ما قدر المساعد يولّد أسئلة لهذا الموضوع، جرّب صياغة مختلفة.');

        $this->incrementDailyUsage($user->id);

        return $text;
    }

    private function callGemini(string $systemInstruction, array $parts, string $tooLargeMessage, string $emptyMessage): string
    {
        $apiKey = config('services.gemini.key');

        if (! $apiKey) {
            throw new RuntimeException('المساعد الذكي غير مفعّل حاليًا على الخادم.');
        }

        $response = Http::timeout(45)
            ->withHeaders(['x-goog-api-key' => $apiKey])
            ->post(
                'https://generativelanguage.googleapis.com/v1beta/models/'
                    . AiAssistantController::GEMINI_MODEL . ':generateContent',
                [
                    'systemInstruction' => ['parts' => [['text' => $systemInstruction]]],
                    'contents' => [[
                        'role' => 'user',
                        'parts' => $parts,
                    ]],
                    'generationConfig' => ['maxOutputTokens' => 1500, 'temperature' => 0.6],
                ]
            );

        if ($response->status() === 429) {
            throw new RuntimeException('المساعد الذكي مزدحم حاليًا، جرّب بعد شوي.');
        }

        if (! $response->successful()) {
            report(new RuntimeException('Telegram AI Gemini call failed: ' . $response->body()));

            $tooLarge = $response->status() === 400
                && str_contains($response->body(), 'exceeds the maximum number of tokens');

            throw new RuntimeException($tooLarge ? $tooLargeMessage : 'تعذّر تحليل الطلب حاليًا، جرّب مرة أخرى بعد شوي.');
        }

        $responseParts = $response->json('candidates.0.content.parts') ?? [];
        $text = collect($responseParts)->pluck('text')->filter()->implode('');

        if (! $text) {
            throw new RuntimeException($emptyMessage);
        }

        return $text;
    }

    /*
     * ⚠ نفس صيغة مفتاح الـCache الموجودة بـAiAssistantController
     * بالحرف (dailyUsageKey/dailyUsageCount/incrementDailyUsage هناك
     * private) — يُقرأ ويُكتب هون بنفس الصيغة عمدًا حتى يشارك البوت
     * والموقع نفس الرصيد اليومي فعليًا لا رصيدين منفصلين بالغلط.
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
}
