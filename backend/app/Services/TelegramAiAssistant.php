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
            'لو السؤال غير أكاديمي أو خارج تخصصك، اعتذر بلطف وقول إنك مخصص للمساعدة الأكاديمية فقط.';

        $text = $this->callGemini($systemInstruction, [
            ['text' => $question],
        ], tooLargeMessage: 'السؤال طويل جدًا، جرّب تختصره.', emptyMessage: 'ما قدر المساعد يطلع بجواب على هذا السؤال، جرّب صياغة مختلفة.');

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
