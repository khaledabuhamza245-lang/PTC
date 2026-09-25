<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ScheduleLecture;
use App\Models\TelegramLink;
use App\Services\PlanCalculator;
use App\Services\TelegramAiAssistant;
use App\Services\TelegramBotApi;
use App\Services\TelegramGpaCalculator;
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
 * لنفس الرقم يوم ما يتغيّر منطق الحساب بمكان ونُنسى الآخر.
 *
 * أمر "معدلي" يستخدم TelegramGpaCalculator — خدمة جديدة معزولة (لا
 * علاقة لها بـGpaController) تعيد بناء منطق frontend/gpa.js
 * (computeStats) بلغة PHP، لأن ذاك الملف يعمل حصرًا بالمتصفح ولا طريقة
 * لاستدعائه من الخادم. العلامات نفسها مقروءة مباشرة من GpaEntry (نفس
 * الجدول الذي تحفظ فيه صفحة "حاسبة المعدل" بالموقع)، فأي تعديل هون أو
 * هناك ينعكس بالمكانين فورًا — لكن صيغة الحساب نفسها مكرَّرة بقصد بين
 * الطرفين (راجع تنبيه الصيانة أعلى TelegramGpaCalculator).
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
 *
 * "جدولي": عرض مباشر لجدول ScheduleLecture (نفس جدول صفحة "جدول
 * المحاضرات" بالموقع) + تذكير تلقائي قبل كل محاضرة بربع ساعة عبر أمر
 * artisan منفصل (SendTelegramLectureReminders) يُستدعى من Cron خارجي
 * كل ٥ دقائق (لا queue/schedule:run حقيقي بدون SSH — راجع
 * telegram_bot_step_reminders_cpanel_cron_setup.txt).
 *
 * إضافة/تعديل/حذف محاضرة من داخل البوت نفسه (بدون فتح الموقع أبدًا)
 * — محادثة متعددة الخطوات مخزَّنة بعمود telegram_links.pending_action
 * (راجع الشرح المفصّل فوق startAddFlow()).
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

    /*
     * ترتيب أيام "جدولي" — نفس ترتيب/ترميز عمود ScheduleLecture::days
     * بالضبط (0=الأحد...6=السبت، مطابق لـCarbon::dayOfWeek ولصفحة
     * "جدول المحاضرات" بالموقع schedule.js) — السبت أولًا كما بالموقع.
     */
    private const DAY_LABELS = [
        6 => 'السبت', 0 => 'الأحد', 1 => 'الاثنين', 2 => 'الثلاثاء',
        3 => 'الأربعاء', 4 => 'الخميس', 5 => 'الجمعة',
    ];

    public function __invoke(
        Request $request,
        TelegramBotApi $bot,
        PlanCalculator $planCalculator,
        TelegramAiAssistant $aiAssistant,
        TelegramGpaCalculator $gpaCalculator
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
            $callbackData = (string) ($callbackQuery['data'] ?? '');

            if (str_starts_with($callbackData, 'sched:')) {
                $this->handleScheduleCallback($bot, $callbackQuery);
            } else {
                $this->handleMenuCallback($bot, $callbackQuery);
            }

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
                "جرّب تكتب \"خطتي\" أو \"معدلي\" أو \"جدولي\"، أو اكتب \"القائمة\" لتختار أداة الذكاء الاصطناعي (مساعد أسئلة/مصحّح أكواد/مولّد أسئلة/تلخيص ملفات)، أو اكتب \"مساعدة\" تشوف كل الأوامر."
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

        /*
         * الطالب بمنتصف محادثة "إضافة/تعديل/حذف محاضرة" (خطوة بخطوة) —
         * أي رسالة نصية بعدها تُعتبر جواب على السؤال الحالي بهاي
         * العملية، مش سؤال حر ولا أمر جديد، بغض النظر عن الوضع المختار
         * بالقائمة الذكية. صور/ملفات بمنتصف هاي العملية مش مدعومة —
         * رسالة توضيحية بدل ما تروح غلط للتلخيص.
         */
        if ($link->isInScheduleFlow()) {
            if ($hasMedia) {
                $bot->sendMessage($chatId, 'أنت بمنتصف عملية جدول حاليًا 🙂 اكتب ردّك كنص، أو اكتب "إلغاء" لإيقافها.');

                return response()->json(['ok' => true]);
            }

            $this->handleScheduleTextInput($bot, $link, $chatId, $text);

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

        if (in_array($normalized, ['معدلي', 'المعدل', 'gpa'], true)) {
            $bot->sendMessage($chatId, $gpaCalculator->formatForTelegram($link->user));

            return response()->json(['ok' => true]);
        }

        if (in_array($normalized, ['مساعدة', 'help', 'أوامر'], true)) {
            $bot->sendMessage(
                $chatId,
                "الأوامر المتاحة حاليًا (نسخة تجريبية، رح تكبر تدريجيًا):\n\n".
                "📊 خطتي — تقدّمك نحو التخرّج (الساعات المعتمدة).\n".
                "🧮 معدلي — معدّلك التراكمي (عام + تفصيل لكل سنة وفصل)، من نفس علاماتك المسجَّلة بحاسبة المعدل بالموقع.\n".
                "📅 جدولي — جدول محاضراتك الأسبوعي + تذكير تلقائي قبل كل محاضرة بربع ساعة (وفيها أزرار إضافة/تعديل/حذف).\n".
                "➕ إضافة محاضرة / ✏️ تعديل محاضرة / 🗑️ حذف محاضرة — تديرها كلها من هون بدون فتح الموقع.\n".
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

        if (in_array($normalized, ['جدولي', 'جدول', 'الجدول', 'schedule'], true)) {
            $this->replyWithScheduleSummary($bot, $chatId, $link);

            return response()->json(['ok' => true]);
        }

        if (in_array($normalized, ['تفعيل التذكيرات', 'تشغيل التذكيرات'], true)) {
            $link->update(['reminders_enabled' => true]);
            $bot->sendMessage($chatId, '🔔 تم تفعيل تذكيرات المحاضرات — رح أذكّرك قبل كل محاضرة بربع ساعة.');

            return response()->json(['ok' => true]);
        }

        if (in_array($normalized, ['إيقاف التذكيرات', 'ايقاف التذكيرات'], true)) {
            $link->update(['reminders_enabled' => false]);
            $bot->sendMessage($chatId, '🔕 تم إيقاف تذكيرات المحاضرات. اكتب "تفعيل التذكيرات" لإرجاعها بأي وقت.');

            return response()->json(['ok' => true]);
        }

        if (in_array($normalized, ['إضافة محاضرة', 'اضافة محاضرة'], true)) {
            $this->startAddFlow($bot, $link, $chatId);

            return response()->json(['ok' => true]);
        }

        if (in_array($normalized, ['تعديل محاضرة'], true)) {
            $this->startEditFlow($bot, $link, $chatId);

            return response()->json(['ok' => true]);
        }

        if (in_array($normalized, ['حذف محاضرة'], true)) {
            $this->startDeleteFlow($bot, $link, $chatId);

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
     * "جدولي" — عرض للجدول الأسبوعي مباشرة من ScheduleLecture (نفس
     * الجدول يلي يبنيه الطالب بصفحة "جدول المحاضرات" بالموقع)، بلا أي
     * حساب أو تكرار منطق — تمامًا نفس فلسفة "خطتي" مع PlanCalculator.
     * أول جزء من ميزة "الجدول + التذكيرات" (عرض فقط حاليًا) — الإضافة
     * والتعديل والحذف من داخل البوت نفسه رح تُبنى بخطوة لاحقة منفصلة.
     */
    private function replyWithScheduleSummary(TelegramBotApi $bot, int|string $chatId, TelegramLink $link): void
    {
        $lectures = ScheduleLecture::query()
            ->where('user_id', $link->user_id)
            ->orderBy('start_time')
            ->get();

        if ($lectures->isEmpty()) {
            $bot->sendMessage(
                $chatId,
                '📅 جدولك فاضي حاليًا. ضيف أول محاضرة من هون مباشرة، أو من صفحة "جدول المحاضرات" بالموقع.',
                [[['text' => '➕ إضافة محاضرة', 'callback_data' => 'sched:add']]]
            );

            return;
        }

        $today = (int) now(config('app.timezone'))->dayOfWeek;
        $lines = ['📅 <b>جدولك الأسبوعي</b>', ''];

        foreach (self::DAY_LABELS as $dayKey => $dayLabel) {
            $dayLectures = $lectures->filter(
                fn (ScheduleLecture $lecture) => in_array($dayKey, is_array($lecture->days) ? $lecture->days : [], true)
            );

            $isToday = $dayKey === $today;

            if ($dayLectures->isEmpty() && ! $isToday) {
                continue;
            }

            $lines[] = ($isToday ? '📌 ' : '') . '<b>' . $dayLabel . '</b>' . ($isToday ? ' (اليوم)' : '');

            if ($dayLectures->isEmpty()) {
                $lines[] = 'لا محاضرات.';
            } else {
                foreach ($dayLectures as $lecture) {
                    $typeIcon = $lecture->type === 'online' ? '🌐' : '🏫';
                    $instructor = trim((string) $lecture->instructor);
                    $name = TelegramBotApi::escapeHtml((string) $lecture->name);
                    $line = "🕐 {$lecture->start_time}–{$lecture->end_time} {$typeIcon} {$name}";

                    if ($instructor !== '') {
                        $line .= ' — ' . TelegramBotApi::escapeHtml($instructor);
                    }

                    $lines[] = $line;
                }
            }

            $lines[] = '';
        }

        $remindersStatus = $link->reminders_enabled
            ? '🔔 التذكيرات مفعّلة (اكتب "إيقاف التذكيرات" لإيقافها)'
            : '🔕 التذكيرات متوقفة (اكتب "تفعيل التذكيرات" لتشغيلها)';
        $lines[] = $remindersStatus;

        $keyboard = [[
            ['text' => '➕ إضافة محاضرة', 'callback_data' => 'sched:add'],
            ['text' => '✏️ تعديل', 'callback_data' => 'sched:edit'],
            ['text' => '🗑️ حذف', 'callback_data' => 'sched:delete'],
        ]];

        $bot->sendMessage($chatId, implode("\n", $lines), $keyboard);
    }

    // تسميات حقول "تعديل محاضرة" — نفس أسماء أعمدة ScheduleLecture.
    private const EDIT_FIELD_LABELS = [
        'name' => 'الاسم', 'instructor' => 'الأستاذ', 'type' => 'النوع',
        'days' => 'الأيام', 'start_time' => 'وقت البداية', 'end_time' => 'وقت النهاية',
    ];

    /*
     * ثاني جزء من ميزة "الجدول" — إضافة/تعديل/حذف محاضرة من داخل
     * المحادثة نفسها، خطوة بخطوة، بدون فتح الموقع أبدًا. الحالة
     * (أي خطوة/أي بيانات جُمعت لحد الآن) تُخزَّن بعمود
     * telegram_links.pending_action (JSON) — راجع
     * TelegramLink::isInScheduleFlow() والتحقق منها بأول __invoke().
     *
     * تسلسل "إضافة": name → instructor (اختياري) → type (أزرار) →
     * days (أزرار متعددة) → start_time → end_time → confirm (أزرار).
     * تسلسل "تعديل": pick_lecture (أزرار) → pick_field (أزرار) → نفس
     * منطق الحقل المفرد (نص أو أزرار حسب نوعه) → حفظ فوري بلا تأكيد
     * إضافي. تسلسل "حذف": pick_lecture (أزرار) → confirm (أزرار).
     */
    private function startAddFlow(TelegramBotApi $bot, TelegramLink $link, int|string $chatId): void
    {
        $link->update(['pending_action' => ['action' => 'add', 'step' => 'name', 'lecture_id' => null, 'data' => []]]);

        $bot->sendMessage(
            $chatId,
            "📝 <b>إضافة محاضرة جديدة</b>\n\nاكتب اسم المادة/المحاضرة:\n\n(تقدر تكتب \"إلغاء\" بأي وقت لإيقاف العملية)"
        );
    }

    private function startEditFlow(TelegramBotApi $bot, TelegramLink $link, int|string $chatId): void
    {
        $lectures = ScheduleLecture::query()->where('user_id', $link->user_id)->orderBy('start_time')->get();

        if ($lectures->isEmpty()) {
            $bot->sendMessage($chatId, 'جدولك فاضي — ما في محاضرات لتعديلها. اكتب "إضافة محاضرة" لتضيف وحدة.');

            return;
        }

        $link->update(['pending_action' => ['action' => 'edit', 'step' => 'pick_lecture', 'lecture_id' => null, 'data' => []]]);

        $bot->sendMessage($chatId, '✏️ اختر المحاضرة يلي بدك تعدّلها:', $this->buildLecturePickerKeyboard($lectures, 'edit_pick'));
    }

    private function startDeleteFlow(TelegramBotApi $bot, TelegramLink $link, int|string $chatId): void
    {
        $lectures = ScheduleLecture::query()->where('user_id', $link->user_id)->orderBy('start_time')->get();

        if ($lectures->isEmpty()) {
            $bot->sendMessage($chatId, 'جدولك فاضي — ما في محاضرات لحذفها.');

            return;
        }

        $link->update(['pending_action' => ['action' => 'delete', 'step' => 'pick_lecture', 'lecture_id' => null, 'data' => []]]);

        $bot->sendMessage($chatId, '🗑️ اختر المحاضرة يلي بدك تحذفها:', $this->buildLecturePickerKeyboard($lectures, 'delete_pick'));
    }

    /**
     * @param \Illuminate\Support\Collection<int, ScheduleLecture> $lectures
     */
    private function buildLecturePickerKeyboard($lectures, string $callbackPrefix): array
    {
        $rows = [];

        foreach ($lectures as $lecture) {
            $label = mb_substr((string) $lecture->name, 0, 30) . ' (' . $lecture->start_time . ')';
            $rows[] = [['text' => $label, 'callback_data' => "sched:{$callbackPrefix}:{$lecture->id}"]];
        }

        $rows[] = [['text' => '❌ إلغاء', 'callback_data' => 'sched:cancel']];

        return $rows;
    }

    /*
     * أي نص عادي أثناء عملية جدول جارية — الخطوات النصية فقط (الأزرار
     * تُعالج بـhandleScheduleCallback). "إلغاء" شغّال بأي خطوة.
     */
    private function handleScheduleTextInput(TelegramBotApi $bot, TelegramLink $link, int|string $chatId, string $text): void
    {
        $normalized = trim($text);

        if (in_array($normalized, ['إلغاء', 'الغاء', 'cancel'], true)) {
            $link->update(['pending_action' => null]);
            $bot->sendMessage($chatId, 'تم إلغاء العملية ✅');

            return;
        }

        $pending = $link->pending_action;
        $step = (string) ($pending['step'] ?? '');
        $action = (string) ($pending['action'] ?? '');
        $data = (array) ($pending['data'] ?? []);

        switch ($step) {
            case 'name':
                $name = trim($text);

                if ($name === '' || mb_strlen($name) > 150) {
                    $bot->sendMessage($chatId, 'اسم غير صالح (لازم يكون بين حرف و١٥٠ حرف). جرّب كتابة الاسم مرة ثانية:');

                    return;
                }

                if ($action === 'edit_field') {
                    $this->applyEditFieldAndFinish($bot, $link, $chatId, 'name', $name);

                    return;
                }

                $data['name'] = $name;
                $link->update(['pending_action' => ['action' => $action, 'step' => 'instructor', 'lecture_id' => null, 'data' => $data]]);
                $bot->sendMessage($chatId, 'تمام 👍 اكتب اسم الدكتور/المدرّس (أو اكتب "تخطي" لو ما بدك تحدد):');

                return;

            case 'instructor':
                $instructor = in_array(trim($text), ['تخطي', 'skip'], true) ? '' : trim($text);

                if (mb_strlen($instructor) > 150) {
                    $bot->sendMessage($chatId, 'الاسم طويل جدًا. جرّب اسم أقصر أو اكتب "تخطي":');

                    return;
                }

                $data['instructor'] = $instructor;

                if ($action === 'edit_field') {
                    $this->applyEditFieldAndFinish($bot, $link, $chatId, 'instructor', $instructor);

                    return;
                }

                $link->update(['pending_action' => ['action' => $action, 'step' => 'type', 'lecture_id' => null, 'data' => $data]]);
                $this->sendTypePicker($bot, $chatId);

                return;

            case 'start_time':
                if (! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', trim($text))) {
                    $bot->sendMessage($chatId, 'صيغة الوقت غير صحيحة 🙂 اكتبه هيك مثلًا: 10:00');

                    return;
                }

                $startTime = trim($text);

                if ($action === 'edit_field') {
                    $lecture = ScheduleLecture::query()
                        ->where('id', $pending['lecture_id'] ?? 0)
                        ->where('user_id', $link->user_id)
                        ->first();

                    if ($lecture && $startTime >= (string) $lecture->end_time) {
                        $bot->sendMessage($chatId, 'وقت البداية لازم يكون قبل وقت النهاية الحالي (' . $lecture->end_time . '). جرّب وقت تاني:');

                        return;
                    }

                    $this->applyEditFieldAndFinish($bot, $link, $chatId, 'start_time', $startTime);

                    return;
                }

                $data['start_time'] = $startTime;
                $link->update(['pending_action' => ['action' => $action, 'step' => 'end_time', 'lecture_id' => null, 'data' => $data]]);
                $bot->sendMessage($chatId, 'ومتى بتخلص؟ اكتب وقت النهاية (مثلًا: 11:30):');

                return;

            case 'end_time':
                if (! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', trim($text))) {
                    $bot->sendMessage($chatId, 'صيغة الوقت غير صحيحة 🙂 اكتبه هيك مثلًا: 11:30');

                    return;
                }

                $endTime = trim($text);

                if ($action === 'edit_field') {
                    $lecture = ScheduleLecture::query()
                        ->where('id', $pending['lecture_id'] ?? 0)
                        ->where('user_id', $link->user_id)
                        ->first();

                    if ($lecture && $endTime <= (string) $lecture->start_time) {
                        $bot->sendMessage($chatId, 'وقت النهاية لازم يكون بعد وقت البداية الحالي (' . $lecture->start_time . '). جرّب وقت تاني:');

                        return;
                    }

                    $this->applyEditFieldAndFinish($bot, $link, $chatId, 'end_time', $endTime);

                    return;
                }

                if ($endTime <= (string) ($data['start_time'] ?? '')) {
                    $bot->sendMessage($chatId, 'وقت النهاية لازم يكون بعد وقت البداية (' . ($data['start_time'] ?? '') . '). جرّب وقت تاني:');

                    return;
                }

                $data['end_time'] = $endTime;
                $link->update(['pending_action' => ['action' => $action, 'step' => 'confirm', 'lecture_id' => null, 'data' => $data]]);
                $this->sendAddConfirmation($bot, $link, $chatId, $data);

                return;

            default:
                $bot->sendMessage($chatId, 'استخدم الأزرار يلي فوق 🙂 أو اكتب "إلغاء" لإيقاف العملية.');
        }
    }

    private function sendTypePicker(TelegramBotApi $bot, int|string $chatId): void
    {
        $bot->sendMessage($chatId, 'شو نوع المحاضرة؟', [[
            ['text' => '🏫 حضوري', 'callback_data' => 'sched:type:in_person'],
            ['text' => '🌐 اونلاين', 'callback_data' => 'sched:type:online'],
        ]]);
    }

    private function sendDayPicker(TelegramBotApi $bot, int|string $chatId): void
    {
        $rows = [];
        $rowKeys = array_keys(self::DAY_LABELS);

        foreach (array_chunk($rowKeys, 2) as $pair) {
            $rows[] = array_map(
                fn ($dayKey) => ['text' => self::DAY_LABELS[$dayKey], 'callback_data' => "sched:day:{$dayKey}"],
                $pair
            );
        }

        $rows[] = [['text' => '✅ تم الاختيار', 'callback_data' => 'sched:days_done']];

        $bot->sendMessage($chatId, 'شو أيام المحاضرة؟ اضغط كل يوم بدك ياه (فيك تختار أكثر من يوم)، وبعدين اضغط "تم الاختيار":', $rows);
    }

    private function sendAddConfirmation(TelegramBotApi $bot, TelegramLink $link, int|string $chatId, array $data): void
    {
        $conflictWarning = $this->buildConflictWarning(
            $link->user_id,
            array_map('intval', (array) ($data['days'] ?? [])),
            (string) ($data['start_time'] ?? ''),
            (string) ($data['end_time'] ?? ''),
            null
        );

        $bot->sendMessage(
            $chatId,
            $conflictWarning . "راجع البيانات قبل الحفظ:\n\n" . $this->formatLectureDataSummary($data),
            [[
                ['text' => '✅ تأكيد وحفظ', 'callback_data' => 'sched:confirm_add'],
                ['text' => '❌ إلغاء', 'callback_data' => 'sched:cancel'],
            ]]
        );
    }

    /*
     * تنبيه تعارض بالوقت — طلب صريح من الطالب: لو محاضرة جديدة أو
     * معدَّلة بتشارك يوم وتتقاطع وقتيًا مع محاضرة موجودة أصلًا بنفس
     * جدوله، نحذّره بوضوح قبل/بعد الحفظ. ما بنمنع الحفظ (ممكن تكون
     * محاضرة تعويضية أو تعارض مقصود)، بس نضمن الطالب يشوف التحذير.
     */
    private function findConflicts(int $userId, array $days, string $startTime, string $endTime, ?int $excludeLectureId): \Illuminate\Support\Collection
    {
        if ($days === [] || $startTime === '' || $endTime === '') {
            return collect();
        }

        return ScheduleLecture::query()
            ->where('user_id', $userId)
            ->when($excludeLectureId, fn ($q) => $q->where('id', '!=', $excludeLectureId))
            ->get()
            ->filter(function (ScheduleLecture $lecture) use ($days, $startTime, $endTime) {
                $lectureDays = is_array($lecture->days) ? $lecture->days : [];

                if (array_intersect($lectureDays, $days) === []) {
                    return false;
                }

                // تقاطع وقتين: بداية الأول قبل نهاية الثاني وبالعكس.
                return $startTime < (string) $lecture->end_time && (string) $lecture->start_time < $endTime;
            });
    }

    private function buildConflictWarning(int $userId, array $days, string $startTime, string $endTime, ?int $excludeLectureId): string
    {
        $conflicts = $this->findConflicts($userId, $days, $startTime, $endTime, $excludeLectureId);

        if ($conflicts->isEmpty()) {
            return '';
        }

        $lines = ["⚠️ <b>تنبيه: تعارض بالوقت مع:</b>"];

        foreach ($conflicts as $conflict) {
            $conflictDays = collect(is_array($conflict->days) ? $conflict->days : [])
                ->map(fn ($d) => self::DAY_LABELS[$d] ?? $d)
                ->implode('، ');

            $lines[] = '• ' . TelegramBotApi::escapeHtml((string) $conflict->name) .
                ' (' . $conflictDays . ' — ' . $conflict->start_time . '–' . $conflict->end_time . ')';
        }

        return implode("\n", $lines) . "\n\n";
    }

    private function formatLectureDataSummary(array $data): string
    {
        $typeLabel = ($data['type'] ?? '') === 'online' ? '🌐 اونلاين' : '🏫 حضوري';
        $daysLabel = collect($data['days'] ?? [])
            ->map(fn ($d) => self::DAY_LABELS[$d] ?? $d)
            ->implode('، ');
        $instructor = trim((string) ($data['instructor'] ?? ''));

        return '📚 ' . TelegramBotApi::escapeHtml((string) ($data['name'] ?? '')) . "\n" .
            ($instructor !== '' ? '👤 ' . TelegramBotApi::escapeHtml($instructor) . "\n" : '') .
            '🗓️ ' . $daysLabel . "\n" .
            '🕐 ' . ($data['start_time'] ?? '') . '–' . ($data['end_time'] ?? '') . "\n" .
            $typeLabel;
    }

    /*
     * ضغطات أزرار عملية الجدول (add/edit/delete) — كل الأزرار يلي
     * تبدأ بـ"sched:". لازم نتحقق من الحساب المربوط + ملكية المحاضرة
     * (user_id) بكل خطوة، وليس الاعتماد على أي شيء بالـcallback_data
     * نفسه غير المعرّفات الرقمية.
     */
    private function handleScheduleCallback(TelegramBotApi $bot, array $callbackQuery): void
    {
        $callbackId = (string) ($callbackQuery['id'] ?? '');
        $chatId = $callbackQuery['message']['chat']['id'] ?? null;
        $data = (string) ($callbackQuery['data'] ?? '');
        $action = substr($data, strlen('sched:'));
        [$key, $arg] = array_pad(explode(':', $action, 2), 2, null);

        if (! $chatId) {
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

        $pending = $link->pending_action;

        switch ($key) {
            case 'add':
                $bot->answerCallbackQuery($callbackId);
                $this->startAddFlow($bot, $link, $chatId);

                return;

            case 'edit':
                $bot->answerCallbackQuery($callbackId);
                $this->startEditFlow($bot, $link, $chatId);

                return;

            case 'delete':
                $bot->answerCallbackQuery($callbackId);
                $this->startDeleteFlow($bot, $link, $chatId);

                return;

            case 'cancel':
                $link->update(['pending_action' => null]);
                $bot->answerCallbackQuery($callbackId, 'تم الإلغاء.');

                return;

            case 'type':
                if (($pending['step'] ?? null) !== 'type' && ($pending['step'] ?? null) !== 'edit_type') {
                    $bot->answerCallbackQuery($callbackId);

                    return;
                }

                $typeValue = $arg === 'online' ? 'online' : 'in_person';
                $typeLabel = $typeValue === 'online' ? '🌐 اونلاين' : '🏫 حضوري';

                if (($pending['action'] ?? null) === 'edit_field') {
                    $bot->answerCallbackQuery($callbackId, 'تم اختيار: ' . $typeLabel);
                    $this->applyEditFieldAndFinish($bot, $link, $chatId, 'type', $typeValue);

                    return;
                }

                $newData = (array) ($pending['data'] ?? []);
                $newData['type'] = $typeValue;
                $link->update(['pending_action' => ['action' => $pending['action'], 'step' => 'days', 'lecture_id' => null, 'data' => $newData]]);
                $bot->answerCallbackQuery($callbackId, 'تم اختيار: ' . $typeLabel);
                $this->sendDayPicker($bot, $chatId);

                return;

            case 'day':
                if (($pending['step'] ?? null) !== 'days') {
                    $bot->answerCallbackQuery($callbackId);

                    return;
                }

                $dayKey = (int) $arg;
                $newData = (array) ($pending['data'] ?? []);
                $days = array_map('intval', (array) ($newData['days'] ?? []));

                if (in_array($dayKey, $days, true)) {
                    $days = array_values(array_diff($days, [$dayKey]));
                } else {
                    $days[] = $dayKey;
                }

                $newData['days'] = $days;
                $link->update(['pending_action' => ['action' => $pending['action'], 'step' => 'days', 'lecture_id' => $pending['lecture_id'] ?? null, 'data' => $newData]]);

                $selectedLabel = empty($days)
                    ? 'ما في أيام مختارة'
                    : collect($days)->map(fn ($d) => self::DAY_LABELS[$d] ?? $d)->implode('، ');
                $bot->answerCallbackQuery($callbackId, 'الأيام المختارة: ' . $selectedLabel);

                return;

            case 'days_done':
                $newData = (array) ($pending['data'] ?? []);
                $days = (array) ($newData['days'] ?? []);

                if (empty($days)) {
                    $bot->answerCallbackQuery($callbackId, 'اختر يوم واحد على الأقل!');

                    return;
                }

                if (($pending['action'] ?? null) === 'edit_field') {
                    $bot->answerCallbackQuery($callbackId);
                    $this->applyEditFieldAndFinish($bot, $link, $chatId, 'days', $days);

                    return;
                }

                $link->update(['pending_action' => ['action' => $pending['action'], 'step' => 'start_time', 'lecture_id' => null, 'data' => $newData]]);
                $bot->answerCallbackQuery($callbackId);
                $bot->sendMessage($chatId, 'متى بتبدأ؟ اكتب وقت البداية (مثلًا: 10:00):');

                return;

            case 'confirm_add':
                $bot->answerCallbackQuery($callbackId);
                $this->saveNewLectureFromPending($bot, $link, $chatId);

                return;

            case 'edit_pick':
                $lectureId = (int) $arg;
                $lecture = ScheduleLecture::query()->where('id', $lectureId)->where('user_id', $link->user_id)->first();

                if (! $lecture) {
                    $bot->answerCallbackQuery($callbackId, 'هذه المحاضرة مش موجودة.');

                    return;
                }

                $link->update(['pending_action' => ['action' => 'edit', 'step' => 'pick_field', 'lecture_id' => $lectureId, 'data' => []]]);
                $bot->answerCallbackQuery($callbackId);
                $bot->sendMessage($chatId, 'شو بدك تعدّل بـ"' . TelegramBotApi::escapeHtml((string) $lecture->name) . '"؟', $this->buildFieldPickerKeyboard());

                return;

            case 'edit_field':
                if (($pending['step'] ?? null) !== 'pick_field' || ! array_key_exists($arg, self::EDIT_FIELD_LABELS)) {
                    $bot->answerCallbackQuery($callbackId);

                    return;
                }

                $bot->answerCallbackQuery($callbackId);
                $this->startEditFieldStep($bot, $link, $chatId, (string) $pending['lecture_id'], $arg);

                return;

            case 'delete_pick':
                $lectureId = (int) $arg;
                $lecture = ScheduleLecture::query()->where('id', $lectureId)->where('user_id', $link->user_id)->first();

                if (! $lecture) {
                    $bot->answerCallbackQuery($callbackId, 'هذه المحاضرة مش موجودة.');

                    return;
                }

                $link->update(['pending_action' => ['action' => 'delete', 'step' => 'confirm', 'lecture_id' => $lectureId, 'data' => []]]);
                $bot->answerCallbackQuery($callbackId);
                $bot->sendMessage(
                    $chatId,
                    'متأكد بدك تحذف "' . TelegramBotApi::escapeHtml((string) $lecture->name) . '"؟',
                    [[
                        ['text' => '✅ نعم احذف', 'callback_data' => "sched:delete_confirm:{$lectureId}"],
                        ['text' => '❌ إلغاء', 'callback_data' => 'sched:cancel'],
                    ]]
                );

                return;

            case 'delete_confirm':
                $lectureId = (int) $arg;

                if (($pending['lecture_id'] ?? null) != $lectureId) {
                    $bot->answerCallbackQuery($callbackId);

                    return;
                }

                $lecture = ScheduleLecture::query()->where('id', $lectureId)->where('user_id', $link->user_id)->first();

                if ($lecture) {
                    $lecture->delete();
                }

                $link->update(['pending_action' => null]);
                $bot->answerCallbackQuery($callbackId, 'تم الحذف.');
                $bot->sendMessage($chatId, '🗑️ تم حذف المحاضرة بنجاح.');

                return;

            default:
                $bot->answerCallbackQuery($callbackId);
        }
    }

    private function buildFieldPickerKeyboard(): array
    {
        $rows = [];

        foreach (self::EDIT_FIELD_LABELS as $field => $label) {
            $rows[] = [['text' => $label, 'callback_data' => "sched:edit_field:{$field}"]];
        }

        $rows[] = [['text' => '❌ إلغاء', 'callback_data' => 'sched:cancel']];

        return $rows;
    }

    /*
     * بدء خطوة تعديل حقل مفرد لمحاضرة موجودة — نفس منطق جمع القيمة
     * المستخدَم بمسار "إضافة" (نص أو أزرار حسب الحقل)، لكن بـ
     * action='edit_field' حتى handleScheduleTextInput/handleScheduleCallback
     * يطبّقوا القيمة فورًا بدل ما يكملوا لسلسلة الإضافة الكاملة.
     */
    private function startEditFieldStep(TelegramBotApi $bot, TelegramLink $link, int|string $chatId, string $lectureId, string $field): void
    {
        $link->update(['pending_action' => [
            'action' => 'edit_field',
            'step' => $field === 'type' ? 'edit_type' : $field,
            'lecture_id' => (int) $lectureId,
            'data' => [],
        ]]);

        match ($field) {
            'name' => $bot->sendMessage($chatId, 'اكتب الاسم الجديد:'),
            'instructor' => $bot->sendMessage($chatId, 'اكتب اسم الدكتور/المدرّس الجديد (أو "تخطي" لإزالته):'),
            'type' => $this->sendTypePicker($bot, $chatId),
            'days' => $this->sendDayPicker($bot, $chatId),
            'start_time' => $bot->sendMessage($chatId, 'اكتب وقت البداية الجديد (مثلًا: 10:00):'),
            'end_time' => $bot->sendMessage($chatId, 'اكتب وقت النهاية الجديد (مثلًا: 11:30):'),
            default => null,
        };
    }

    private function applyEditFieldAndFinish(TelegramBotApi $bot, TelegramLink $link, int|string $chatId, string $field, mixed $value): void
    {
        $pending = $link->pending_action;
        $lecture = ScheduleLecture::query()
            ->where('id', $pending['lecture_id'] ?? 0)
            ->where('user_id', $link->user_id)
            ->first();

        $link->update(['pending_action' => null]);

        if (! $lecture) {
            $bot->sendMessage($chatId, 'تعذّر إيجاد هذه المحاضرة (ممكن اتحذفت). جرّب من جديد.');

            return;
        }

        $lecture->update([$field => $value]);

        $bot->sendMessage($chatId, '✅ تم تحديث "' . self::EDIT_FIELD_LABELS[$field] . '" بنجاح.');

        // فقط الحقول يلي تأثّر على التوقيت/الأيام تستاهل فحص تعارض جديد.
        if (in_array($field, ['days', 'start_time', 'end_time'], true)) {
            $fresh = $lecture->fresh();
            $warning = $this->buildConflictWarning(
                $link->user_id,
                is_array($fresh->days) ? $fresh->days : [],
                (string) $fresh->start_time,
                (string) $fresh->end_time,
                $fresh->id
            );

            if ($warning !== '') {
                $bot->sendMessage($chatId, $warning);
            }
        }
    }

    private function saveNewLectureFromPending(TelegramBotApi $bot, TelegramLink $link, int|string $chatId): void
    {
        $pending = $link->pending_action;
        $data = (array) ($pending['data'] ?? []);
        $link->update(['pending_action' => null]);

        ScheduleLecture::create([
            'user_id' => $link->user_id,
            'course_key' => null,
            'name' => (string) ($data['name'] ?? ''),
            'instructor' => (string) ($data['instructor'] ?? ''),
            'type' => ($data['type'] ?? '') === 'online' ? 'online' : 'in_person',
            'days' => array_values(array_map('intval', (array) ($data['days'] ?? []))),
            'start_time' => (string) ($data['start_time'] ?? ''),
            'end_time' => (string) ($data['end_time'] ?? ''),
        ]);

        $bot->sendMessage($chatId, "✅ <b>تمت إضافة المحاضرة بنجاح!</b>\n\n" . $this->formatLectureDataSummary($data) . "\n\nاكتب \"جدولي\" لتشوف جدولك المحدَّث.");
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
