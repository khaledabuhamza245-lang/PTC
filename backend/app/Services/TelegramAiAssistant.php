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

    /*
     * أي ملف بهذا الحجم أو أصغر يُرسَل لـGemini كبيانات inline ضمن نداء
     * التلخيص نفسه (سطر واحد شبكيًا) بدل رفعه أولًا لواجهة Files API
     * ثم انتظار "تجهيزه" (uploadFile()+getFile() بـGeminiFileService) —
     * هذا هو التسريع الفعلي المطلوب، لا مجرد رفع سقف وقت التنفيذ: يلغي
     * جولة شبكة كاملة (رفع + استطلاع كل نصف ثانية) من كل رد تقريبًا،
     * لأنه أغلب الملفات الحقيقية (صور، PDF قصير) أصغر من هذا الحد.
     * الحد نفسه محسوب عمدًا أقل بكثير من حد Gemini لحجم الطلب الواحد
     * (~20 ميغا) حتى بعد إضافة ~33% من ترميز base64 (8 ميغا × 1.37 ≈
     * 11 ميغا) — يبقى هامش أمان واسع. الملفات الأكبر (نادرة) تبقى تمر
     * بواجهة Files API كما كانت، لأنها هي الأنسب لملف كبير فعلًا.
     */
    private const INLINE_UPLOAD_THRESHOLD = 8 * 1024 * 1024; // 8MB

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

        if ($size <= self::INLINE_UPLOAD_THRESHOLD) {
            try {
                $bytes = file_get_contents($localPath);
                if ($bytes === false) {
                    throw new RuntimeException('تعذّرت قراءة الملف.');
                }

                $text = $this->generate(base64_encode($bytes), $mimeType, inline: true);

                $this->incrementDailyUsage($user->id);

                return $text;
            } finally {
                @unlink($localPath);
            }
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

    /*
     * timeoutSeconds مرفوع لـ90 (بدل الافتراضي 45) — ملف PDF متعدد
     * الصفحات يحتاج Gemini وقتًا فعليًا أطول ليقرأه كاملًا قبل التلخيص،
     * والـ45 القديمة كانت أقصر من هذا الاحتياج الواقعي أحيانًا (راجع
     * تعليق replyWithFileSummary بالـwebhook للصورة الكاملة: رفع الملف
     * لـGemini + انتظار جهوزيته يضيفان وقتًا فوق هذا النداء نفسه).
     */
    /*
     * $fileData: إما file_uri (نتيجة رفع سابق لـFiles API، $inline=false)
     * أو محتوى الملف نفسه مرمَّز base64 ($inline=true، المسار السريع).
     * $inline=true يحتاج مهلة أطول قليلًا (120 بدل 90) لأنه بهذا المسار
     * Gemini يستقبل الملف *ويقرأه* ضمن نفس النداء دفعة وحدة، بينما
     * المسار الآخر كان قد "جهّز" الملف مسبقًا بنداء uploadFile/getFile
     * منفصل قبل ما نوصل هون أصلًا.
     */
    private function generate(string $fileData, string $mimeType, bool $inline = false): string
    {
        $systemInstruction =
            'أنت مساعد أكاديمي لطلاب هندسة أنظمة الحاسوب بكلية فلسطين التقنية. ' .
            'لخّص محتوى الصورة أو الملف المرفق بشكل واضح ومركّز يفيد الطالب '.
            'للمذاكرة، بالعربية الفصحى البسيطة، بدون مقدمات طويلة.';

        $filePart = $inline
            ? ['inline_data' => ['mime_type' => $mimeType, 'data' => $fileData]]
            : ['file_data' => ['mime_type' => $mimeType, 'file_uri' => $fileData]];

        return $this->callGemini(
            $systemInstruction,
            [
                ['text' => 'لخّصلي هذا المحتوى.'],
                $filePart,
            ],
            tooLargeMessage: 'هذا الملف كبير جدًا على المساعد يقرأه دفعة وحدة. جرّب صفحة أو جزء أصغر.',
            emptyMessage: 'ما قدر المساعد يطلع بردّ لهذا الملف، جرّب صورة أوضح.',
            timeoutSeconds: $inline ? 120 : 90
        );
    }

    /**
     * سؤال نصي حر (بلا صورة/ملف) — يشارك نفس السقف اليومي ونفس نموذج
     * Gemini المستخدَم للتلخيص، لكن بتعليمة نظام مختلفة تناسب أسئلة
     * وأجوبة قصيرة بدل تلخيص ملف. عمدًا بلا أي سياق محادثة سابقة (لا
     * حفظ بقاعدة بيانات) — كل سؤال مستقل بذاته، تمامًا كتلخيص الملف.
     *
     * @throws RuntimeException برسالة عربية جاهزة للعرض على الطالب مباشرة.
     */
    /*
     * ⚠ عمدًا بلا حد يومي (بطلب صريح من المستخدم) — الحد اليومي المشترك
     * صار مقتصرًا على "تلخيص ملفات" و"ورشة الأكواد" (فحص/تحسين/شرح) فقط،
     * وهما الأثقل تكلفة فعليًا (رفع ملفات، نداءات Gemini متعددة لكل
     * طلب). "مساعد أسئلة عام" و"مولّد أسئلة/مدار الأسئلة" (راجع
     * generateQuizQuestion) صارا بلا حد إطلاقًا.
     */
    public function askText(User $user, string $question): string
    {
        $systemInstruction =
            'أنت مساعد أكاديمي لطلاب هندسة أنظمة الحاسوب بكلية فلسطين التقنية. ' .
            'جاوب على سؤال الطالب بشكل واضح ومختصر ومفيد، بالعربية الفصحى البسيطة. ' .
            'أنت مؤهَّل تمامًا لشرح مواضيع هندسة الحاسوب المتخصصة أيضًا — مثل الدوائر المنطقية ' .
            '(Logic Gates)، مخططات التوقيت (Timing Diagrams)، معمارية الحاسوب، الأنظمة الرقمية، ' .
            'شبكات الحاسوب، وأنظمة التشغيل — لا تعتذر عن هذي المواضيع، هي صميم تخصص الطالب. ' .
            'لو السؤال غير أكاديمي إطلاقًا (مثلًا شخصي أو ترفيهي بحت)، اعتذر بلطف وقول إنك مخصص للمساعدة الأكاديمية فقط.';

        return $this->callGemini($systemInstruction, [
            ['text' => $question],
        ], tooLargeMessage: 'السؤال طويل جدًا، جرّب تختصره.', emptyMessage: 'ما قدر المساعد يطلع بجواب على هذا السؤال، جرّب صياغة مختلفة.');
    }

    // عام (لا private) حتى يقدر TelegramWebhookController يتحقق من حجم ملف الكود قبل ما يحمّله أصلًا.
    public const MAX_CODE_FILE_SIZE = 300 * 1024; // 300KB — كافٍ لأي ملف كود طالب فعلي (html/css/js/php...).

    // ناتج نداء "التعديلات" — أصغر بكثير من نداء "الملف كامل" القديم (تناسبي مع حجم التغيير لا حجم الملف).
    private const EDIT_OUTPUT_TOKENS = 8192;

    /*
     * فواصل نص فريدة لصيغة "التعديلات" التي نطلبها من Gemini (بدل
     * الملف كامل) — ≡ (IDENTICAL TO، U+2261) عمليًا غير موجود بأي كود
     * حقيقي، فاختيارها هون يقلّل تقريبًا لصفر احتمال تصادمها مع محتوى
     * الكود نفسه (بعكس مثلًا ``` أو {{ }} الشائعة ببعض اللغات).
     */
    private const EDIT_MARK_START = '≡≡EDIT≡≡';
    private const EDIT_MARK_REASON = '≡≡REASON≡≡';
    private const EDIT_MARK_OLD = '≡≡OLD≡≡';
    private const EDIT_MARK_NEW = '≡≡NEW≡≡';
    private const EDIT_MARK_END = '≡≡END≡≡';
    private const EDIT_MARK_NOCHANGES = '≡≡NOCHANGES≡≡';

    /*
     * وضع "مصحّح أكواد" بالقائمة الذكية — الطالب يبعت كود كنص عادي أو
     * كملف (html/css/js/php...)، والمساعد يرجّع: ملاحظات نصية قصيرة +
     * قائمة "تعديلات" (مقطع أصلي بالضبط ← مقطع بديل + سبب) تُطبَّق
     * برمجيًا على الكود الأصلي (راجع applyCodeEdits) بدل ما نطلب من
     * Gemini يرجّع الملف كامل من جديد.
     *
     * ليش هذا أفضل من "أرجعلي الملف كامل" (النسخة السابقة): حجم رد
     * Gemini صار متناسبًا مع حجم *التغيير* لا حجم *الملف* — ملف ٩٠
     * كيلوبايت بفيه ٣ أخطاء بسيطة يحتاج رد بضع مئات كلمة بس، مش رد
     * بحجم الملف كامل. هذا يقلّل احتمال القطع (truncation) بشكل جذري
     * حتى بلا حاجة لرفع maxOutputTokens لأرقام ضخمة، ويخلي كل تعديل
     * قابل للمراجعة لحاله (زي أدوات مراجعة الكود الاحترافية) بدل ما
     * يضطر الطالب يقارن ملفين كاملين سطر بسطر. وكخط دفاع أخير لو صار
     * رد ضخم استثنائيًا (كود فيه عشرات الأخطاء)، callGemini(...,
     * continueOnTruncation: true) بيطلب تلقائيًا من الموديل "كمّل" لو
     * انقطع فعليًا بسبب حد الطول، بدل ما يرجع نص مبتور صامت.
     *
     * جرّبنا أول نسخة بطلب JSON منظّم من Gemini بنداء واحد (responseSchema)
     * لفصل الحقلين، لكنها فشلت عمليًا (رد فاضي/غير قابل للتحليل) —
     * فاعتمدنا صيغة نصية بفواصل فريدة (EDIT_MARK_*) نحللها بـregex،
     * بنفس فلسفة generateQuizQuestion()/parseQuizQuestion().
     *
     * @return array{notes: string, fixed_code: string, edits: array<int, array{reason: string, old: string, new: string, status: string}>, no_changes: bool}
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
            'بردّك (فقط اشرح الملاحظات — التعديلات رح تُطلب منك بشكل منفصل).';

        $notes = $this->callGemini($notesInstruction, [
            ['text' => "حلّل هذا الكود:\n\n" . $code],
        ], tooLargeMessage: 'الكود طويل جدًا، جرّب تبعت جزء أصغر منه.', emptyMessage: 'ما قدر المساعد يحلل هذا الكود، جرّب تبعته مرة ثانية.');

        $editsText = $this->callGemini(
            $this->editsInstruction('صحّح أي أخطاء برمجية أو منطقية بالكود'),
            [['text' => "الكود:\n\n" . $code]],
            tooLargeMessage: 'الكود طويل جدًا، جرّب تبعت جزء أصغر منه.',
            emptyMessage: 'ما قدر المساعد يجهّز التعديلات لهذا الكود، جرّب مرة أخرى.',
            maxOutputTokens: self::EDIT_OUTPUT_TOKENS,
            timeoutSeconds: 60,
            continueOnTruncation: true
        );

        $this->incrementDailyUsage($user->id);

        return $this->buildEditsResult(trim($notes), $code, $editsText);
    }

    /*
     * تعليمة نظام موحّدة لطلب "التعديلات" (تُستخدم من debugCode()
     * وoptimizeCode() بفرق جملة واحدة فقط تصف نوع المراجعة المطلوبة)
     * — تشرح لـGemini صيغة الفواصل بالضبط وتطلب حرفية تامة بمقطع
     * "القبل" حتى تنجح applyCodeEdits() بالبحث والاستبدال الدقيق.
     */
    private function editsInstruction(string $goal): string
    {
        return
            "أنت مساعد برمجي خبير. مهمتك: {$goal}. رجّع تعديلاتك **حصرًا** بهذه الصيغة النصية، بلا " .
            "أي شرح أو مقدمة أو خاتمة أو علامات Markdown، وكرّر الكتلة لكل تعديل مستقل:\n\n" .
            self::EDIT_MARK_START . "\n" .
            self::EDIT_MARK_REASON . " <سبب التعديل بجملة قصيرة بالعربية>\n" .
            self::EDIT_MARK_OLD . "\n<المقطع الأصلي من الكود بالضبط حرفيًا — بلا أي تغيير حتى بمسافة أو سطر جديد، منسوخ تمامًا كما هو بالكود المرسَل>\n" .
            self::EDIT_MARK_NEW . "\n<المقطع البديل بعد التعديل>\n" .
            self::EDIT_MARK_END . "\n\n" .
            'كرّر هذه الكتلة لكل تعديل منفصل بالترتيب اللي يظهر فيه بالكود. اجعل "المقطع الأصلي" أصغر ما يمكن ' .
            'ويكفي فقط لتحديد مكان التعديل بدقة (سطر أو بضعة أسطر متجاورة، لا الملف كامل ولا دالة كاملة إلا لو ' .
            'التعديل يطال الدالة كلها فعلًا). لو الكود ما يحتاج أي تعديل إطلاقًا، لا تكتب أي كتلة ورجّع فقط ' .
            'الكلمة ' . self::EDIT_MARK_NOCHANGES . ' بلا أي شيء آخر.';
    }

    /*
     * يحوّل رد Gemini النصي (صيغة EDIT_MARK_*) لقائمة تعديلات، يطبّقها
     * على الكود الأصلي، ويبني نتيجة موحّدة تُستهلَك من الـwebhook.
     * فرّقنا بين null (رد ما اتبع الصيغة إطلاقًا — خطأ فعلي) و[] (اتّبع
     * الصيغة وقال صراحة "لا تعديلات") حتى ما نخلط رد فاشل بكود سليم.
     */
    private function buildEditsResult(string $notes, string $originalCode, string $editsText): array
    {
        $parsedEdits = $this->parseCodeEdits($editsText);

        if ($parsedEdits === null) {
            throw new RuntimeException('تعذّر فهم التعديلات المقترحة بصيغة واضحة، جرّب مرة أخرى.');
        }

        if ($parsedEdits === []) {
            return [
                'notes' => $notes,
                'fixed_code' => $originalCode,
                'edits' => [],
                'no_changes' => true,
            ];
        }

        $applied = $this->applyCodeEdits($originalCode, $parsedEdits);

        return [
            'notes' => $notes,
            'fixed_code' => $applied['final_code'],
            'edits' => $applied['edits'],
            'no_changes' => false,
        ];
    }

    /**
     * @return array<int, array{reason: string, old: string, new: string}>|null
     */
    private function parseCodeEdits(string $text): ?array
    {
        $trimmed = trim($text);

        if ($trimmed === '') {
            return null;
        }

        if (str_contains($trimmed, self::EDIT_MARK_NOCHANGES)) {
            return [];
        }

        if (! str_contains($trimmed, self::EDIT_MARK_START)) {
            return null;
        }

        $pattern = '/' . preg_quote(self::EDIT_MARK_START, '/') . '\s*'
            . preg_quote(self::EDIT_MARK_REASON, '/') . '\s*(?<reason>.*?)\s*'
            . preg_quote(self::EDIT_MARK_OLD, '/') . '\n(?<old>.*?)\n'
            . preg_quote(self::EDIT_MARK_NEW, '/') . '\n(?<new>.*?)\n'
            . preg_quote(self::EDIT_MARK_END, '/') . '/us';

        if (! preg_match_all($pattern, $text, $matches, PREG_SET_ORDER) || $matches === []) {
            return null;
        }

        // stripMarkdownCodeFence احتياطي هون: أحيانًا يحيط النموذج مقطع OLD/NEW بعلامات ```
        // رغم تعليمة "بلا Markdown" — إزالتها هون تمنع فشل applyCodeEdits() بحثًا حرفيًا بلا داعٍ.
        return array_map(fn (array $m): array => [
            'reason' => trim($m['reason']),
            'old' => $this->stripMarkdownCodeFence($m['old']),
            'new' => $this->stripMarkdownCodeFence($m['new']),
        ], $matches);
    }

    /**
     * يطبّق كل تعديل بالبحث عن مقطعه الأصلي حرفيًا بالكود، مع محاولة
     * احتياطية بتجاهل فروقات المسافات/الأسطر لو الحرفي ما طابق (مثلًا
     * لو Gemini غيّر مسافة بسيطة بالنسخ)، وبدون أي تخمين لو ما لقى
     * تطابقًا إطلاقًا — أفضل تعديل "يتعذّر تطبيقه" ويُعرض للطالب صراحة
     * من تعديل يُطبَّق بمكان غلط بصمت.
     *
     * status لكل تعديل: applied (تطابق حرفي وحيد) | ambiguous (تطابق
     * حرفي أكتر من مرة، طُبِّق على أول واحد) | applied_fuzzy (تطابق
     * بعد تجاهل فروقات المسافات) | failed (ما انطبق).
     *
     * @param array<int, array{reason: string, old: string, new: string}> $edits
     * @return array{final_code: string, edits: array<int, array{reason: string, old: string, new: string, status: string}>}
     */
    private function applyCodeEdits(string $code, array $edits): array
    {
        $finalCode = $code;
        $applied = [];

        foreach ($edits as $edit) {
            $old = $edit['old'];
            $new = $edit['new'];
            $reason = $edit['reason'];

            if (trim($old) === '') {
                $applied[] = ['reason' => $reason, 'old' => $old, 'new' => $new, 'status' => 'failed'];

                continue;
            }

            $count = substr_count($finalCode, $old);

            if ($count === 1) {
                $finalCode = str_replace($old, $new, $finalCode);
                $applied[] = ['reason' => $reason, 'old' => $old, 'new' => $new, 'status' => 'applied'];

                continue;
            }

            if ($count > 1) {
                $pos = strpos($finalCode, $old);
                $finalCode = substr_replace($finalCode, $new, $pos, strlen($old));
                $applied[] = ['reason' => $reason, 'old' => $old, 'new' => $new, 'status' => 'ambiguous'];

                continue;
            }

            $fuzzy = $this->findFuzzyMatch($finalCode, $old);

            if ($fuzzy !== null) {
                [$start, $length] = $fuzzy;
                $finalCode = substr_replace($finalCode, $new, $start, $length);
                $applied[] = ['reason' => $reason, 'old' => $old, 'new' => $new, 'status' => 'applied_fuzzy'];

                continue;
            }

            $applied[] = ['reason' => $reason, 'old' => $old, 'new' => $new, 'status' => 'failed'];
        }

        return ['final_code' => $finalCode, 'edits' => $applied];
    }

    /*
     * مطابقة متسامحة: تقسّم المقطع المطلوب لقطع مفصولة بمسافات/أسطر،
     * وتبني نمطًا يقبل أي عدد/نوع فراغات بينها بالكود الفعلي. الإزاحة
     * (offset) من preg_match ببايتات دائمًا (حتى مع معدِّل /u)، ونفس
     * الشيء لـsubstr_replace/strlen — يعني الحسبة متّسقة رغم UTF-8.
     *
     * @return array{0: int, 1: int}|null [موقع البداية بالبايت، طول التطابق بالبايت]
     */
    private function findFuzzyMatch(string $haystack, string $needle): ?array
    {
        $trimmed = trim($needle);

        if ($trimmed === '') {
            return null;
        }

        $pieces = preg_split('/\s+/u', $trimmed) ?: [];
        $pieces = array_values(array_filter($pieces, static fn (string $p): bool => $p !== ''));

        if ($pieces === []) {
            return null;
        }

        $pattern = '/' . implode('\s+', array_map(
            static fn (string $p): string => preg_quote($p, '/'),
            $pieces
        )) . '/us';

        if (! preg_match($pattern, $haystack, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        return [$m[0][1], strlen($m[0][0])];
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
     * "شرح الكود" — تفريع عن أداة المصحّح، لكن بدل تحديد أخطاء نطلب
     * شرح منطق الكود سطر بسطر/كتلة بكتلة، للطالب يلي فاهم الكود شغّال
     * بس مش فاهم ليش. رد نصي واحد فقط (بلا ملف كود ثاني — ما في شي
     * نعدّله هون أصلًا).
     *
     * @throws RuntimeException برسالة عربية جاهزة للعرض على الطالب مباشرة.
     */
    public function explainCode(User $user, string $code): string
    {
        if ($this->dailyUsageCount($user->id) >= AiAssistantController::DAILY_LIMIT) {
            throw new RuntimeException(
                'وصلت الحد الأقصى للأسئلة اليوم (' . AiAssistantController::DAILY_LIMIT . '). سيتجدّد تلقائيًا الساعة ١٢ منتصف الليل.'
            );
        }

        $systemInstruction =
            'أنت مدرّس برمجة لطلاب هندسة أنظمة الحاسوب. الطالب رح يبعتلك كود برمجي (بأي لغة، مثل ' .
            'HTML/CSS/JavaScript/PHP/Python/C/C++/Java، أو Verilog/VHDL، أو Assembly). مهمتك تشرحله ' .
            'منطق الكود بأسلوب تعليمي مبسّط: وش الهدف العام من الكود، وبعدين امشِ على أهم الأجزاء ' .
            'ووضّح وظيفة كل جزء وليش مكتوب هيك. ما تدقق على الأخطاء ولا تقترح تعديلات — بس اشرح كيف ' .
            'الكود شغّال حاليًا، حتى لو فيه خطأ. جاوب بالعربية الفصحى البسيطة، بدون تنسيق Markdown.';

        $text = $this->callGemini(
            $systemInstruction,
            [['text' => "اشرحلي منطق هذا الكود:\n\n" . $code]],
            tooLargeMessage: 'الكود طويل جدًا، جرّب تبعت جزء أصغر منه.',
            emptyMessage: 'ما قدر المساعد يشرح هذا الكود، جرّب تبعته مرة ثانية.',
            maxOutputTokens: 4000
        );

        $this->incrementDailyUsage($user->id);

        return $text;
    }

    /*
     * "تحسين الأداء" — تفريع تاني عن المصحّح، لكن الهدف هون كفاءة
     * وجودة الكود لا تصحيح خطأ (الكود مفروض أصلًا شغّال). نفس أسلوب
     * "تعديلات" debugCode() بالضبط (راجع تعليقها للتفصيل الكامل)، بس
     * بتعليمة مختلفة تمامًا تركّز على الأداء، القراءة، وتسمية العناصر
     * بدل تصحيح الأخطاء.
     *
     * @return array{notes: string, fixed_code: string, edits: array<int, array{reason: string, old: string, new: string, status: string}>, no_changes: bool}
     * @throws RuntimeException برسالة عربية جاهزة للعرض على الطالب مباشرة.
     */
    public function optimizeCode(User $user, string $code): array
    {
        if ($this->dailyUsageCount($user->id) >= AiAssistantController::DAILY_LIMIT) {
            throw new RuntimeException(
                'وصلت الحد الأقصى للأسئلة اليوم (' . AiAssistantController::DAILY_LIMIT . '). سيتجدّد تلقائيًا الساعة ١٢ منتصف الليل.'
            );
        }

        $notesInstruction =
            'أنت مهندس برمجيات خبير تراجع كود طالب هندسة أنظمة الحاسوب (الكود شغّال أصلًا وما فيه ' .
            'أخطاء وظيفية بالضرورة). ركّز حصرًا على تحسينات الأداء والكفاءة والقراءة: تعقيد زمني/مكاني ' .
            'أفضل، تكرار كود ممكن تفاديه، تسمية متغيرات ودوال أوضح، وبنية أنظف. اذكر كل اقتراح بنقطة ' .
            'مستقلة مع سبب مختصر ليش هو تحسين. جاوب بالعربية الفصحى البسيطة، بدون تنسيق Markdown، ' .
            'وبدون كتابة الكود كاملًا بردّك (فقط الملاحظات — التعديلات رح تُطلب منك بشكل منفصل).';

        $notes = $this->callGemini($notesInstruction, [
            ['text' => "راجع كفاءة هذا الكود واقترح تحسينات:\n\n" . $code],
        ], tooLargeMessage: 'الكود طويل جدًا، جرّب تبعت جزء أصغر منه.', emptyMessage: 'ما قدر المساعد يحلل كفاءة هذا الكود، جرّب تبعته مرة ثانية.');

        $editsText = $this->callGemini(
            $this->editsInstruction('حسّن أداء وقراءة الكود بلا تغيير سلوكه الوظيفي (تسمية، تعقيد، تكرار...)'),
            [['text' => "الكود:\n\n" . $code]],
            tooLargeMessage: 'الكود طويل جدًا، جرّب تبعت جزء أصغر منه.',
            emptyMessage: 'ما قدر المساعد يجهّز تحسينات لهذا الكود، جرّب مرة أخرى.',
            maxOutputTokens: self::EDIT_OUTPUT_TOKENS,
            timeoutSeconds: 60,
            continueOnTruncation: true
        );

        $this->incrementDailyUsage($user->id);

        return $this->buildEditsResult(trim($notes), $code, $editsText);
    }

    /*
     * وضع "مولّد أسئلة" (نسخة قديمة، غير مستخدَمة من أي زر حاليًا —
     * راجع تعليق QUIZ_SUBJECTS بالـwebhook، أُبقيت لأي مسار قديم متبقٍّ).
     * الطالب يبعت اسم موضوع أو مفهوم دراسي كنص، والمساعد يولّد له أسئلة
     * اختيار من متعدد للمراجعة الذاتية.
     *
     * ⚠ عمدًا بلا حد يومي (بطلب صريح من المستخدم) — لنفس سبب
     * generateQuizQuestion() تمامًا: "مولّد أسئلة"/"مدار الأسئلة" كله
     * مستثنى من الحد المشترك، المُقتصَر الآن على تلخيص الملفات وورشة
     * الأكواد فقط.
     *
     * @throws RuntimeException برسالة عربية جاهزة للعرض على الطالب مباشرة.
     */
    public function generateQuiz(User $user, string $topic): string
    {
        $systemInstruction =
            'أنت مساعد أكاديمي لطلاب هندسة أنظمة الحاسوب. الطالب رح يبعتلك اسم موضوع أو مفهوم دراسي. ' .
            'ولّد له بالضبط 5 أسئلة اختيار من متعدد (كل سؤال 4 خيارات (أ/ب/ج/د)) لمراجعة هذا الموضوع، ' .
            'واكتب بنهاية الرسالة قسم منفصل بعنوان "الإجابات الصحيحة" فيه رقم كل سؤال وحرف إجابته الصحيحة فقط. ' .
            'جاوب بالعربية الفصحى البسيطة، وبدون تنسيق Markdown (رسائل تيليجرام هون HTML لا Markdown).';

        return $this->callGemini($systemInstruction, [
            ['text' => 'ولّدلي أسئلة مراجعة عن: ' . $topic],
        ], tooLargeMessage: 'اسم الموضوع طويل جدًا، جرّب تختصره.', emptyMessage: 'ما قدر المساعد يولّد أسئلة لهذا الموضوع، جرّب صياغة مختلفة.');
    }

    /*
     * محرّك "الاختبار المستمر" — سؤال واحد بكل نداء (لا خمسة دفعة
     * وحدة زي generateQuiz() فوق) حتى يقدر الطالب يوقف وقت ما بدو،
     * ونتفادى تكرار نفس السؤال بتمرير نصوص الأسئلة السابقة ($askedQuestions)
     * ليتجنبها الموديل صراحةً. صيغة رد نصية بسيطة بعلامات ثابتة
     * (مش JSON responseSchema — جُرِّب سابقًا مع debugCode وفشل عمليًا،
     * راجع تعليق debugCode) نحللها بـregex بدل انتظار بنية منظّمة.
     *
     * @param string[] $askedQuestions نصوص أسئلة سابقة بنفس الجلسة (تُقصّ لآخر ٢٠ فقط قبل الإرسال لتبقى الحمولة معقولة).
     * @return array{question: string, options: array<string,string>, correct: string}
     * @throws RuntimeException برسالة عربية جاهزة للعرض على الطالب مباشرة.
     */
    /*
     * ⚠ "مدار الأسئلة" مقصود إنه بلا أي حد يومي — طلب صريح من المستخدم
     * ("بدي إياه مفتوح") بعد ما لاحظ إنه بيتوقف عند 30 سؤال باليوم، وهو
     * نفس رقم DAILY_LIMIT المشترك بين الموقع وباقي أدوات البوت (تلخيص/
     * مساعد عام/مصحّح أكواد). عمدًا لا نتحقق من dailyUsageCount هون ولا
     * نستدعي incrementDailyUsage() بآخر الدالة (تحت) — حتى توليد أسئلة
     * لا نهائي ما يستهلك ولا يتأثر برصيد تلك الأدوات إطلاقًا.
     */
    public function generateQuizQuestion(User $user, string $topic, array $askedQuestions = []): array
    {
        $recentAsked = array_slice($askedQuestions, -20);

        $avoidance = $recentAsked === []
            ? ''
            : "أسئلة سبق طرحها بهذه الجلسة، تجنّب تكرارها أو إعادة صياغتها بشكل قريب:\n- "
                . implode("\n- ", $recentAsked) . "\n\n";

        $systemInstruction =
            'أنت مولّد أسئلة مراجعة لطلاب هندسة أنظمة الحاسوب. الطالب رح يبعتلك اسم مادة أو موضوع دراسي. ' .
            'ولّد سؤال اختيار من متعدد واحد فقط (4 خيارات)، أصيل ومختلف في صياغته وزاويته كل مرة — ' .
            'نوّع بين تعريف مفهوم، تطبيق عملي، مقارنة، أو تحليل سيناريو قصير، حسب ما يناسب الموضوع. ' .
            'اكتب الرد بالضبط بهذا الشكل ولا شيء غيره (بلا Markdown ولا نجوم ولا شرح إضافي):' . "\n\n" .
            "السؤال: <نص السؤال>\n" .
            "أ) <الخيار الأول>\n" .
            "ب) <الخيار الثاني>\n" .
            "ج) <الخيار الثالث>\n" .
            "د) <الخيار الرابع>\n" .
            'الإجابة: <حرف واحد من أ/ب/ج/د>';

        $text = $this->callGemini(
            $systemInstruction,
            [['text' => $avoidance . 'ولّد سؤالًا عن: ' . $topic]],
            tooLargeMessage: 'اسم الموضوع طويل جدًا، جرّب تختصره.',
            emptyMessage: 'ما قدر المساعد يولّد سؤالًا لهذا الموضوع، جرّب صياغة مختلفة.',
            maxOutputTokens: 900
        );

        $parsed = $this->parseQuizQuestion($text);

        if ($parsed === null) {
            throw new RuntimeException('تعذّر تجهيز السؤال بشكل صحيح، جرّب مرة أخرى.');
        }

        return $parsed;
    }

    /**
     * @return array{question: string, options: array<string,string>, correct: string}|null
     */
    private function parseQuizQuestion(string $text): ?array
    {
        $pattern = '/السؤال\s*[:：]\s*(?<q>.+?)\s*\n+\s*أ\)\s*(?<a>.+?)\s*\n+\s*ب\)\s*(?<b>.+?)\s*\n+\s*ج\)\s*(?<c>.+?)\s*\n+\s*د\)\s*(?<d>.+?)\s*\n+\s*الإجابة\s*[:：]\s*(?<correct>[أبجد])/us';

        if (! preg_match($pattern, $text, $m)) {
            return null;
        }

        return [
            'question' => trim($m['q']),
            'options' => [
                'أ' => trim($m['a']),
                'ب' => trim($m['b']),
                'ج' => trim($m['c']),
                'د' => trim($m['d']),
            ],
            'correct' => $m['correct'],
        ];
    }

    /*
     * $maxOutputTokens الافتراضي (1500) كافٍ لملاحظات/شرح/سؤال واحد،
     * لكنه غير كافٍ إطلاقًا لإرجاع "الكود كاملًا" لملف حقيقي كبير
     * (مثل admin.html) — كان هذا السبب الفعلي وراء شكوى الطاقم إنه
     * الملف المرجَّع من debugCode/optimizeCode "مش كامل": الرد يُقطَع
     * عند حد الخرج لا لأي خلل بمنطق إرسال الملف نفسه. نداءا الكود
     * الكامل بـdebugCode()/optimizeCode() يمرّران قيمة أعلى بكثير.
     */
    /*
     * $continueOnTruncation: خط الدفاع الأخير ضد رد ينقطع فعليًا بسبب
     * حد $maxOutputTokens (finishReason === 'MAX_TOKENS') — بدل ما
     * نكتفي برفع الرقم ونأمل إنه يكفي، لو انقطع فعليًا نضيف رد الموديل
     * الجزئي كدور "model" ونطلب منه "كمّل من حيث توقفت" بدور "user"
     * جديد، ونلزّق النصين. أصبح هذا مأمونًا الآن بعد إغلاق اتصال
     * الويبهوك فورًا (fastcgi_finish_request بالكونترولر) — الوقت
     * الإضافي ما بيأثر على تسليم الرد لتيليجرام. سقف 3 جولات يمنع حلقة
     * لا نهائية لو الموديل استمر يقطع ردّه لأي سبب.
     */
    private function callGemini(
        string $systemInstruction,
        array $parts,
        string $tooLargeMessage,
        string $emptyMessage,
        int $maxOutputTokens = 1500,
        int $timeoutSeconds = 45,
        bool $continueOnTruncation = false
    ): string {
        $apiKey = config('services.gemini.key');

        if (! $apiKey) {
            throw new RuntimeException('المساعد الذكي غير مفعّل حاليًا على الخادم.');
        }

        $contents = [['role' => 'user', 'parts' => $parts]];
        $accumulated = '';
        $maxRounds = $continueOnTruncation ? 3 : 1;

        for ($round = 1; $round <= $maxRounds; $round++) {
            $response = Http::timeout($timeoutSeconds)
                ->withHeaders(['x-goog-api-key' => $apiKey])
                ->post(
                    'https://generativelanguage.googleapis.com/v1beta/models/'
                        . AiAssistantController::GEMINI_MODEL . ':generateContent',
                    [
                        'systemInstruction' => ['parts' => [['text' => $systemInstruction]]],
                        'contents' => $contents,
                        'generationConfig' => ['maxOutputTokens' => $maxOutputTokens, 'temperature' => 0.6],
                    ]
                );

            if ($response->status() === 429) {
                if ($accumulated !== '') {
                    break;
                }

                throw new RuntimeException('المساعد الذكي مزدحم حاليًا، جرّب بعد شوي.');
            }

            if (! $response->successful()) {
                report(new RuntimeException('Telegram AI Gemini call failed: ' . $response->body()));

                if ($accumulated !== '') {
                    break;
                }

                $tooLarge = $response->status() === 400
                    && str_contains($response->body(), 'exceeds the maximum number of tokens');

                throw new RuntimeException($tooLarge ? $tooLargeMessage : 'تعذّر تحليل الطلب حاليًا، جرّب مرة أخرى بعد شوي.');
            }

            $candidate = $response->json('candidates.0') ?? [];
            $chunkParts = $candidate['content']['parts'] ?? [];
            $chunkText = collect($chunkParts)->pluck('text')->filter()->implode('');
            $finishReason = $candidate['finishReason'] ?? null;

            $accumulated .= $chunkText;

            if (! $continueOnTruncation || $finishReason !== 'MAX_TOKENS' || $round === $maxRounds) {
                break;
            }

            $contents[] = ['role' => 'model', 'parts' => [['text' => $chunkText]]];
            $contents[] = ['role' => 'user', 'parts' => [
                ['text' => 'تابع بالضبط من حيث توقفت، بلا إعادة أي جزء سبق إرساله، وبلا أي مقدمة أو تعليق إضافي.'],
            ]];
        }

        if (! $accumulated) {
            throw new RuntimeException($emptyMessage);
        }

        return $accumulated;
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
