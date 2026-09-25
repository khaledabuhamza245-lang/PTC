<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TelegramLink;
use App\Services\PlanCalculator;
use App\Services\TelegramAiAssistant;
use App\Services\TelegramBotApi;
use Illuminate\Http\Request;

/*
 * نقطة الاستقبال الوحيدة من تيليجرام (Webhook) — المرحلة الأولى (ربط
 * الحساب) + أول أمر حقيقي (خطتي).
 *
 * تيليجرام بيبعت POST لهاد الرابط لكل رسالة توصل للبوت. الحماية هون
 * بـ"سرّ" خاص (X-Telegram-Bot-Api-Secret-Token) نحدده إحنا وقت تفعيل
 * الـwebhook — مش بجلسة تسجيل دخول عادية، لأنه المستدعي هون سيرفرات
 * تيليجرام نفسها لا متصفح طالب (نفس فلسفة /ai/process-pending الحالية
 * بالموقع، حماية برمز سرّي بالرابط/الترويسة لا Sanctum).
 *
 * أمر "خطتي" يستدعي PlanCalculator::summarize() مباشرة (نفس الخدمة
 * يلي تستخدمها صفحة "صفحتي الشخصية" بالضبط عبر PlanController) — عمدًا
 * بلا أي حساب جديد أو منفصل هون، حتى ما يصير عنا مصدرين مختلفين
 * لنفس الرقم يوم ما يتغيّر منطق الحساب بمكان ونُنسى الآخر. لهاد
 * السبب بالضبط ما بنينا أمر "معدلي" بعد — حاسبة المعدل التراكمي
 * بالموقع (gpa.html) حسابها بالكامل بالمتصفح (JS) لا بالخادم، فمافي
 * رقم جاهز نقرأه من قاعدة البيانات بأمان بدون ما نكرر نفس المنطق
 * هون — قرار مؤجَّل لمرحلة لاحقة يستاهل نقاشه لحاله.
 *
 * أي رسالة نصية ما اتعرفت كأمر (مش "خطتي"/"مساعدة"/"القائمة") تُعتبر
 * سؤال حر وتتحول تلقائيًا لأداة الذكاء الاصطناعي المختارة حاليًا من
 * "القائمة الذكية" (chat/debug/quiz — عمود telegram_links.mode) —
 * راجع routeFreeTextToAssistant() وTelegramLink::currentMode().
 * الصور والملفات (replyWithFileSummary) دايمًا تروح للتلخيص بغض
 * النظر عن الوضع الحالي، لأنها ميزة منفصلة عمليًا عن أوضاع النص.
 *
 * "القائمة الذكية": رسالة فيها أزرار inline (callback_query) — ضغطة
 * زر بتوصل هون كتحديث منفصل (update.callback_query لا update.message)
 * فلازم يُعالج قبل استخراج $message العادية بالأسفل.
 */
class TelegramWebhookController extends Controller
{
    /*
     * أدوات "القائمة الذكية" — كل وحدة مربوطة بدالة مختلفة بـ
     * TelegramAiAssistant. "summarize" مش له دالة نص خاصة (التلخيص
     * أصلًا شغّال بالصور/الملفات بغض النظر عن الوضع) — اختياره بس
     * تذكير للطالب إنه يبعت صورة/ملف.
     */
    private const MODE_LABELS = [
        'chat' => '💬 مساعد أسئلة عام',
        'debug' => '🐛 مصحّح أكواد',
        'quiz' => '📝 مولّد أسئلة',
        'summarize' => '📄 تلخيص ملفات',
    ];

    /*
     * امتدادات ملفات الكود المقبولة بوضع "مصحّح أكواد" فقط — منفصلة
     * تمامًا عن الصور/PDF المقبولة بالتلخيص (replyWithFileSummary).
     * تيليجرام غالبًا يبعت mime_type غير دقيق لهذي الامتدادات (أو
     * application/octet-stream)، فالتحقق هون بامتداد اسم الملف نفسه
     * لا بـmime_type.
     */
    private const CODE_FILE_EXTENSIONS = [
        'html', 'htm', 'css', 'js', 'jsx', 'ts', 'tsx', 'php', 'py', 'java',
        'c', 'h', 'cpp', 'hpp', 'cs', 'json', 'sql', 'txt', 'md', 'xml',
        'sh', 'rb', 'go', 'kt', 'swift', 'yml', 'yaml', 'vue', 'dart',
    ];

    public function __invoke(
        Request $request,
        TelegramBotApi $bot,
        PlanCalculator $planCalculator,
        TelegramAiAssistant $aiAssistant
    ) {
        $expectedSecret = (string) config('services.telegram.webhook_secret', '');

        if (
            $expectedSecret === ''
            || $request->header('X-Telegram-Bot-Api-Secret-Token') !== $expectedSecret
        ) {
            return response()->json(['ok' => false], 403);
        }

        $callbackQuery = $request->input('callback_query');
        if (is_array($callbackQuery)) {
            $this->handleMenuCallback($bot, $callbackQuery);

            return response()->json(['ok' => true]);
        }

        $message = $request->input('message');
        $chatId = $message['chat']['id'] ?? null;
        $text = trim((string) ($message['text'] ?? ''));
        $telegramFirstName = (string) ($message['from']['first_name'] ?? '');

        $photos = $message['photo'] ?? null;
        $document = $message['document'] ?? null;
        $hasMedia = is_array($photos) && $photos !== [] || is_array($document);

        // ردّ فارغ لأي تحديث ما فيه رسالة نصية ولا صورة/ملف (ملصقات،
        // تعديل رسالة قديمة...) — 200 دايمًا حتى ما تعيد تيليجرام
        // إرسال نفس التحديث.
        if (! $chatId || ($text === '' && ! $hasMedia)) {
            return response()->json(['ok' => true]);
        }

        if (str_starts_with($text, '/start')) {
            $token = trim(substr($text, strlen('/start')));

            if ($token === '') {
                $bot->sendMessage(
                    $chatId,
                    'أهلًا 👋 لازم تربط حسابك أول شي من صفحة "حسابي" بموقع دليل طالب هندسة أنظمة الحاسوب.'
                );

                return response()->json(['ok' => true]);
            }

            $link = TelegramLink::query()
                ->where('link_token', $token)
                ->where('token_expires_at', '>', now())
                ->first();

            if (! $link) {
                $bot->sendMessage(
                    $chatId,
                    'هذا الرابط منتهي أو غير صالح. ارجع لصفحة "حسابي" بالموقع واطلب رابط ربط جديد.'
                );

                return response()->json(['ok' => true]);
            }

            $link->update([
                'telegram_chat_id' => $chatId,
                'telegram_first_name' => $telegramFirstName,
                'linked_at' => now(),
                'link_token' => null,
                'token_expires_at' => null,
            ]);

            $studentName = trim((string) ($link->user?->first_name ?? ''));

            $bot->sendMessage(
                $chatId,
                "تم ربط حسابك بنجاح يا {$studentName} ✅\n".
                "جرّب تكتب \"خطتي\"، أو اكتب \"القائمة\" لتختار أداة الذكاء الاصطناعي (مساعد أسئلة/مصحّح أكواد/مولّد أسئلة/تلخيص ملفات)، أو اكتب \"مساعدة\" تشوف كل الأوامر."
            );

            return response()->json(['ok' => true]);
        }

        /*
         * أي أمر تاني يحتاج حساب مربوط فعليًا — نجيبه بحثًا بمعرّف
         * المحادثة، لا الاعتماد على أي شيء أرسله المستخدم نفسه.
         */
        $link = TelegramLink::query()
            ->whereNotNull('telegram_chat_id')
            ->where('telegram_chat_id', $chatId)
            ->first();

        if (! $link || ! $link->user) {
            $bot->sendMessage(
                $chatId,
                'لسا ما ربطت حسابك 🙂 روح لصفحة "إعدادات الحساب" بالموقع واضغط "اربط حسابي بتيليجرام".'
            );

            return response()->json(['ok' => true]);
        }

        if ($hasMedia) {
            /*
             * بوضع "مصحّح أكواد" فقط، ملف بامتداد كود معروف (html/css/js/php...)
             * يروح لمسار التصحيح لا التلخيص — أي ملف/صورة تانية (بأي
             * وضع) يفضل يروح للتلخيص العادي كما كان دايمًا.
             */
            $documentExtension = is_array($document)
                ? strtolower((string) pathinfo((string) ($document['file_name'] ?? ''), PATHINFO_EXTENSION))
                : '';

            if (
                $link->currentMode() === 'debug'
                && is_array($document)
                && in_array($documentExtension, self::CODE_FILE_EXTENSIONS, true)
            ) {
                $this->replyWithCodeFileDebug($bot, $aiAssistant, $chatId, $link->user, $document);

                return response()->json(['ok' => true]);
            }

            $this->replyWithFileSummary($bot, $aiAssistant, $chatId, $link->user, $photos, $document);

            return response()->json(['ok' => true]);
        }

        $normalized = trim($text, "/ \t\n");

        if (in_array($normalized, ['خطتي', 'plan'], true)) {
            $this->replyWithPlanSummary($bot, $chatId, $link->user, $planCalculator);

            return response()->json(['ok' => true]);
        }

        if (in_array($normalized, ['مساعدة', 'help', 'أوامر'], true)) {
            $bot->sendMessage(
                $chatId,
                "الأوامر المتاحة حاليًا (نسخة تجريبية، رح تكبر تدريجيًا):\n\n".
                "📊 خطتي — تقدّمك نحو التخرّج (الساعات المعتمدة).\n".
                "🧰 القائمة — اختر أداة الذكاء الاصطناعي يلي بدك تشتغل فيها.\n".
                "📷 ابعتلي صورة صفحة أو ملف PDF — رح ألخّصلك محتواها (بأي وضع).\n".
                "💬 اكتب أي سؤال أو كود أو موضوع عادي — رح يردّ حسب الأداة المختارة حاليًا.\n".
                "❓ مساعدة — هاي القائمة."
            );

            return response()->json(['ok' => true]);
        }

        if (in_array($normalized, ['القائمة', 'menu', 'قائمة'], true)) {
            $this->sendMenu($bot, $chatId, $link->currentMode());

            return response()->json(['ok' => true]);
        }

        /*
         * أي نص تاني (مش أمر معروف) نعتبره سؤال حر ونمرره للأداة
         * المختارة حاليًا من "القائمة الذكية" — بدل رسالة "ما فهمت"
         * الجامدة، وبدل ما تكون أداة واحدة فقط ثابتة (askText).
         */
        $this->routeFreeTextToAssistant($bot, $aiAssistant, $chatId, $link, $text);

        return response()->json(['ok' => true]);
    }

    /*
     * أزرار "القائمة الذكية" — كل زر بيبدّل عمود telegram_links.mode
     * لهذا الحساب عبر callback_data بصيغة "mode:<key>" (راجع
     * handleMenuCallback). ✅ بتظهر جنب الأداة المختارة حاليًا فقط.
     */
    private function sendMenu(TelegramBotApi $bot, int|string $chatId, string $currentMode): void
    {
        $label = function (string $key) use ($currentMode) {
            $text = self::MODE_LABELS[$key];

            return $key === $currentMode ? $text . ' ✅' : $text;
        };

        $keyboard = [
            [
                ['text' => $label('chat'), 'callback_data' => 'mode:chat'],
                ['text' => $label('debug'), 'callback_data' => 'mode:debug'],
            ],
            [
                ['text' => $label('quiz'), 'callback_data' => 'mode:quiz'],
                ['text' => $label('summarize'), 'callback_data' => 'mode:summarize'],
            ],
        ];

        $bot->sendMessage(
            $chatId,
            "🧰 <b>القائمة الذكية</b>\n\nاختر الأداة يلي بدك تشتغل فيها — أي رسالة نصية بعدها بتروح لنفس الأداة تلقائيًا لحد ما تبدّلها من هون:",
            $keyboard
        );
    }

    /*
     * ضغطة زر بـ"القائمة الذكية" (update.callback_query). لازم نتحقق
     * من الحساب المربوط هون كمان بنفس طريقة باقي الأوامر (بحثًا
     * بمعرّف المحادثة)، وليس بأي معطى يبعته العميل نفسه.
     */
    private function handleMenuCallback(TelegramBotApi $bot, array $callbackQuery): void
    {
        $callbackId = (string) ($callbackQuery['id'] ?? '');
        $chatId = $callbackQuery['message']['chat']['id'] ?? null;
        $data = (string) ($callbackQuery['data'] ?? '');

        if (! $chatId || ! str_starts_with($data, 'mode:')) {
            $bot->answerCallbackQuery($callbackId);

            return;
        }

        $mode = substr($data, strlen('mode:'));

        if (! array_key_exists($mode, self::MODE_LABELS)) {
            $bot->answerCallbackQuery($callbackId);

            return;
        }

        $link = TelegramLink::query()
            ->whereNotNull('telegram_chat_id')
            ->where('telegram_chat_id', $chatId)
            ->first();

        if (! $link) {
            $bot->answerCallbackQuery($callbackId, 'هذا الحساب مش مربوط.');

            return;
        }

        $link->update(['mode' => $mode]);
        $bot->answerCallbackQuery($callbackId, 'تم اختيار: ' . self::MODE_LABELS[$mode]);

        $confirmations = [
            'chat' => "💬 <b>مساعد أسئلة عام</b>\nاكتب أي سؤال أكاديمي وبردّ عليك مباشرة.",
            'debug' => "🐛 <b>مصحّح أكواد</b>\nابعت الكود كنص عادي أو كملف (html/css/js/php...)، وبردّلك بملاحظات على الأخطاء + ملف فيه الكود بعد التصحيح.",
            'quiz' => "📝 <b>مولّد أسئلة</b>\nاكتب اسم موضوع أو مفهوم دراسي، وبولّدلك 5 أسئلة اختيار من متعدد للمراجعة.",
            'summarize' => "📄 <b>تلخيص ملفات</b>\nابعتلي صورة صفحة أو ملف PDF وبلخصلك محتواها (هاي شغالة بأي وضع أصلًا).",
        ];

        $bot->sendMessage($chatId, $confirmations[$mode]);
    }

    /*
     * توجيه أي نص حر لدالة TelegramAiAssistant المناسبة حسب
     * $link->currentMode(). نفس منطق التحقق من السقف اليومي والرسائل
     * الودّية بكل الأوضاع (مبني على replyWithFileSummary الأصلية).
     */
    private function routeFreeTextToAssistant(
        TelegramBotApi $bot,
        TelegramAiAssistant $aiAssistant,
        int|string $chatId,
        TelegramLink $link,
        string $text
    ): void {
        $mode = $link->currentMode();

        if ($mode === 'summarize') {
            $bot->sendMessage(
                $chatId,
                '📄 وضعك الحالي "تلخيص ملفات" — ابعتلي صورة صفحة أو ملف PDF مباشرة، أو اكتب "القائمة" لتبدّل الأداة.'
            );

            return;
        }

        @set_time_limit(60);

        $user = $link->user;

        if ($aiAssistant->remainingToday($user) <= 0) {
            $bot->sendMessage(
                $chatId,
                'وصلت الحد الأقصى للأسئلة اليوم (' . \App\Http\Controllers\Api\V1\AiAssistantController::DAILY_LIMIT . '). سيتجدّد تلقائيًا الساعة ١٢ منتصف الليل.'
            );

            return;
        }

        try {
            if ($mode === 'debug') {
                $result = $aiAssistant->debugCode($user, $text);
                $this->sendDebugResult($bot, $chatId, $result, 'fixed_code.txt');

                return;
            }

            $answer = $mode === 'quiz'
                ? $aiAssistant->generateQuiz($user, $text)
                : $aiAssistant->askText($user, $text);

            $emoji = $mode === 'quiz' ? '📝' : '💬';
            $safeAnswer = TelegramBotApi::escapeHtml($answer);
            $bot->sendMessage($chatId, "{$emoji} {$safeAnswer}");
        } catch (\Throwable $error) {
            $friendly = $error instanceof \RuntimeException
                ? $error->getMessage()
                : 'صار خطأ غير متوقع أثناء معالجة طلبك، جرّب مرة أخرى.';

            if (! $error instanceof \RuntimeException) {
                report($error);
            }

            $bot->sendMessage($chatId, "⚠️ {$friendly}");
        }
    }

    /*
     * ملف كود (html/css/js/php...) بوضع "مصحّح أكواد" — يقرأ محتوى
     * الملف كنص عادي (لا رفع لـGemini Files مثل التلخيص، الكود نص
     * صرف أصلًا وحجمه صغير) ويمرّره لنفس TelegramAiAssistant::debugCode().
     */
    private function replyWithCodeFileDebug(
        TelegramBotApi $bot,
        TelegramAiAssistant $aiAssistant,
        int|string $chatId,
        \App\Models\User $user,
        array $document
    ): void {
        @set_time_limit(60);

        if ($aiAssistant->remainingToday($user) <= 0) {
            $bot->sendMessage(
                $chatId,
                'وصلت الحد الأقصى للأسئلة اليوم (' . \App\Http\Controllers\Api\V1\AiAssistantController::DAILY_LIMIT . '). سيتجدّد تلقائيًا الساعة ١٢ منتصف الليل.'
            );

            return;
        }

        $fileId = (string) ($document['file_id'] ?? '');
        $originalName = (string) ($document['file_name'] ?? 'code.txt');

        if ((int) ($document['file_size'] ?? 0) > TelegramAiAssistant::MAX_CODE_FILE_SIZE) {
            $bot->sendMessage($chatId, 'الملف كبير جدًا للمصحّح حاليًا (الحد ٣٠٠ كيلوبايت) — جرّب جزء أصغر من الكود.');

            return;
        }

        $bot->sendMessage($chatId, '🤖 جارٍ مراجعة الكود... ثواني وبردّ عليك.');

        $localPath = $bot->downloadFile($fileId);

        if (! $localPath) {
            $bot->sendMessage($chatId, 'تعذّر تحميل الملف من تيليجرام، جرّب تبعته مرة ثانية.');

            return;
        }

        try {
            $size = filesize($localPath) ?: 0;

            if ($size <= 0 || $size > TelegramAiAssistant::MAX_CODE_FILE_SIZE) {
                $bot->sendMessage($chatId, 'الملف كبير جدًا للمصحّح حاليًا (الحد ٣٠٠ كيلوبايت) — جرّب جزء أصغر من الكود.');

                return;
            }

            $code = (string) file_get_contents($localPath);
            $result = $aiAssistant->debugCode($user, $code);
            $this->sendDebugResult($bot, $chatId, $result, 'fixed_' . $originalName);
        } catch (\Throwable $error) {
            $friendly = $error instanceof \RuntimeException
                ? $error->getMessage()
                : 'صار خطأ غير متوقع أثناء مراجعة الكود، جرّب مرة أخرى.';

            if (! $error instanceof \RuntimeException) {
                report($error);
            }

            $bot->sendMessage($chatId, "⚠️ {$friendly}");
        } finally {
            @unlink($localPath);
        }
    }

    /*
     * ملاحظات "مصحّح أكواد" كرسالة نصية قصيرة + الكود المعدَّل كملف
     * منفصل (sendDocument) — بدل نص طويل يعمل سكرول بالمحادثة، بناءً
     * على طلب صريح من الطالب. يُستدعى من مسار النص الحر ومسار رفع
     * الملف كليهما.
     *
     * @param array{notes: string, fixed_code: string} $result
     */
    private function sendDebugResult(TelegramBotApi $bot, int|string $chatId, array $result, string $filename): void
    {
        $notes = trim($result['notes']);
        $safeNotes = $notes !== '' ? TelegramBotApi::escapeHtml($notes) : 'ما في ملاحظات إضافية.';
        $bot->sendMessage($chatId, "🐛 <b>ملاحظات المصحّح</b>\n\n{$safeNotes}");

        $fixedCode = $result['fixed_code'];

        if (trim($fixedCode) === '') {
            return;
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'tgcode_');

        try {
            file_put_contents($tmpPath, $fixedCode);
            $bot->sendDocument($chatId, $tmpPath, $filename, '📄 الكود بعد المراجعة');
        } finally {
            @unlink($tmpPath);
        }
    }

    private function replyWithPlanSummary(
        TelegramBotApi $bot,
        int|string $chatId,
        \App\Models\User $user,
        PlanCalculator $planCalculator
    ): void {
        $summary = $planCalculator->summarize($user);

        $completed = (int) $summary['completed_hours'];
        $total = (int) $summary['total_credit_hours'];
        $remaining = (int) $summary['remaining_hours'];
        $percent = (int) $summary['percent'];
        $registered = (int) ($summary['counts']['registered'] ?? 0);

        $bot->sendMessage(
            $chatId,
            "📊 <b>تقدّمك نحو التخرّج</b>\n\n".
            "✅ أنجزت {$completed} من {$total} ساعة معتمدة ({$percent}٪)\n".
            "📚 متبقّي: {$remaining} ساعة\n".
            "🟢 مسجّل حاليًا: {$registered} مساق"
        );
    }

    /*
     * صورة أو ملف (PDF/صورة كمستند) → تنزيل من تيليجرام → تلخيص عبر
     * TelegramAiAssistant. رفع الحد الزمني هون تحديدًا (لا لباقي
     * الأوامر) لأن استدعاء Gemini قد يأخذ عشرات الثواني، وإعدادات PHP
     * الافتراضية بالاستضافة المشتركة أقصر من ذلك عادة.
     */
    private function replyWithFileSummary(
        TelegramBotApi $bot,
        TelegramAiAssistant $aiAssistant,
        int|string $chatId,
        \App\Models\User $user,
        ?array $photos,
        ?array $document
    ): void {
        @set_time_limit(60);

        if (is_array($photos) && $photos !== []) {
            $fileId = (string) end($photos)['file_id'];
            $mimeType = 'image/jpeg';
            $displayName = 'telegram-photo.jpg';
        } elseif (is_array($document)) {
            $mimeType = (string) ($document['mime_type'] ?? '');
            $isSupported = str_starts_with($mimeType, 'image/') || $mimeType === 'application/pdf';

            if (! $isSupported) {
                $bot->sendMessage($chatId, 'هذا النوع من الملفات مش مدعوم حاليًا 🙂 جرّب صورة أو ملف PDF.');

                return;
            }

            $fileId = (string) ($document['file_id'] ?? '');
            $displayName = (string) ($document['file_name'] ?? 'telegram-document');
        } else {
            return;
        }

        if ($aiAssistant->remainingToday($user) <= 0) {
            $bot->sendMessage(
                $chatId,
                'وصلت الحد الأقصى للأسئلة اليوم (' . \App\Http\Controllers\Api\V1\AiAssistantController::DAILY_LIMIT . '). سيتجدّد تلقائيًا الساعة ١٢ منتصف الليل.'
            );

            return;
        }

        $bot->sendMessage($chatId, '🤖 جارٍ تحليل الملف... ثواني وبردّ عليك.');

        $localPath = $bot->downloadFile($fileId);

        if (! $localPath) {
            $bot->sendMessage($chatId, 'تعذّر تحميل الملف من تيليجرام، جرّب تبعته مرة ثانية.');

            return;
        }

        try {
            $summary = $aiAssistant->summarizeFile($user, $localPath, $mimeType, $displayName);
            $safeSummary = TelegramBotApi::escapeHtml($summary);
            $bot->sendMessage($chatId, "📝 <b>ملخّص المحتوى</b>\n\n{$safeSummary}");
        } catch (\Throwable $error) {
            $friendly = $error instanceof \RuntimeException
                ? $error->getMessage()
                : 'صار خطأ غير متوقع أثناء تحليل الملف، جرّب مرة أخرى.';

            if (! $error instanceof \RuntimeException) {
                report($error);
            }

            $bot->sendMessage($chatId, "⚠️ {$friendly}");
        }
    }
}
