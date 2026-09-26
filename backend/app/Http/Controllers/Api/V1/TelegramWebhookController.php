<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\Course;
use App\Models\CourseFile;
use App\Models\GpaEntry;
use App\Models\ScheduleLecture;
use App\Models\TelegramLink;
use App\Models\Tool;
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
 * لاستدعائه من الخادم. العلامات نفسها مقروءة/مكتوبة مباشرة من جدول
 * gpa_entries (نفس الجدول الذي تقرأ/تكتب منه صفحة "حاسبة المعدل"
 * بالموقع عبر GpaController)، فأي تعديل هون أو هناك ينعكس بالمكانين
 * فورًا وبلا أي تأخير — لكن صيغة الحساب نفسها مكرَّرة بقصد بين الطرفين
 * (راجع تنبيه الصيانة أعلى TelegramGpaCalculator).
 *
 * تسجيل/تعديل/حذف علامة أي مادة (إجباري بأي سنة/فصل، أو اختياري) من
 * داخل البوت نفسه — بنفس فلسفة "إضافة/تعديل/حذف محاضرة" بالجدول
 * تمامًا: محادثة متعددة الخطوات بأزرار inline، مخزَّنة بنفس عمود
 * telegram_links.pending_action (action يبدأ بـ"gpa_" هون تمييزًا عن
 * "add"/"edit"/"delete" الخاصة بالجدول — راجع دوال handleGpaCallback/
 * handleGpaTextInput أسفل دوال الجدول).
 *
 * Inline Mode: طالب يكتب "@اسم_البوت بحث" بأي محادثة تيليجرام (حتى لو
 * مجموعة دراسية ما فيها البوت مضاف أصلًا) فيظهرله بحث فوري بمواد
 * وملفات وأدوات الموقع، يقدر يختار نتيجة منها فتُرسل كرسالة جاهزة
 * (بعنوان + رابط مباشر للموقع) بهاي المحادثة — بلا فتح البوت نهائيًا.
 * نفس منطق SearchController بالضبط (بحث عام، بلا حاجة لحساب مربوط،
 * لأن كل ما يُرجعه أصلًا عام على الموقع) — راجع handleInlineQuery.
 * يتطلب تفعيل يدوي لمرة واحدة من BotFather (/setinline) — راجع
 * telegram_bot_step_inline_mode_botfather_setup.txt.
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

    // مواضيع جاهزة بأزرار لمولّد الأسئلة — يبقى ممكن كمان كتابة أي
    // موضوع تاني حر كنص عادي (مرحلة ٠).
    private const QUIZ_SUBJECTS = ['هياكل بيانات', 'معمارية حاسوب', 'شبكات', 'أنظمة تشغيل', 'قواعد بيانات'];

    /*
     * القائمة الرئيسية (مرحلة ١) — أسماء الأزرار الدائمة (ReplyKeyboardMarkup)
     * كثوابت حتى ما نكرر النص الحرفي بأكثر من مكان (الكيبورد نفسه +
     * أماكن المطابقة بـ__invoke()).
     */
    private const MAIN_MENU_PLAN = '📊 خطتي';
    private const MAIN_MENU_GPA = '🧮 معدلي';
    private const MAIN_MENU_SCHEDULE = '📅 جدولي';
    private const MAIN_MENU_COURSES = '📚 المساقات';
    private const MAIN_MENU_TOOLS = '🧰 القائمة الذكية';
    private const MAIN_MENU_HELP = '❓ مساعدة';

    private const MAIN_MENU_SEARCH = '🔍 بحث';

    private const MAIN_MENU_KEYBOARD = [
        [['text' => self::MAIN_MENU_PLAN], ['text' => self::MAIN_MENU_GPA]],
        [['text' => self::MAIN_MENU_SCHEDULE], ['text' => self::MAIN_MENU_COURSES]],
        [['text' => self::MAIN_MENU_SEARCH], ['text' => self::MAIN_MENU_TOOLS]],
        [['text' => self::MAIN_MENU_HELP]],
    ];

    // زر إضافي يظهر فقط لحسابات الإدارة (User::isStaff()) — راجع
    // buildMainMenuKeyboard().
    private const MAIN_MENU_ADMIN_ANNOUNCE = '📢 نشر إعلان';

    // حجم صفحة قوائم "المساقات" (اختياريات/ملفات) — نفس فلسفة GPA_PAGE_SIZE.
    private const COURSE_HUB_PAGE_SIZE = 8;

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
        // لغات وصف عتاد رقمي/تجميع — مهمة تحديدًا لتخصص هندسة أنظمة
        // الحاسوب (مواد الدوائر الرقمية/المعمارية)، أُضيفت مع المرحلة ٠.
        'v', 'vhd', 'vhdl', 'asm', 's',
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

        /*
         * inline_query: طالب كتب "@اسم_البوت بحث" بأي محادثة (حتى لو
         * مجموعة ما فيها البوت أصلًا) — لازم يُعالج قبل أي شيء تاني،
         * لأنه لا $message ولا $callback_query بهاي الحالة إطلاقًا.
         * لا يحتاج حساب مربوط (نفس فلسفة SearchController العامة —
         * بحث بمحتوى الموقع العام لا ببيانات شخصية).
         */
        $inlineQuery = $request->input('inline_query');
        if (is_array($inlineQuery)) {
            $this->handleInlineQuery($bot, $inlineQuery);

            return response()->json(['ok' => true]);
        }

        $callbackQuery = $request->input('callback_query');
        if (is_array($callbackQuery)) {
            $callbackData = (string) ($callbackQuery['data'] ?? '');

            if (str_starts_with($callbackData, 'sched:')) {
                $this->handleScheduleCallback($bot, $callbackQuery);
            } elseif (str_starts_with($callbackData, 'gpa:')) {
                $this->handleGpaCallback($bot, $gpaCalculator, $callbackQuery);
            } elseif (str_starts_with($callbackData, 'quizsubj:')) {
                $this->handleQuizSubjectCallback($bot, $aiAssistant, $callbackQuery);
            } elseif (str_starts_with($callbackData, 'hub:')) {
                $this->handleCourseHubCallback($bot, $callbackQuery);
            } elseif (str_starts_with($callbackData, 'announce:')) {
                $this->handleAnnounceCallback($bot, $callbackQuery);
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
                /*
                 * علة كانت موجودة: "/start" بلا توكن (مثلًا بعد حذف
                 * سجل المحادثة بتطبيق تيليجرام — هذا لا يفصل الحساب
                 * إطلاقًا، بس تيليجرام يرسل /start تاني كأول رسالة)
                 * كانت ترجع "لازم تربط حسابك" دايمًا حتى لو الحساب
                 * مربوط فعليًا أصلًا. لازم نتحقق أول من وجود ربط سابق
                 * بنفس معرّف المحادثة قبل ما نفترض إنه غير مربوط.
                 */
                $existingLink = TelegramLink::query()
                    ->whereNotNull('telegram_chat_id')
                    ->where('telegram_chat_id', $chatId)
                    ->first();

                if ($existingLink && $existingLink->user) {
                    $bot->sendMessageWithMainMenu(
                        $chatId,
                        'أهلًا فيك من جديد 👋 حسابك مربوط أصلًا — استخدم الأزرار تحت 👇 للمتابعة.',
                        $this->buildMainMenuKeyboard($existingLink->user)
                    );

                    return response()->json(['ok' => true]);
                }

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

            $bot->sendMessageWithMainMenu(
                $chatId,
                "تم ربط حسابك بنجاح يا {$studentName} ✅\n".
                'استخدم الأزرار تحت 👇 للتنقل بين خطتك ومعدلك وجدولك وأدوات الذكاء الاصطناعي، أو زر "مساعدة" لشرح كل شيء.',
                $this->buildMainMenuKeyboard($link->user)
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
            /*
             * علة كانت موجودة: لو الطالب بمنتصف أي عملية (بحث، نشر
             * إعلان...) وضغط زر تاني من القائمة الرئيسية الدائمة
             * (خطتي/معدلي/جدولي...)، كان النص يروح غلط لمعالج العملية
             * الحالية (مثلًا يُفهم كمصطلح بحث جديد) بدل ما يُفهم كتنقّل
             * فعلي. أي ضغطة على زر رئيسي معروف لازم "تخرج" فورًا من أي
             * عملية جارية بدل ما تُحتجَز فيها.
             */
            $mainMenuButtons = [
                self::MAIN_MENU_PLAN, self::MAIN_MENU_GPA, self::MAIN_MENU_SCHEDULE,
                self::MAIN_MENU_COURSES, self::MAIN_MENU_SEARCH, self::MAIN_MENU_TOOLS,
                self::MAIN_MENU_HELP, self::MAIN_MENU_ADMIN_ANNOUNCE,
            ];

            if (! $hasMedia && in_array(trim($text), $mainMenuButtons, true)) {
                $link->update(['pending_action' => null]);
            } elseif ($hasMedia) {
                $bot->sendMessage($chatId, 'أنت بمنتصف عملية حاليًا 🙂 اكتب ردّك كنص، أو اكتب "إلغاء" لإيقافها.');

                return response()->json(['ok' => true]);
            }
        }

        if ($link->isInScheduleFlow()) {
            $pendingAction = (string) ($link->pending_action['action'] ?? '');

            if (str_starts_with($pendingAction, 'gpa')) {
                $this->handleGpaTextInput($bot, $gpaCalculator, $link, $chatId, $text);
            } elseif ($pendingAction === 'announce') {
                $this->handleAnnounceTextInput($bot, $link, $chatId, $text);
            } elseif ($pendingAction === 'search') {
                $this->handleSearchTextInput($bot, $link, $chatId, $text);
            } else {
                $this->handleScheduleTextInput($bot, $link, $chatId, $text);
            }

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

        /*
         * القائمة الرئيسية (مرحلة ١) — أزرار دائمة (ReplyKeyboardMarkup)
         * تظهر تحت مربع الكتابة دايمًا، بدل أوامر نصية لازم تُحفظ أو
         * تُكتب يدويًا. الضغط على أي زر هون بيبعت نصه كرسالة عادية
         * (آلية تيليجرام القياسية)، فالمطابقة بالأسفل ضد self::MAIN_MENU_*
         * كافية — القيم القديمة (plan/gpa/schedule/help/menu...) باقية
         * كمان للتوافق لو حدا كتبها يدويًا، لكنها لم تعد مذكورة بأي
         * رسالة للمستخدم.
         */
        if (in_array($normalized, [self::MAIN_MENU_PLAN, 'خطتي', 'plan'], true)) {
            $this->replyWithPlanSummary($bot, $chatId, $link->user, $planCalculator);

            return response()->json(['ok' => true]);
        }

        if (in_array($normalized, [self::MAIN_MENU_GPA, 'معدلي', 'المعدل', 'gpa'], true)) {
            $this->replyWithGpaSummary($bot, $chatId, $link->user, $gpaCalculator);

            return response()->json(['ok' => true]);
        }

        if (in_array($normalized, [self::MAIN_MENU_HELP, 'مساعدة', 'help', 'أوامر'], true)) {
            $bot->sendMessageWithMainMenu(
                $chatId,
                "🧭 <b>دليلك باستخدام البوت</b> (نسخة تجريبية، رح تكبر تدريجيًا)\n\n".
                "استخدم الأزرار الظاهرة تحت مربع الكتابة دايمًا للتنقل — ما في داعي تكتب أي شيء يدوي:\n\n".
                "📊 خطتي — تقدّمك نحو التخرّج (الساعات المعتمدة).\n".
                "🧮 معدلي — معدّلك التراكمي (عام + تفصيل لكل سنة وفصل) + أزرار تسجيل/تعديل/حذف علامة أي مادة، ومحاكي \"ماذا لو؟\" — كلها بمزامنة فورية مع حاسبة المعدل بالموقع.\n".
                "📅 جدولي — جدول محاضراتك الأسبوعي + تذكير تلقائي قبل كل محاضرة بربع ساعة، وأزرار إضافة/تعديل/حذف/تفعيل التذكيرات مباشرة تحت الجدول.\n".
                "📚 المساقات — تصفّح مساقات الخطة حسب السنة والفصل (أو المساقات الاختيارية أو الأدوات الهندسية)، وشوف تفاصيل أي مادة: الساعات المعتمدة، المتطلبات السابقة، الأدوات المرتبطة، ومحتواها العام — كل هذا من غير ما تفتح الموقع.\n".
                "🔍 بحث — دور بكلمة وحدة عن مادة أو محتوى أو أداة بنفس الوقت.\n".
                "🧰 القائمة الذكية — اختر أداة الذكاء الاصطناعي يلي بدك تشتغل فيها (مساعد أسئلة/مصحّح أكواد/مولّد أسئلة/تلخيص ملفات).\n".
                "📷 ابعتلي صورة صفحة أو ملف PDF — رح ألخّصلك محتواها (بأي وضع).\n".
                "💬 اكتب أي سؤال أو كود أو موضوع عادي — رح يردّ حسب الأداة المختارة حاليًا.\n".
                "🔎 بأي محادثة تيليجرام (حتى مجموعات الدراسة)، اكتب @".config('services.telegram.bot_username', 'اسم_البوت')." متبوعًا باسم مادة/أداة لتشاركها بضغطة وحدة، بدون فتح البوت.\n".
                "❓ مساعدة — هاي القائمة.",
                $this->buildMainMenuKeyboard($link->user)
            );

            return response()->json(['ok' => true]);
        }

        if (in_array($normalized, [self::MAIN_MENU_TOOLS, 'القائمة', 'menu', 'قائمة'], true)) {
            $this->sendMenu($bot, $chatId, $link->currentMode());

            return response()->json(['ok' => true]);
        }

        if (in_array($normalized, [self::MAIN_MENU_SCHEDULE, 'جدولي', 'جدول', 'الجدول', 'schedule'], true)) {
            $this->replyWithScheduleSummary($bot, $chatId, $link);

            return response()->json(['ok' => true]);
        }

        if (in_array($normalized, [self::MAIN_MENU_COURSES, 'المساقات', 'مساقات'], true)) {
            $this->sendCourseHubYearPicker($bot, $chatId);

            return response()->json(['ok' => true]);
        }

        if (in_array($normalized, [self::MAIN_MENU_SEARCH, 'بحث'], true)) {
            $link->update(['pending_action' => ['action' => 'search', 'step' => 'query', 'lecture_id' => null, 'data' => []]]);
            $bot->sendMessage($chatId, '🔍 اكتب كلمة أو اسم مادة/ملف/أداة تدور عليه (حرفين على الأقل):');

            return response()->json(['ok' => true]);
        }

        /*
         * "📢 نشر إعلان" — زر لا يظهر إلا لحسابات الإدارة (User::isStaff())
         * بلوحة القائمة الرئيسية أصلًا، لكن نتحقق هون كمان من نفس
         * الحساب المربوط فعليًا (لا من كون الزر ظاهر بواجهة المستخدم)،
         * لأنه أي نص - حتى لو حدا كتبه يدويًا بدون ما يشوف الزر - لازم
         * يمر من نفس فحص الصلاحية الحقيقي. مرحلة ٣ (نطاق أول: إعلانات
         * فقط — باقي صلاحيات الإدارة الكاملة مؤجلة لمراحل لاحقة).
         */
        if ($normalized === self::MAIN_MENU_ADMIN_ANNOUNCE) {
            if (! $link->user->isStaff()) {
                $bot->sendMessage($chatId, '⛔ هذا الخيار متاح فقط لحسابات الإدارة.');

                return response()->json(['ok' => true]);
            }

            $this->startAnnounceFlow($bot, $link, $chatId);

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
            'quiz' => "📝 <b>مولّد أسئلة</b>\nاختر موضوع جاهز من الأزرار تحت، أو اكتب اسم أي موضوع/مفهوم دراسي حر، وبولّدلك 5 أسئلة اختيار من متعدد للمراجعة.",
            'summarize' => "📄 <b>تلخيص ملفات</b>\nابعتلي صورة صفحة أو ملف PDF وبلخصلك محتواها (هاي شغالة بأي وضع أصلًا).",
        ];

        if ($mode === 'quiz') {
            $bot->sendMessage($chatId, $confirmations[$mode], $this->buildQuizSubjectKeyboard());

            return;
        }

        $bot->sendMessage($chatId, $confirmations[$mode]);
    }

    /*
     * لوحة أزرار بمواضيع جاهزة لمولّد الأسئلة (self::QUIZ_SUBJECTS)،
     * صفين بكل سطر — بديل اختياري عن كتابة الموضوع كنص حر.
     */
    private function buildQuizSubjectKeyboard(): array
    {
        $buttons = array_map(
            static fn (string $subject) => ['text' => $subject, 'callback_data' => 'quizsubj:' . $subject],
            self::QUIZ_SUBJECTS
        );

        return array_chunk($buttons, 2);
    }

    /*
     * طالب ضغط زر موضوع جاهز لمولّد الأسئلة (quizsubj:<الموضوع>) —
     * نفس منطق فرع quiz بـrouteFreeTextToAssistant() بالضبط، لكن
     * المصدر callback_data لا نص حر بالرسالة.
     */
    private function handleQuizSubjectCallback(TelegramBotApi $bot, TelegramAiAssistant $aiAssistant, array $callbackQuery): void
    {
        $callbackId = (string) ($callbackQuery['id'] ?? '');
        $chatId = $callbackQuery['message']['chat']['id'] ?? null;
        $data = (string) ($callbackQuery['data'] ?? '');
        $subject = trim(substr($data, strlen('quizsubj:')));

        if (! $chatId || $subject === '') {
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

        $bot->answerCallbackQuery($callbackId);

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
            $answer = $aiAssistant->generateQuiz($user, $subject);
            $safeAnswer = TelegramBotApi::escapeHtml($answer);
            $bot->sendMessage($chatId, "📝 {$safeAnswer}");
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

        $bot->sendMessageWithMainMenu(
            $chatId,
            "📊 <b>تقدّمك نحو التخرّج</b>\n\n".
            "✅ أنجزت {$completed} من {$total} ساعة معتمدة ({$percent}٪)\n".
            "📚 متبقّي: {$remaining} ساعة\n".
            "🟢 مسجّل حاليًا: {$registered} مساق",
            $this->buildMainMenuKeyboard($user)
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
            ? '🔔 التذكيرات مفعّلة (قبل كل محاضرة بربع ساعة)'
            : '🔕 التذكيرات متوقفة حاليًا';
        $lines[] = $remindersStatus;

        $keyboard = [
            [
                ['text' => '➕ إضافة محاضرة', 'callback_data' => 'sched:add'],
                ['text' => '✏️ تعديل', 'callback_data' => 'sched:edit'],
                ['text' => '🗑️ حذف', 'callback_data' => 'sched:delete'],
            ],
            [
                $link->reminders_enabled
                    ? ['text' => '🔕 إيقاف التذكيرات', 'callback_data' => 'sched:remind_off']
                    : ['text' => '🔔 تفعيل التذكيرات', 'callback_data' => 'sched:remind_on'],
            ],
        ];

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

            case 'remind_on':
                $link->update(['reminders_enabled' => true]);
                $bot->answerCallbackQuery($callbackId, '🔔 تم تفعيل التذكيرات.');
                $this->replyWithScheduleSummary($bot, $chatId, $link->fresh());

                return;

            case 'remind_off':
                $link->update(['reminders_enabled' => false]);
                $bot->answerCallbackQuery($callbackId, '🔕 تم إيقاف التذكيرات.');
                $this->replyWithScheduleSummary($bot, $chatId, $link->fresh());

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
     * ═══════════════ "معدلي" — تسجيل/تعديل/حذف علامة ═══════════════
     * نفس فلسفة إضافة/تعديل/حذف محاضرة تمامًا (pending_action + أزرار
     * inline)، لكن الهدف هون صف بجدول gpa_entries (نفس جدول
     * GpaController) لا ScheduleLecture. راجع الشرح المفصّل بأعلى
     * الملف. GPA_PAGE_SIZE يتحكم بعدد الأزرار بكل صفحة لقوائم طويلة
     * (المساقات الاختيارية ~24 مساق، والمساقات المعلَّمة عند الحذف).
     */
    private const GPA_PAGE_SIZE = 8;

    private function gpaYearLabel(int $year): string
    {
        $labels = [1 => 'السنة الأولى', 2 => 'السنة الثانية', 3 => 'السنة الثالثة', 4 => 'السنة الرابعة'];

        return $labels[$year] ?? "السنة {$year}";
    }

    private function gpaSemesterLabel(int $semester): string
    {
        return (($semester - 1) % 2 === 0) ? 'الفصل الأول' : 'الفصل الثاني';
    }

    private function gpaFmtGrade(float $grade): string
    {
        $formatted = rtrim(number_format($grade, 2, '.', ''), '0');

        return rtrim($formatted, '.');
    }

    /**
     * خريطة course_id => grade لكل علامات الطالب الحالية — لعرضها جنب
     * كل مساق بالأزرار فقط (لا تدخل أي حساب هون).
     */
    private function gpaGradesByCourseId(int $userId): array
    {
        return GpaEntry::query()->where('user_id', $userId)->pluck('grade', 'course_id')->all();
    }

    private function replyWithGpaSummary(TelegramBotApi $bot, int|string $chatId, \App\Models\User $user, TelegramGpaCalculator $gpaCalculator): void
    {
        $bot->sendMessage(
            $chatId,
            $gpaCalculator->formatForTelegram($user),
            [
                [
                    ['text' => '➕ تسجيل/تعديل علامة', 'callback_data' => 'gpa:set'],
                    ['text' => '🗑️ حذف علامة', 'callback_data' => 'gpa:delete'],
                ],
                [['text' => '🎯 محاكي "ماذا لو؟"', 'callback_data' => 'gpa:whatif']],
            ]
        );
    }

    private function startGpaSetFlow(TelegramBotApi $bot, TelegramLink $link, int|string $chatId): void
    {
        $link->update(['pending_action' => ['action' => 'gpa_set', 'step' => 'pick_kind', 'lecture_id' => null, 'data' => []]]);

        $bot->sendMessage($chatId, '📚 أي نوع مادة بدك تسجّل/تعدّل علامتها؟', [
            [
                ['text' => '📘 إجباري', 'callback_data' => 'gpa:kind:required'],
                ['text' => '📗 اختياري', 'callback_data' => 'gpa:kind:elective'],
            ],
            [['text' => '❌ إلغاء', 'callback_data' => 'gpa:cancel']],
        ]);
    }

    private function startGpaDeleteFlow(TelegramBotApi $bot, TelegramLink $link, int|string $chatId): void
    {
        $entries = GpaEntry::query()->where('user_id', $link->user_id)->with('course')->get()
            ->filter(fn (GpaEntry $entry) => $entry->course !== null)
            ->values();

        if ($entries->isEmpty()) {
            $bot->sendMessage($chatId, 'ما في أي علامة مسجَّلة لتحذفها. اكتب "معدلي" وبعدها "➕ تسجيل/تعديل علامة" لتضيف أول علامة.');

            return;
        }

        $link->update(['pending_action' => ['action' => 'gpa_delete', 'step' => 'pick_course', 'lecture_id' => null, 'data' => ['page' => 0]]]);

        $bot->sendMessage($chatId, '🗑️ اختر المادة يلي بدك تحذف علامتها:', $this->buildGpaDeletePickerKeyboard($entries, 0));
    }

    /**
     * @param \Illuminate\Support\Collection<int, GpaEntry> $entries
     */
    private function buildGpaDeletePickerKeyboard($entries, int $page): array
    {
        $slice = $entries->slice($page * self::GPA_PAGE_SIZE, self::GPA_PAGE_SIZE);

        $rows = [];
        foreach ($slice as $entry) {
            $course = $entry->course;
            $label = mb_substr((string) ($course->name_ar ?: $course->name_en), 0, 26) . ' (' . $this->gpaFmtGrade((float) $entry->grade) . ')';
            $rows[] = [['text' => $label, 'callback_data' => "gpa:delpick:{$course->id}"]];
        }

        $navRow = [];
        if ($page > 0) {
            $navRow[] = ['text' => '⬅️ السابق', 'callback_data' => 'gpa:delpage:' . ($page - 1)];
        }
        if (($page + 1) * self::GPA_PAGE_SIZE < $entries->count()) {
            $navRow[] = ['text' => 'التالي ➡️', 'callback_data' => 'gpa:delpage:' . ($page + 1)];
        }
        if ($navRow !== []) {
            $rows[] = $navRow;
        }

        $rows[] = [['text' => '❌ إلغاء', 'callback_data' => 'gpa:cancel']];

        return $rows;
    }

    private function buildGpaYearPickerKeyboard(): array
    {
        $years = Course::query()
            ->where('is_active', true)
            ->where('course_type', 'required')
            ->distinct()
            ->orderBy('year')
            ->pluck('year')
            ->all();

        $rows = [];
        foreach (array_chunk($years, 2) as $pair) {
            $rows[] = array_map(
                fn ($year) => ['text' => $this->gpaYearLabel((int) $year), 'callback_data' => "gpa:year:{$year}"],
                $pair
            );
        }
        $rows[] = [['text' => '❌ إلغاء', 'callback_data' => 'gpa:cancel']];

        return $rows;
    }

    private function buildGpaSemesterPickerKeyboard(int $year): array
    {
        $semesters = Course::query()
            ->where('is_active', true)
            ->where('course_type', 'required')
            ->where('year', $year)
            ->distinct()
            ->orderBy('semester')
            ->pluck('semester')
            ->all();

        $rows = [];
        foreach (array_chunk($semesters, 2) as $pair) {
            $rows[] = array_map(
                fn ($semester) => ['text' => $this->gpaSemesterLabel((int) $semester), 'callback_data' => "gpa:semester:{$semester}"],
                $pair
            );
        }
        $rows[] = [['text' => '❌ إلغاء', 'callback_data' => 'gpa:cancel']];

        return $rows;
    }

    private function buildGpaRequiredCoursePickerKeyboard(int $userId, int $year, int $semester): array
    {
        $courses = Course::query()
            ->where('is_active', true)
            ->where('course_type', 'required')
            ->where('year', $year)
            ->where('semester', $semester)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $grades = $this->gpaGradesByCourseId($userId);

        $rows = [];
        foreach ($courses as $course) {
            $label = mb_substr((string) ($course->name_ar ?: $course->name_en), 0, 26);

            if (array_key_exists($course->id, $grades)) {
                $label .= ' (' . $this->gpaFmtGrade((float) $grades[$course->id]) . ')';
            }

            $rows[] = [['text' => $label, 'callback_data' => "gpa:course:{$course->id}"]];
        }

        $rows[] = [['text' => '❌ إلغاء', 'callback_data' => 'gpa:cancel']];

        return $rows;
    }

    private function buildGpaElectiveCoursePickerKeyboard(int $userId, int $page): array
    {
        $courses = Course::query()
            ->where('is_active', true)
            ->where('course_type', 'elective')
            ->orderBy('name_ar')
            ->get();

        $grades = $this->gpaGradesByCourseId($userId);
        $slice = $courses->slice($page * self::GPA_PAGE_SIZE, self::GPA_PAGE_SIZE);

        $rows = [];
        foreach ($slice as $course) {
            $label = mb_substr((string) ($course->name_ar ?: $course->name_en), 0, 26);

            if (array_key_exists($course->id, $grades)) {
                $label .= ' (' . $this->gpaFmtGrade((float) $grades[$course->id]) . ')';
            }

            $rows[] = [['text' => $label, 'callback_data' => "gpa:course:{$course->id}"]];
        }

        $navRow = [];
        if ($page > 0) {
            $navRow[] = ['text' => '⬅️ السابق', 'callback_data' => 'gpa:epage:' . ($page - 1)];
        }
        if (($page + 1) * self::GPA_PAGE_SIZE < $courses->count()) {
            $navRow[] = ['text' => 'التالي ➡️', 'callback_data' => 'gpa:epage:' . ($page + 1)];
        }
        if ($navRow !== []) {
            $rows[] = $navRow;
        }

        $rows[] = [['text' => '❌ إلغاء', 'callback_data' => 'gpa:cancel']];

        return $rows;
    }

    /*
     * ضغطات أزرار "معدلي" (كل الأزرار يلي تبدأ بـ"gpa:") — نفس بنية
     * handleScheduleCallback بالضبط، لكن لجدول gpa_entries. لازم نتحقق
     * من الحساب المربوط + الخطوة الحالية (pending['step']) بكل زر، لا
     * الاعتماد على أي شيء بالـcallback_data نفسه غير المعرّفات الرقمية.
     */
    private function handleGpaCallback(TelegramBotApi $bot, TelegramGpaCalculator $gpaCalculator, array $callbackQuery): void
    {
        $callbackId = (string) ($callbackQuery['id'] ?? '');
        $chatId = $callbackQuery['message']['chat']['id'] ?? null;
        $data = (string) ($callbackQuery['data'] ?? '');
        $action = substr($data, strlen('gpa:'));
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
            case 'set':
                $bot->answerCallbackQuery($callbackId);
                $this->startGpaSetFlow($bot, $link, $chatId);

                return;

            case 'delete':
                $bot->answerCallbackQuery($callbackId);
                $this->startGpaDeleteFlow($bot, $link, $chatId);

                return;

            case 'whatif':
                $bot->answerCallbackQuery($callbackId);
                $link->update(['pending_action' => ['action' => 'gpa_whatif', 'step' => 'enter_target', 'lecture_id' => null, 'data' => []]]);
                $bot->sendMessage($chatId, '🎯 شو المعدل التراكمي يلي بدك توصله؟ اكتب رقم من ٠ إلى ١٠٠ (مثلًا: 85):');

                return;

            case 'cancel':
                $link->update(['pending_action' => null]);
                $bot->answerCallbackQuery($callbackId, 'تم الإلغاء.');

                return;

            case 'kind':
                if (($pending['action'] ?? null) !== 'gpa_set' || ($pending['step'] ?? null) !== 'pick_kind') {
                    $bot->answerCallbackQuery($callbackId);

                    return;
                }

                $kind = $arg === 'elective' ? 'elective' : 'required';

                if ($kind === 'required') {
                    $link->update(['pending_action' => ['action' => 'gpa_set', 'step' => 'pick_year', 'lecture_id' => null, 'data' => ['kind' => $kind]]]);
                    $bot->answerCallbackQuery($callbackId, 'إجباري');
                    $bot->sendMessage($chatId, '📘 اختر السنة:', $this->buildGpaYearPickerKeyboard());

                    return;
                }

                $link->update(['pending_action' => ['action' => 'gpa_set', 'step' => 'pick_course', 'lecture_id' => null, 'data' => ['kind' => $kind, 'page' => 0]]]);
                $bot->answerCallbackQuery($callbackId, 'اختياري');
                $bot->sendMessage($chatId, '📗 اختر المادة الاختيارية:', $this->buildGpaElectiveCoursePickerKeyboard($link->user_id, 0));

                return;

            case 'year':
                if (($pending['action'] ?? null) !== 'gpa_set' || ($pending['step'] ?? null) !== 'pick_year') {
                    $bot->answerCallbackQuery($callbackId);

                    return;
                }

                $year = (int) $arg;
                $newData = (array) ($pending['data'] ?? []);
                $newData['year'] = $year;
                $link->update(['pending_action' => ['action' => 'gpa_set', 'step' => 'pick_semester', 'lecture_id' => null, 'data' => $newData]]);
                $bot->answerCallbackQuery($callbackId, $this->gpaYearLabel($year));
                $bot->sendMessage($chatId, '📅 اختر الفصل:', $this->buildGpaSemesterPickerKeyboard($year));

                return;

            case 'semester':
                if (($pending['action'] ?? null) !== 'gpa_set' || ($pending['step'] ?? null) !== 'pick_semester') {
                    $bot->answerCallbackQuery($callbackId);

                    return;
                }

                $semester = (int) $arg;
                $newData = (array) ($pending['data'] ?? []);
                $newData['semester'] = $semester;
                $year = (int) ($newData['year'] ?? 0);
                $link->update(['pending_action' => ['action' => 'gpa_set', 'step' => 'pick_course', 'lecture_id' => null, 'data' => $newData]]);
                $bot->answerCallbackQuery($callbackId, $this->gpaSemesterLabel($semester));
                $bot->sendMessage($chatId, '📚 اختر المادة:', $this->buildGpaRequiredCoursePickerKeyboard($link->user_id, $year, $semester));

                return;

            case 'epage':
                if (($pending['action'] ?? null) !== 'gpa_set' || ($pending['step'] ?? null) !== 'pick_course') {
                    $bot->answerCallbackQuery($callbackId);

                    return;
                }

                $page = max(0, (int) $arg);
                $newData = (array) ($pending['data'] ?? []);
                $newData['page'] = $page;
                $link->update(['pending_action' => ['action' => 'gpa_set', 'step' => 'pick_course', 'lecture_id' => null, 'data' => $newData]]);
                $bot->answerCallbackQuery($callbackId);
                $bot->sendMessage($chatId, '📗 اختر المادة الاختيارية:', $this->buildGpaElectiveCoursePickerKeyboard($link->user_id, $page));

                return;

            case 'course':
                if (($pending['action'] ?? null) !== 'gpa_set' || ($pending['step'] ?? null) !== 'pick_course') {
                    $bot->answerCallbackQuery($callbackId);

                    return;
                }

                $courseId = (int) $arg;
                $course = Course::query()->where('id', $courseId)->where('is_active', true)->first();

                if (! $course) {
                    $bot->answerCallbackQuery($callbackId, 'هذه المادة مش موجودة.');

                    return;
                }

                $newData = (array) ($pending['data'] ?? []);
                $newData['course_id'] = $courseId;
                $link->update(['pending_action' => ['action' => 'gpa_set', 'step' => 'enter_grade', 'lecture_id' => null, 'data' => $newData]]);
                $bot->answerCallbackQuery($callbackId);

                $grades = $this->gpaGradesByCourseId($link->user_id);
                $currentGrade = array_key_exists($courseId, $grades)
                    ? "\n\nالعلامة الحالية: " . $this->gpaFmtGrade((float) $grades[$courseId])
                    : '';

                $bot->sendMessage(
                    $chatId,
                    '✍️ اكتب علامة مادة "' . TelegramBotApi::escapeHtml((string) $course->name_ar) . '" (رقم من ٠ إلى ١٠٠):' . $currentGrade
                );

                return;

            case 'delpage':
                if (($pending['action'] ?? null) !== 'gpa_delete' || ($pending['step'] ?? null) !== 'pick_course') {
                    $bot->answerCallbackQuery($callbackId);

                    return;
                }

                $page = max(0, (int) $arg);
                $entries = GpaEntry::query()->where('user_id', $link->user_id)->with('course')->get()
                    ->filter(fn (GpaEntry $entry) => $entry->course !== null)
                    ->values();
                $newData = (array) ($pending['data'] ?? []);
                $newData['page'] = $page;
                $link->update(['pending_action' => ['action' => 'gpa_delete', 'step' => 'pick_course', 'lecture_id' => null, 'data' => $newData]]);
                $bot->answerCallbackQuery($callbackId);
                $bot->sendMessage($chatId, '🗑️ اختر المادة يلي بدك تحذف علامتها:', $this->buildGpaDeletePickerKeyboard($entries, $page));

                return;

            case 'delpick':
                if (($pending['action'] ?? null) !== 'gpa_delete' || ($pending['step'] ?? null) !== 'pick_course') {
                    $bot->answerCallbackQuery($callbackId);

                    return;
                }

                $courseId = (int) $arg;
                $entry = GpaEntry::query()->where('user_id', $link->user_id)->where('course_id', $courseId)->with('course')->first();

                if (! $entry || ! $entry->course) {
                    $bot->answerCallbackQuery($callbackId, 'ما في علامة مسجَّلة لهذه المادة.');

                    return;
                }

                $newData = (array) ($pending['data'] ?? []);
                $newData['course_id'] = $courseId;
                $link->update(['pending_action' => ['action' => 'gpa_delete', 'step' => 'confirm', 'lecture_id' => null, 'data' => $newData]]);
                $bot->answerCallbackQuery($callbackId);
                $bot->sendMessage(
                    $chatId,
                    'متأكد بدك تحذف علامة "' . TelegramBotApi::escapeHtml((string) $entry->course->name_ar) . '" (' . $this->gpaFmtGrade((float) $entry->grade) . ')؟',
                    [[
                        ['text' => '✅ نعم احذف', 'callback_data' => "gpa:delconfirm:{$courseId}"],
                        ['text' => '❌ إلغاء', 'callback_data' => 'gpa:cancel'],
                    ]]
                );

                return;

            case 'delconfirm':
                if (($pending['action'] ?? null) !== 'gpa_delete' || ($pending['step'] ?? null) !== 'confirm') {
                    $bot->answerCallbackQuery($callbackId);

                    return;
                }

                $courseId = (int) $arg;

                if ((int) ($pending['data']['course_id'] ?? 0) !== $courseId) {
                    $bot->answerCallbackQuery($callbackId);

                    return;
                }

                GpaEntry::query()->where('user_id', $link->user_id)->where('course_id', $courseId)->delete();
                $link->update(['pending_action' => null]);
                $bot->answerCallbackQuery($callbackId, 'تم الحذف.');
                $bot->sendMessage($chatId, '🗑️ تم حذف العلامة بنجاح.');
                $this->replyWithGpaSummary($bot, $chatId, $link->user, $gpaCalculator);

                return;

            default:
                $bot->answerCallbackQuery($callbackId);
        }
    }

    /*
     * نص عادي أثناء عملية "معدلي" جارية — الخطوة الوحيدة اللي تحتاج نص
     * هي "enter_grade" (كل الخطوات التانية أزرار بالكامل عبر
     * handleGpaCallback). "إلغاء" شغّال بأي خطوة، متل باقي المحادثات.
     */
    private function handleGpaTextInput(TelegramBotApi $bot, TelegramGpaCalculator $gpaCalculator, TelegramLink $link, int|string $chatId, string $text): void
    {
        $normalized = trim($text);

        if (in_array($normalized, ['إلغاء', 'الغاء', 'cancel'], true)) {
            $link->update(['pending_action' => null]);
            $bot->sendMessage($chatId, 'تم إلغاء العملية ✅');

            return;
        }

        $pending = $link->pending_action;
        $step = (string) ($pending['step'] ?? '');
        $data = (array) ($pending['data'] ?? []);

        if ($step === 'enter_target') {
            $normalizedTarget = str_replace(',', '.', $normalized);

            if (! preg_match('/^\d{1,3}(\.\d{1,2})?$/', $normalizedTarget)) {
                $bot->sendMessage($chatId, 'رقم غير صالح 🙂 اكتب رقم هدف من ٠ إلى ١٠٠ (مثلًا: 85):');

                return;
            }

            $target = round((float) $normalizedTarget, 2);
            $link->update(['pending_action' => null]);

            if ($target < 0 || $target > 100) {
                $bot->sendMessage($chatId, 'الهدف لازم يكون بين ٠ و١٠٠.');

                return;
            }

            $bot->sendMessage($chatId, $gpaCalculator->formatWhatIfForTelegram($link->user, $target));

            return;
        }

        if ($step !== 'enter_grade') {
            $bot->sendMessage($chatId, 'استخدم الأزرار يلي فوق 🙂 أو اكتب "إلغاء" لإيقاف العملية.');

            return;
        }

        $normalizedGrade = str_replace(',', '.', $normalized);

        if (! preg_match('/^\d{1,3}(\.\d{1,2})?$/', $normalizedGrade)) {
            $bot->sendMessage($chatId, 'علامة غير صالحة 🙂 اكتب رقم من ٠ إلى ١٠٠ (مثلًا: 85 أو 85.5):');

            return;
        }

        $grade = round((float) $normalizedGrade, 2);

        if ($grade < 0 || $grade > 100) {
            $bot->sendMessage($chatId, 'العلامة لازم تكون بين ٠ و١٠٠. جرّب رقم صحيح:');

            return;
        }

        $courseId = (int) ($data['course_id'] ?? 0);
        $course = Course::query()->where('id', $courseId)->where('is_active', true)->first();

        $link->update(['pending_action' => null]);

        if (! $course) {
            $bot->sendMessage($chatId, 'تعذّر إيجاد هذه المادة (ممكن تغيّرت). جرّب من جديد.');

            return;
        }

        GpaEntry::updateOrCreate(
            ['user_id' => $link->user_id, 'course_id' => $course->id],
            ['grade' => $grade]
        );

        $bot->sendMessage(
            $chatId,
            '✅ تم حفظ علامة "' . TelegramBotApi::escapeHtml((string) $course->name_ar) . '": <b>' . $this->gpaFmtGrade($grade) . '</b>'
        );
        $this->replyWithGpaSummary($bot, $chatId, $link->user, $gpaCalculator);
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

    /*
     * ═══════════════ Inline Mode — بحث بأي محادثة ═══════════════
     * أقل حدّ مطلوب لتجربة بحث مفيدة: أقل من حرفين نرجّع نتائج فاضية
     * (تيليجرام بيتجاهل is_personal/cache_time بهاي الحالة وما يعرض
     * شي — أفضل من فرض زر "ابدأ بحثًا خاصًا" غير ضروري). نفس حدود
     * SearchController تقريبًا بس أقل شوي (٥ بدل ٦-٨) لأن مجموع
     * النتائج الثلاث لازم يبقى معقول بقائمة منسدلة واحدة.
     */
    private const INLINE_MIN_QUERY_LENGTH = 2;

    private const INLINE_TOOL_TYPE_LABELS = [
        'software' => 'برنامج', 'online' => 'أداة ويب', 'concept' => 'مفهوم', 'library' => 'مكتبة',
    ];

    // نفس تسميات أنواع المحتوى بالضبط (TelegramContentNotifier::KIND_LABELS
    // وadmin.html) — مكرَّرة هون بقصد، كلاهما ثابت نادرًا ما يتغيّر.
    private const INLINE_CONTENT_KIND_LABELS = [
        'youtube' => 'يوتيوب', 'vid' => 'فيديو', 'drive' => 'درايف',
        'assignment' => 'تعيين', 'exercise' => 'تدريب', 'exam' => 'اختبار',
        'book' => 'مرجع', 'software' => 'برنامج', 'github' => 'GitHub',
        'pdf' => 'PDF', 'doc' => 'مستند', 'image' => 'صورة',
        'link' => 'رابط', 'other' => 'أخرى',
    ];

    private function handleInlineQuery(TelegramBotApi $bot, array $inlineQuery): void
    {
        $inlineQueryId = (string) ($inlineQuery['id'] ?? '');

        if ($inlineQueryId === '') {
            return;
        }

        $term = trim((string) ($inlineQuery['query'] ?? ''));

        if (mb_strlen($term) < self::INLINE_MIN_QUERY_LENGTH) {
            $bot->answerInlineQuery($inlineQueryId, [], 30);

            return;
        }

        try {
            $bot->answerInlineQuery($inlineQueryId, $this->buildInlineSearchResults($term), 60);
        } catch (\Throwable $error) {
            report($error);
        }
    }

    /*
     * نفس منطق SearchController::index() بالضبط (البحث بالاسم/الرمز/
     * الكلمات المفتاحية لا الوصف الطويل، وترتيب حسب الصلة) — لكن مُعاد
     * كتابته هون محليًا لأن شكل النتيجة مختلف كليًا (InlineQueryResult
     * لتيليجرام لا JSON عادي لواجهة الموقع). أي تعديل مستقبلي على منطق
     * البحث بـSearchController يستاهل مراجعة هون كمان يدويًا.
     */
    private function buildInlineSearchResults(string $term): array
    {
        $escaped = str_replace(['%', '_'], ['\%', '\_'], $term);
        $contains = '%'.$escaped.'%';
        $prefix = $escaped.'%';
        $relevance = fn ($column) => "(CASE WHEN {$column} LIKE ? THEN 0 ELSE 1 END)";

        $frontendUrl = rtrim((string) config('app.frontend_url'), '/');

        $courses = Course::query()
            ->where('is_active', true)
            ->where(function ($query) use ($contains) {
                $query->where('name_ar', 'like', $contains)
                    ->orWhere('name_en', 'like', $contains)
                    ->orWhere('code', 'like', $contains)
                    ->orWhere('keywords', 'like', $contains);
            })
            ->orderByRaw($relevance('name_ar'), [$prefix])
            ->limit(5)
            ->get(['id', 'key', 'code', 'name_ar', 'name_en']);

        $content = CourseFile::query()
            ->where('is_published', true)
            ->where('title', 'like', $contains)
            ->orderByRaw($relevance('title'), [$prefix])
            ->with('course:id,key,code,name_ar,name_en')
            ->limit(5)
            ->get(['id', 'course_id', 'title', 'kind']);

        $tools = Tool::query()
            ->where('is_active', true)
            ->where('name', 'like', $contains)
            ->orderByRaw($relevance('name'), [$prefix])
            ->limit(5)
            ->get(['id', 'name', 'type', 'description']);

        $results = [];

        foreach ($courses as $course) {
            $name = (string) ($course->name_ar ?: $course->name_en);
            $code = (string) $course->code;
            $link = $frontendUrl.'/course.html?course='.urlencode((string) $course->key);

            $messageText = '📘 <b>'.TelegramBotApi::escapeHtml($name).'</b>'.
                ($code !== '' ? ' ('.TelegramBotApi::escapeHtml($code).')' : '')."\n".
                '🌐 '.$link;

            $results[] = [
                'type' => 'article',
                'id' => 'course:'.$course->id,
                'title' => '📘 '.$name,
                'description' => $code !== '' ? $code : 'مادة',
                'input_message_content' => ['message_text' => $messageText, 'parse_mode' => 'HTML'],
            ];
        }

        foreach ($content as $file) {
            $course = $file->course;

            if (! $course) {
                continue;
            }

            $courseName = (string) ($course->name_ar ?: $course->name_en);
            $kindLabel = self::INLINE_CONTENT_KIND_LABELS[$file->kind] ?? null;
            $link = $frontendUrl.'/course.html?course='.urlencode((string) $course->key).'&content='.(int) $file->id;

            $messageText = '📄 <b>'.TelegramBotApi::escapeHtml((string) $file->title).'</b>'."\n".
                '📘 '.TelegramBotApi::escapeHtml($courseName)."\n".
                '🌐 '.$link;

            $results[] = [
                'type' => 'article',
                'id' => 'content:'.$file->id,
                'title' => '📄 '.(string) $file->title,
                'description' => $courseName.($kindLabel ? ' — '.$kindLabel : ''),
                'input_message_content' => ['message_text' => $messageText, 'parse_mode' => 'HTML'],
            ];
        }

        foreach ($tools as $tool) {
            $typeLabel = self::INLINE_TOOL_TYPE_LABELS[$tool->type] ?? '';
            $description = trim((string) $tool->description);

            $messageText = '🧰 <b>'.TelegramBotApi::escapeHtml((string) $tool->name).'</b>'.
                ($description !== '' ? "\n".TelegramBotApi::escapeHtml($description) : '')."\n".
                '🌐 '.$frontendUrl.'/tools.html';

            $results[] = [
                'type' => 'article',
                'id' => 'tool:'.$tool->id,
                'title' => '🧰 '.(string) $tool->name,
                'description' => $typeLabel.($description !== '' ? ' — '.mb_substr($description, 0, 60) : ''),
                'input_message_content' => ['message_text' => $messageText, 'parse_mode' => 'HTML'],
            ];
        }

        return $results;
    }

    /*
     * ============================================================
     * "المساقات" (Course Hub) — مرحلة ٢. تصفح كل مساقات الخطة
     * الدراسية من داخل البوت مباشرة (سنة → فصل → قائمة مساقات →
     * تفاصيل مادة + محتواها العام)، قراءة فقط، بلا أي حاجة لحساب
     * مربوط ولا لفتح الموقع. مبني على نفس الاستعلامات المستخدمة
     * بـCourseController/CoursePrerequisiteController/CourseFileController
     * العامة (راجع Course::prerequisites()/requiredFor()، Tool::TYPES،
     * self::INLINE_TOOL_TYPE_LABELS وINLINE_CONTENT_KIND_LABELS
     * الموجودة أصلًا لميزة البحث الفوري).
     *
     * السنوات ١-٤، والفصول مرقّمة ١-٨ بشكل متسلسل بكل الخطة (فصلين
     * لكل سنة) — نفس قاعدة between:1,4 وbetween:1,8 المستخدمة
     * بالتحقق من صحة بيانات Staff/CourseController.
     *
     * مخطط callback_data: hub:root | hub:year:{سنة} |
     * hub:sem:{سنة}:{فصل} | hub:electives:{صفحة} | hub:course:{key} |
     * hub:files:{key}:{صفحة}.
     * ============================================================
     */
    /*
     * لوحة القائمة الرئيسية — نفس self::MAIN_MENU_KEYBOARD لكل الطلاب،
     * وصف إضافي "📢 نشر إعلان" لحسابات الإدارة فقط (User::isStaff())
     * — مرحلة ٣.
     */
    private function buildMainMenuKeyboard(\App\Models\User $user): array
    {
        $keyboard = self::MAIN_MENU_KEYBOARD;

        if ($user->isStaff()) {
            $keyboard[] = [['text' => self::MAIN_MENU_ADMIN_ANNOUNCE]];
        }

        return $keyboard;
    }

    private function sendCourseHubYearPicker(TelegramBotApi $bot, int|string $chatId): void
    {
        $keyboard = [
            [
                ['text' => '📘 السنة الأولى', 'callback_data' => 'hub:year:1'],
                ['text' => '📗 السنة الثانية', 'callback_data' => 'hub:year:2'],
            ],
            [
                ['text' => '📙 السنة الثالثة', 'callback_data' => 'hub:year:3'],
                ['text' => '📕 السنة الرابعة', 'callback_data' => 'hub:year:4'],
            ],
            [['text' => '🔀 المساقات الاختيارية', 'callback_data' => 'hub:electives:1']],
            [['text' => '🧰 الأدوات الهندسية', 'callback_data' => 'hub:tools:1']],
        ];

        $bot->sendMessage(
            $chatId,
            "📚 <b>المساقات</b>\n\nاختر السنة الدراسية لتصفّح مساقاتها الإجبارية، أو تصفّح المساقات الاختيارية أو الأدوات الهندسية مباشرة:",
            $keyboard
        );
    }

    private function handleCourseHubCallback(TelegramBotApi $bot, array $callbackQuery): void
    {
        $callbackId = (string) ($callbackQuery['id'] ?? '');
        $chatId = $callbackQuery['message']['chat']['id'] ?? null;
        $data = (string) ($callbackQuery['data'] ?? '');
        $action = substr($data, strlen('hub:'));
        [$key, $arg] = array_pad(explode(':', $action, 2), 2, null);

        if (! $chatId) {
            $bot->answerCallbackQuery($callbackId);

            return;
        }

        $bot->answerCallbackQuery($callbackId);

        if ($key === 'root') {
            $this->sendCourseHubYearPicker($bot, $chatId);

            return;
        }

        if ($key === 'year') {
            $year = (int) $arg;

            if ($year < 1 || $year > 4) {
                $this->sendCourseHubYearPicker($bot, $chatId);

                return;
            }

            $this->sendCourseHubSemesterPicker($bot, $chatId, $year);

            return;
        }

        if ($key === 'sem') {
            [$year, $semester] = array_pad(explode(':', (string) $arg, 2), 2, null);
            $this->sendCourseHubCourseList($bot, $chatId, (int) $year, (int) $semester);

            return;
        }

        if ($key === 'electives') {
            $this->sendCourseHubElectivesList($bot, $chatId, max(1, (int) $arg));

            return;
        }

        if ($key === 'course') {
            $this->sendCourseHubCourseDetail($bot, $chatId, (string) $arg);

            return;
        }

        if ($key === 'files') {
            $this->sendCourseHubCourseFiles($bot, $chatId, (string) $arg);

            return;
        }

        if ($key === 'tools') {
            $this->sendCourseHubToolsList($bot, $chatId, max(1, (int) $arg));

            return;
        }

        if ($key === 'tool') {
            $this->sendCourseHubToolDetail($bot, $chatId, (int) $arg);

            return;
        }

        if ($key === 'coursetools') {
            $this->sendCourseHubCourseToolsList($bot, $chatId, (string) $arg);

            return;
        }
    }

    private function sendCourseHubSemesterPicker(TelegramBotApi $bot, int|string $chatId, int $year): void
    {
        $firstSemester = ($year - 1) * 2 + 1;
        $secondSemester = $firstSemester + 1;

        $keyboard = [
            [
                ['text' => '1️⃣ الفصل الأول', 'callback_data' => "hub:sem:{$year}:{$firstSemester}"],
                ['text' => '2️⃣ الفصل الثاني', 'callback_data' => "hub:sem:{$year}:{$secondSemester}"],
            ],
            [['text' => '🔙 رجوع للسنوات', 'callback_data' => 'hub:root']],
        ];

        $bot->sendMessage($chatId, "📘 <b>السنة {$year}</b>\n\nاختر الفصل:", $keyboard);
    }

    private function sendCourseHubCourseList(TelegramBotApi $bot, int|string $chatId, int $year, int $semester): void
    {
        $courses = Course::query()
            ->where('is_active', true)
            ->where('year', $year)
            ->where('semester', $semester)
            ->where('course_type', 'required')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['key', 'code', 'name_ar', 'name_en']);

        if ($courses->isEmpty()) {
            $bot->sendMessage(
                $chatId,
                '📭 لا يوجد مساقات إجبارية مسجّلة لهذا الفصل حاليًا بالموقع.',
                [[['text' => '🔙 رجوع للفصول', 'callback_data' => "hub:year:{$year}"]]]
            );

            return;
        }

        $keyboard = $courses->map(function (Course $course) {
            $label = trim(($course->code ? $course->code.' — ' : '').(string) ($course->name_ar ?: $course->name_en));

            return [['text' => $label, 'callback_data' => 'hub:course:'.$course->key]];
        })->values()->all();

        $keyboard[] = [['text' => '🔙 رجوع للفصول', 'callback_data' => "hub:year:{$year}"]];

        $bot->sendMessage($chatId, "📘 <b>مساقات السنة {$year} — الفصل {$semester}</b>\n\nاختر مادة لعرض تفاصيلها:", $keyboard);
    }

    private function sendCourseHubElectivesList(TelegramBotApi $bot, int|string $chatId, int $page): void
    {
        $query = Course::query()
            ->where('is_active', true)
            ->where('course_type', 'elective')
            ->orderBy('year')
            ->orderBy('semester')
            ->orderBy('sort_order')
            ->orderBy('id');

        $total = (clone $query)->count();
        $courses = $query
            ->forPage($page, self::COURSE_HUB_PAGE_SIZE)
            ->get(['key', 'code', 'name_ar', 'name_en', 'year']);

        if ($courses->isEmpty()) {
            $bot->sendMessage(
                $chatId,
                '📭 لا يوجد مساقات اختيارية مسجّلة حاليًا بالموقع.',
                [[['text' => '🔙 رجوع', 'callback_data' => 'hub:root']]]
            );

            return;
        }

        $keyboard = $courses->map(function (Course $course) {
            $label = trim((string) ($course->name_ar ?: $course->name_en)).' (سنة '.$course->year.')';

            return [['text' => $label, 'callback_data' => 'hub:course:'.$course->key]];
        })->values()->all();

        $lastPage = (int) ceil($total / self::COURSE_HUB_PAGE_SIZE);
        $pagerRow = [];

        if ($page > 1) {
            $pagerRow[] = ['text' => '⬅️ السابق', 'callback_data' => 'hub:electives:'.($page - 1)];
        }

        if ($page < $lastPage) {
            $pagerRow[] = ['text' => 'التالي ➡️', 'callback_data' => 'hub:electives:'.($page + 1)];
        }

        if ($pagerRow !== []) {
            $keyboard[] = $pagerRow;
        }

        $keyboard[] = [['text' => '🔙 رجوع', 'callback_data' => 'hub:root']];

        $bot->sendMessage(
            $chatId,
            "🔀 <b>المساقات الاختيارية</b> (صفحة {$page} من ".max(1, $lastPage).")\n\nاختر مادة لعرض تفاصيلها:",
            $keyboard
        );
    }

    private function sendCourseHubCourseDetail(TelegramBotApi $bot, int|string $chatId, string $courseKey): void
    {
        $course = Course::query()->where('key', $courseKey)->where('is_active', true)->first();

        if (! $course) {
            $bot->sendMessage($chatId, '⚠️ هذه المادة غير موجودة أو غير مفعّلة حاليًا.', [[['text' => '🔙 رجوع', 'callback_data' => 'hub:root']]]);

            return;
        }

        $name = TelegramBotApi::escapeHtml((string) ($course->name_ar ?: $course->name_en));
        $code = TelegramBotApi::escapeHtml((string) $course->code);
        $typeLabel = match ($course->course_type) {
            'elective' => 'اختياري',
            'placeholder' => 'غير محدد',
            default => 'إجباري',
        };

        $lines = [
            "📘 <b>{$name}</b>".($code !== '' ? " ({$code})" : ''),
            "🏷️ النوع: {$typeLabel}",
            '🎓 الساعات المعتمدة: '.(int) $course->credit_hours,
            "📅 السنة {$course->year} — الفصل {$course->semester}",
        ];

        $description = trim((string) $course->description);

        if ($description !== '') {
            $lines[] = '';
            $lines[] = TelegramBotApi::escapeHtml(mb_substr($description, 0, 400));
        }

        $prerequisites = $course->prerequisites()->with('prerequisite')->get();
        $lines[] = '';

        if ($prerequisites->isEmpty()) {
            $lines[] = '🔗 المتطلبات السابقة: لا يوجد';
        } else {
            $lines[] = '🔗 <b>المتطلبات السابقة:</b>';

            foreach ($prerequisites as $prerequisite) {
                $prereqName = $prerequisite->prerequisite?->name_ar
                    ?? $prerequisite->prerequisite?->name_en
                    ?? $prerequisite->prerequisite_code_raw
                    ?? 'غير معروف';
                $lines[] = '• '.TelegramBotApi::escapeHtml((string) $prereqName);
            }
        }

        $tools = $course->tools()->where('is_active', true)->get(['tools.id', 'tools.name']);

        if ($tools->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '🧰 <b>الأدوات المرتبطة:</b> '.TelegramBotApi::escapeHtml($tools->pluck('name')->implode('، '));
        }

        $frontendUrl = rtrim((string) config('app.frontend_url'), '/');
        $siteLink = $frontendUrl.'/course.html?course='.urlencode((string) $course->key);

        $keyboard = [
            [['text' => '📁 عرض محتوى المادة', 'callback_data' => 'hub:files:'.$course->key]],
        ];

        if ($tools->isNotEmpty()) {
            $keyboard[] = [['text' => "🧰 أدوات المادة ({$tools->count()})", 'callback_data' => 'hub:coursetools:'.$course->key]];
        }

        $keyboard[] = [['text' => '🌐 فتح صفحة المادة بالموقع', 'url' => $siteLink]];
        $keyboard[] = [['text' => '🔙 رجوع للمساقات', 'callback_data' => 'hub:root']];

        $bot->sendMessage($chatId, implode("\n", $lines), $keyboard);
    }

    /*
     * "🧰 أدوات المادة" — نفس أدوات المادة المعروضة أصلًا بتفاصيلها
     * (Course::tools()) لكن كل أداة بزر يفتح تفاصيلها الكاملة
     * (sendCourseHubToolDetail) مباشرة، بدل مجرد أسماء بلا روابط.
     */
    private function sendCourseHubCourseToolsList(TelegramBotApi $bot, int|string $chatId, string $courseKey): void
    {
        $course = Course::query()->where('key', $courseKey)->where('is_active', true)->first();

        if (! $course) {
            $bot->sendMessage($chatId, '⚠️ هذه المادة غير موجودة أو غير مفعّلة حاليًا.', [[['text' => '🔙 رجوع', 'callback_data' => 'hub:root']]]);

            return;
        }

        $tools = $course->tools()->where('is_active', true)->get(['tools.id', 'tools.name', 'tools.type']);

        if ($tools->isEmpty()) {
            $bot->sendMessage(
                $chatId,
                '📭 لا يوجد أدوات مرتبطة بهذه المادة حاليًا.',
                [[['text' => '🔙 رجوع لتفاصيل المادة', 'callback_data' => 'hub:course:'.$course->key]]]
            );

            return;
        }

        $keyboard = $tools->map(function (Tool $tool) {
            $typeLabel = self::INLINE_TOOL_TYPE_LABELS[$tool->type] ?? '';
            $label = trim(($typeLabel !== '' ? $typeLabel.' — ' : '').(string) $tool->name);

            return [['text' => $label, 'callback_data' => 'hub:tool:'.$tool->id]];
        })->values()->all();

        $keyboard[] = [['text' => '🔙 رجوع لتفاصيل المادة', 'callback_data' => 'hub:course:'.$course->key]];

        $bot->sendMessage(
            $chatId,
            '🧰 <b>أدوات مادة '.TelegramBotApi::escapeHtml((string) ($course->name_ar ?: $course->name_en))."</b>\n\nاختر أداة لعرض تفاصيلها وروابطها:",
            $keyboard
        );
    }

    /*
     * كل محتوى المادة العام برسالة وحدة (أو عدة رسائل متتالية فقط لو
     * تجاوز حد تيليجرام لطول الرسالة)، مرتّب حسب القسم المنشور فيه
     * بالضبط كما بالموقع (Course::sections() مرتّبة sort_order، وكل
     * قسم فيه CourseSection::contents() مرتّبة بنفس الطريقة) — ثم
     * دفعة "عام" أخيرة لأي ملف بلا قسم (course_section_id = null).
     * الرابط لكل ملف هو رابطه المباشر الحقيقي (external_url: يوتيوب/
     * درايف/غيره) لو موجود، وإلا رابط صفحة المادة بالموقع كحل بديل
     * وحيد (لا يوجد رابط تحميل مباشر ثابت للملفات المرفوعة محليًا —
     * التحميل هناك يمر عبر رابط موقّع مؤقت الصلاحية).
     */
    private function sendCourseHubCourseFiles(TelegramBotApi $bot, int|string $chatId, string $courseKey): void
    {
        $course = Course::query()->where('key', $courseKey)->where('is_active', true)->first();

        if (! $course) {
            $bot->sendMessage($chatId, '⚠️ هذه المادة غير موجودة أو غير مفعّلة حاليًا.', [[['text' => '🔙 رجوع', 'callback_data' => 'hub:root']]]);

            return;
        }

        $frontendUrl = rtrim((string) config('app.frontend_url'), '/');
        $courseLink = $frontendUrl.'/course.html?course='.urlencode((string) $course->key);
        $courseName = (string) ($course->name_ar ?: $course->name_en);

        /*
         * بدون حساب مربوط بالموقع (context البوت مجهول الهوية دايمًا
         * هون تمامًا متل البحث الفوري)، فقط visibility=public مسموح —
         * نفس منطق CourseFile::isVisibleTo() لما $user و$isCourseStudent
         * تكونان null/false.
         */
        $visibleScope = static fn ($query) => $query
            ->where('is_published', true)
            ->where('status', 'ready')
            ->where('visibility', 'public')
            ->orderBy('sort_order')
            ->orderBy('id');

        $sections = $course->sections()
            ->where('is_published', true)
            ->with(['contents' => $visibleScope])
            ->get();

        $unsectioned = $visibleScope($course->files()->whereNull('course_section_id'))
            ->get(['id', 'title', 'kind', 'external_url']);

        $groups = [];

        foreach ($sections as $section) {
            if ($section->contents->isNotEmpty()) {
                $groups[] = ['title' => (string) $section->title, 'files' => $section->contents];
            }
        }

        if ($unsectioned->isNotEmpty()) {
            $groups[] = ['title' => 'عام', 'files' => $unsectioned];
        }

        if ($groups === []) {
            $bot->sendMessage(
                $chatId,
                "📭 لا يوجد محتوى عام متاح لمادة \"".TelegramBotApi::escapeHtml($courseName)."\" ضمن البوت حاليًا.\n🌐 {$courseLink}",
                [[['text' => '🔙 رجوع لتفاصيل المادة', 'callback_data' => 'hub:course:'.$course->key]]]
            );

            return;
        }

        $lines = ['📁 <b>محتوى مادة '.TelegramBotApi::escapeHtml($courseName).'</b>', ''];

        foreach ($groups as $group) {
            $lines[] = '▫️ <b>'.TelegramBotApi::escapeHtml($group['title']).'</b>';

            foreach ($group['files'] as $file) {
                $kindLabel = self::INLINE_CONTENT_KIND_LABELS[$file->kind] ?? 'محتوى';
                $externalUrl = trim((string) $file->external_url);
                $link = $externalUrl !== '' ? $externalUrl : $courseLink.'&content='.(int) $file->id;
                $lines[] = '📄 '.TelegramBotApi::escapeHtml((string) $file->title).' — '.$kindLabel;
                $lines[] = '🔗 '.$link;
            }

            $lines[] = '';
        }

        $keyboard = [[['text' => '🔙 رجوع لتفاصيل المادة', 'callback_data' => 'hub:course:'.$course->key]]];

        $this->sendChunkedMessage($bot, $chatId, $lines, $keyboard);
    }

    /*
     * تيليجرام يرفض أي رسالة أطول من ٤٠٩٦ حرف — لو محتوى المادة كثير
     * جدًا (نادر لكن ممكن)، نقسمها لعدة رسائل متتالية بدل ما نفشل
     * بصمت أو نبتر المحتوى، مع إبقاء ترتيب الأسطر كما هو والأزرار على
     * آخر رسالة فقط.
     */
    private function sendChunkedMessage(TelegramBotApi $bot, int|string $chatId, array $lines, array $keyboard = []): void
    {
        $chunks = [];
        $current = '';

        foreach ($lines as $line) {
            $candidate = $current === '' ? $line : $current."\n".$line;

            if (mb_strlen($candidate) > 3500 && $current !== '') {
                $chunks[] = $current;
                $current = $line;
            } else {
                $current = $candidate;
            }
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        $lastIndex = count($chunks) - 1;

        foreach ($chunks as $index => $chunk) {
            $bot->sendMessage($chatId, $chunk, $index === $lastIndex ? $keyboard : null);
        }
    }

    /*
     * "🧰 الأدوات الهندسية" — نفس منطق قسم الأدوات بالموقع
     * (Tool::TYPES / INLINE_TOOL_TYPE_LABELS)، قراءة فقط، مقسّم صفحات.
     */
    private function sendCourseHubToolsList(TelegramBotApi $bot, int|string $chatId, int $page): void
    {
        $query = Tool::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name');
        $total = (clone $query)->count();
        $tools = $query->forPage($page, self::COURSE_HUB_PAGE_SIZE)->get(['id', 'name', 'type']);

        if ($tools->isEmpty()) {
            $bot->sendMessage($chatId, '📭 لا يوجد أدوات مسجّلة حاليًا بالموقع.', [[['text' => '🔙 رجوع', 'callback_data' => 'hub:root']]]);

            return;
        }

        $keyboard = $tools->map(function (Tool $tool) {
            $typeLabel = self::INLINE_TOOL_TYPE_LABELS[$tool->type] ?? '';
            $label = trim(($typeLabel !== '' ? $typeLabel.' — ' : '').(string) $tool->name);

            return [['text' => $label, 'callback_data' => 'hub:tool:'.$tool->id]];
        })->values()->all();

        $lastPage = (int) ceil($total / self::COURSE_HUB_PAGE_SIZE);
        $pagerRow = [];

        if ($page > 1) {
            $pagerRow[] = ['text' => '⬅️ السابق', 'callback_data' => 'hub:tools:'.($page - 1)];
        }

        if ($page < $lastPage) {
            $pagerRow[] = ['text' => 'التالي ➡️', 'callback_data' => 'hub:tools:'.($page + 1)];
        }

        if ($pagerRow !== []) {
            $keyboard[] = $pagerRow;
        }

        $keyboard[] = [['text' => '🔙 رجوع', 'callback_data' => 'hub:root']];

        $bot->sendMessage(
            $chatId,
            "🧰 <b>الأدوات الهندسية</b> (صفحة {$page} من ".max(1, $lastPage).")\n\nاختر أداة لعرض تفاصيلها:",
            $keyboard
        );
    }

    private function sendCourseHubToolDetail(TelegramBotApi $bot, int|string $chatId, int $toolId): void
    {
        $tool = Tool::query()->where('id', $toolId)->where('is_active', true)->first();

        if (! $tool) {
            $bot->sendMessage($chatId, '⚠️ هذه الأداة غير موجودة أو غير مفعّلة حاليًا.', [[['text' => '🔙 رجوع', 'callback_data' => 'hub:tools:1']]]);

            return;
        }

        $typeLabel = self::INLINE_TOOL_TYPE_LABELS[$tool->type] ?? '';
        $lines = ['🧰 <b>'.TelegramBotApi::escapeHtml((string) $tool->name).'</b>'.($typeLabel !== '' ? ' — '.$typeLabel : '')];

        $description = trim((string) $tool->description);

        if ($description !== '') {
            $lines[] = '';
            $lines[] = TelegramBotApi::escapeHtml($description);
        }

        $explanation = trim((string) $tool->explanation);

        if ($explanation !== '') {
            $lines[] = '';
            $lines[] = TelegramBotApi::escapeHtml($explanation);
        }

        $keyboard = [];
        $officialUrl = trim((string) $tool->official_url);
        $videoUrl = trim((string) $tool->video_url);
        $linkRow = [];

        if ($officialUrl !== '') {
            $linkRow[] = ['text' => '🌐 الرابط الرسمي', 'url' => $officialUrl];
        }

        if ($videoUrl !== '') {
            $linkRow[] = ['text' => '🎬 فيديو شرح', 'url' => $videoUrl];
        }

        if ($linkRow !== []) {
            $keyboard[] = $linkRow;
        }

        $keyboard[] = [['text' => '🔙 رجوع للأدوات', 'callback_data' => 'hub:tools:1']];

        $bot->sendMessage($chatId, implode("\n", $lines), $keyboard);
    }

    /*
     * ============================================================
     * "🔍 بحث" — نفس منطق البحث الفوري (buildInlineSearchResults)
     * لكن كرسالة عادية بمحادثة البوت، مع أزرار مباشرة لفتح المادة/
     * الأداة من داخل "المساقات" (Course Hub). خطوة واحدة فقط
     * (pending_action.action = 'search'، step = 'query').
     * ============================================================
     */
    private function handleSearchTextInput(TelegramBotApi $bot, TelegramLink $link, int|string $chatId, string $text): void
    {
        $normalized = trim($text);

        if (in_array($normalized, ['إلغاء', 'الغاء', 'cancel'], true)) {
            $link->update(['pending_action' => null]);
            $bot->sendMessage($chatId, 'تم إلغاء البحث.');

            return;
        }

        if (mb_strlen($normalized) < 2) {
            $bot->sendMessage($chatId, 'اكتب كلمة حرفين على الأقل 🙂 أو اكتب "إلغاء" لإيقاف البحث:');

            return;
        }

        /*
         * علة كانت موجودة: كنا نلغي وضع البحث فورًا هون (قبل ما نعرض
         * النتيجة)، فلو ما لقى نتيجة وكتب كلمة تانية مباشرة (بدل ما
         * يضغط "🔍 بحث" من جديد كما اقترحت الرسالة)، النص كان يروح غلط
         * لأداة الذكاء الاصطناعي المختارة حاليًا بالقائمة الذكية. وضع
         * البحث الآن "لاصق" — يضل فعّال لعدة محاولات متتالية، ولا يخرج
         * منه الطالب إلا بضغط زر رئيسي تاني (راجع mainMenuButtons
         * بـ__invoke) أو كتابة "إلغاء".
         */
        $this->sendSearchResults($bot, $chatId, $normalized);
    }

    private function sendSearchResults(TelegramBotApi $bot, int|string $chatId, string $term): void
    {
        $escaped = str_replace(['%', '_'], ['\%', '\_'], $term);
        $contains = '%'.$escaped.'%';

        $courses = Course::query()
            ->where('is_active', true)
            ->where(function ($query) use ($contains) {
                $query->where('name_ar', 'like', $contains)
                    ->orWhere('name_en', 'like', $contains)
                    ->orWhere('code', 'like', $contains)
                    ->orWhere('keywords', 'like', $contains);
            })
            ->limit(5)
            ->get(['key', 'code', 'name_ar', 'name_en']);

        $files = CourseFile::query()
            ->where('is_published', true)
            ->where('status', 'ready')
            ->where('visibility', 'public')
            ->where('title', 'like', $contains)
            ->with('course:id,key,code,name_ar,name_en')
            ->limit(5)
            ->get(['id', 'course_id', 'title', 'kind', 'external_url']);

        $tools = Tool::query()
            ->where('is_active', true)
            ->where('name', 'like', $contains)
            ->limit(5)
            ->get(['id', 'name', 'type']);

        if ($courses->isEmpty() && $files->isEmpty() && $tools->isEmpty()) {
            $bot->sendMessage($chatId, '🔍 ما لقيت أي نتيجة لـ"'.TelegramBotApi::escapeHtml($term).'". جرّب كلمة تانية.');

            return;
        }

        $frontendUrl = rtrim((string) config('app.frontend_url'), '/');
        $lines = ['🔍 <b>نتائج البحث عن "'.TelegramBotApi::escapeHtml($term).'"</b>', ''];
        $keyboard = [];

        if ($courses->isNotEmpty()) {
            $lines[] = '📘 <b>مساقات:</b>';

            foreach ($courses as $course) {
                $name = (string) ($course->name_ar ?: $course->name_en);
                $lines[] = '• '.TelegramBotApi::escapeHtml($name).($course->code ? ' ('.TelegramBotApi::escapeHtml((string) $course->code).')' : '');
                $keyboard[] = [['text' => '📘 '.$name, 'callback_data' => 'hub:course:'.$course->key]];
            }

            $lines[] = '';
        }

        if ($files->isNotEmpty()) {
            $lines[] = '📄 <b>محتوى:</b>';

            foreach ($files as $file) {
                $course = $file->course;
                $courseName = $course ? (string) ($course->name_ar ?: $course->name_en) : '';
                $externalUrl = trim((string) $file->external_url);
                $link = $externalUrl !== ''
                    ? $externalUrl
                    : ($course ? $frontendUrl.'/course.html?course='.urlencode((string) $course->key).'&content='.(int) $file->id : '');

                $lines[] = '• '.TelegramBotApi::escapeHtml((string) $file->title).($courseName !== '' ? ' — '.TelegramBotApi::escapeHtml($courseName) : '');

                if ($link !== '') {
                    $lines[] = '  🔗 '.$link;
                }
            }

            $lines[] = '';
        }

        if ($tools->isNotEmpty()) {
            $lines[] = '🧰 <b>أدوات:</b>';

            foreach ($tools as $tool) {
                $lines[] = '• '.TelegramBotApi::escapeHtml((string) $tool->name);
                $keyboard[] = [['text' => '🧰 '.$tool->name, 'callback_data' => 'hub:tool:'.$tool->id]];
            }
        }

        $bot->sendMessage($chatId, implode("\n", $lines), $keyboard !== [] ? $keyboard : null);
    }

    // تسميات استهداف الإعلان بالمعاينة النهائية.
    private const ANNOUNCE_AUDIENCE_LABELS = [
        'all' => '👥 كل الطلاب',
        'year' => '🎓 طلاب سنة معيّنة',
        'course' => '📘 طلاب مادة معيّنة',
    ];

    /*
     * ============================================================
     * "📢 نشر إعلان" (مرحلة ٣، مُحدَّثة) — حصريًا لحسابات الإدارة
     * (User::isStaff(): role admin/supervisor)، والتعرّف على الحساب
     * فقط عبر telegram_links.telegram_chat_id المربوط فعليًا (لا أي
     * معطى يبعته العميل نفسه).
     *
     * استهداف دقيق مدعوم الآن: كل الطلاب (audience=all) / سنة معيّنة
     * (audience=year + audience_year) / طلاب مادة معيّنة (audience=course
     * + course_id، بنفس شرط التسجيل الفعّال المستخدم بمنطق الموقع نفسه:
     * my_courses.status بـ['registered','completed']). استهداف
     * "سنة+فصل" (audience=year_semester) غير مدعوم عمدًا — عمود
     * users.semester غير موجود فعليًا بقاعدة البيانات رغم إشارة الكود
     * له بأماكن تانية (علة موثّقة بـstep90)، فأي استهداف بيعتمد عليه
     * ما بيوصل لحدا فعليًا.
     *
     * عند النشر: يُنشأ Announcement عادي (يظهر بالموقع فورًا بنفس آلية
     * الإعلانات العادية — AnnouncementController::visibleTo() بالضبط)
     * + بث تيليجرام فوري لكل طالب مستهدف مربوط حسابه (telegram_links.
     * telegram_chat_id) — إرسال متزامن معزول لكل مستلم (try/catch مستقل
     * لكل رسالة، نفس نمط TelegramContentNotifier) حتى فشل رسالة وحدة
     * (بوت محظور، محادثة محذوفة...) ما يوقف الباقي. ⚠️ إرسال متزامن —
     * لعدد طلاب كبير جدًا ممكن يبطّئ الرد على تحديث تيليجرام نفسه؛
     * يستاهل مراجعة لاحقًا (queue حقيقي) لو الاستخدام كبر كثير.
     *
     * تسلسل الخطوات (pending_action.action = 'announce'): title →
     * body (اختياري) → audience (أزرار: الكل/سنة/مادة) → [pick_year:
     * أزرار ١-٤ | course_query: نص بحث → pickcourse: أزرار نتائج] →
     * confirm (أزرار نشر/إلغاء).
     * ============================================================
     */
    private function startAnnounceFlow(TelegramBotApi $bot, TelegramLink $link, int|string $chatId): void
    {
        $link->update(['pending_action' => ['action' => 'announce', 'step' => 'title', 'lecture_id' => null, 'data' => []]]);
        $bot->sendMessage($chatId, "📢 <b>نشر إعلان جديد</b>\n\nاكتب عنوان الإعلان (بحد أقصى ١٩٠ حرف)، أو اكتب \"إلغاء\" لإيقاف العملية:");
    }

    private function sendAnnounceAudiencePicker(TelegramBotApi $bot, int|string $chatId): void
    {
        $bot->sendMessage($chatId, '👥 مين المفروض يشوف هذا الإعلان؟', [
            [['text' => '👥 كل الطلاب', 'callback_data' => 'announce:aud:all']],
            [['text' => '🎓 طلاب سنة معيّنة', 'callback_data' => 'announce:aud:year']],
            [['text' => '📘 طلاب مادة معيّنة', 'callback_data' => 'announce:aud:course']],
        ]);
    }

    private function sendAnnouncePreview(TelegramBotApi $bot, int|string $chatId, array $data): void
    {
        $audienceLabel = self::ANNOUNCE_AUDIENCE_LABELS[$data['audience'] ?? ''] ?? 'غير محدّد';

        if (($data['audience'] ?? null) === 'year') {
            $audienceLabel .= ' (السنة '.((int) ($data['audience_year'] ?? 0)).')';
        } elseif (($data['audience'] ?? null) === 'course') {
            $audienceLabel .= ' ("'.TelegramBotApi::escapeHtml((string) ($data['course_name'] ?? '')).'")';
        }

        $preview = "📢 <b>معاينة الإعلان</b>\n\n<b>".TelegramBotApi::escapeHtml((string) $data['title']).'</b>';

        if (! empty($data['body'])) {
            $preview .= "\n\n".TelegramBotApi::escapeHtml((string) $data['body']);
        }

        $preview .= "\n\n".$audienceLabel;

        $bot->sendMessage($chatId, $preview, [[
            ['text' => '✅ نشر الإعلان', 'callback_data' => 'announce:publish'],
            ['text' => '❌ إلغاء', 'callback_data' => 'announce:cancel'],
        ]]);
    }

    private function handleAnnounceTextInput(TelegramBotApi $bot, TelegramLink $link, int|string $chatId, string $text): void
    {
        $normalized = trim($text);

        if (in_array($normalized, ['إلغاء', 'الغاء', 'cancel'], true)) {
            $link->update(['pending_action' => null]);
            $bot->sendMessage($chatId, 'تم إلغاء نشر الإعلان.');

            return;
        }

        if (! $link->user || ! $link->user->isStaff()) {
            $link->update(['pending_action' => null]);
            $bot->sendMessage($chatId, '⛔ هذا الخيار متاح فقط لحسابات الإدارة.');

            return;
        }

        $pending = $link->pending_action;
        $step = $pending['step'] ?? null;
        $data = $pending['data'] ?? [];

        if ($step === 'title') {
            if ($normalized === '' || mb_strlen($normalized) > 190) {
                $bot->sendMessage($chatId, 'عنوان غير صالح 🙂 اكتب عنوان الإعلان (نص غير فاضي، بحد أقصى ١٩٠ حرف):');

                return;
            }

            $data['title'] = $normalized;
            $link->update(['pending_action' => ['action' => 'announce', 'step' => 'body', 'lecture_id' => null, 'data' => $data]]);
            $bot->sendMessage($chatId, '📝 اكتب نص الإعلان (اختياري)، أو ارسل "تخطي" لتجاوزه:');

            return;
        }

        if ($step === 'body') {
            $body = in_array($normalized, ['تخطي', 'skip', '-'], true) ? null : $text;

            if ($body !== null && mb_strlen($body) > 10000) {
                $bot->sendMessage($chatId, 'النص طويل جدًا (بحد أقصى ١٠٠٠٠ حرف) 🙂 جرّب نص أقصر، أو ارسل "تخطي":');

                return;
            }

            $data['body'] = $body;
            $link->update(['pending_action' => ['action' => 'announce', 'step' => 'audience', 'lecture_id' => null, 'data' => $data]]);
            $this->sendAnnounceAudiencePicker($bot, $chatId);

            return;
        }

        if ($step === 'course_query') {
            if (mb_strlen($normalized) < 2) {
                $bot->sendMessage($chatId, 'اكتب حرفين على الأقل من اسم المادة أو رمزها:');

                return;
            }

            $escaped = str_replace(['%', '_'], ['\%', '\_'], $normalized);
            $contains = '%'.$escaped.'%';

            $courses = Course::query()
                ->where('is_active', true)
                ->where(function ($query) use ($contains) {
                    $query->where('name_ar', 'like', $contains)
                        ->orWhere('name_en', 'like', $contains)
                        ->orWhere('code', 'like', $contains);
                })
                ->limit(8)
                ->get(['key', 'code', 'name_ar', 'name_en']);

            if ($courses->isEmpty()) {
                $bot->sendMessage($chatId, 'ما لقيت أي مادة مطابقة 🙂 جرّب اسم أو رمز تاني، أو اكتب "إلغاء":');

                return;
            }

            $keyboard = $courses->map(function (Course $course) {
                $label = trim(($course->code ? $course->code.' — ' : '').(string) ($course->name_ar ?: $course->name_en));

                return [['text' => $label, 'callback_data' => 'announce:pickcourse:'.$course->key]];
            })->values()->all();

            $bot->sendMessage($chatId, 'اختر المادة المقصودة:', $keyboard);

            return;
        }

        $bot->sendMessage($chatId, 'استخدم الأزرار يلي فوق 🙂 أو اكتب "إلغاء" لإيقاف العملية.');
    }

    private function handleAnnounceCallback(TelegramBotApi $bot, array $callbackQuery): void
    {
        $callbackId = (string) ($callbackQuery['id'] ?? '');
        $chatId = $callbackQuery['message']['chat']['id'] ?? null;
        $data = (string) ($callbackQuery['data'] ?? '');
        $action = substr($data, strlen('announce:'));
        [$key, $arg] = array_pad(explode(':', $action, 2), 2, null);

        if (! $chatId) {
            $bot->answerCallbackQuery($callbackId);

            return;
        }

        $link = TelegramLink::query()
            ->whereNotNull('telegram_chat_id')
            ->where('telegram_chat_id', $chatId)
            ->first();

        if (! $link || ! $link->user || ! $link->user->isStaff()) {
            $bot->answerCallbackQuery($callbackId, 'غير مخوّل.');

            return;
        }

        $pending = $link->pending_action;
        $pendingData = $pending['data'] ?? [];

        if ($key === 'cancel') {
            $link->update(['pending_action' => null]);
            $bot->answerCallbackQuery($callbackId, 'تم الإلغاء.');
            $bot->sendMessage($chatId, 'تم إلغاء نشر الإعلان.');

            return;
        }

        if ($key === 'aud') {
            if (($pending['step'] ?? null) !== 'audience') {
                $bot->answerCallbackQuery($callbackId);

                return;
            }

            $bot->answerCallbackQuery($callbackId);

            if ($arg === 'all') {
                $pendingData['audience'] = 'all';
                $link->update(['pending_action' => ['action' => 'announce', 'step' => 'confirm', 'lecture_id' => null, 'data' => $pendingData]]);
                $this->sendAnnouncePreview($bot, $chatId, $pendingData);

                return;
            }

            if ($arg === 'year') {
                $link->update(['pending_action' => ['action' => 'announce', 'step' => 'pick_year', 'lecture_id' => null, 'data' => $pendingData]]);
                $bot->sendMessage($chatId, '🎓 اختر السنة المستهدفة:', [
                    [
                        ['text' => 'السنة ١', 'callback_data' => 'announce:year:1'],
                        ['text' => 'السنة ٢', 'callback_data' => 'announce:year:2'],
                    ],
                    [
                        ['text' => 'السنة ٣', 'callback_data' => 'announce:year:3'],
                        ['text' => 'السنة ٤', 'callback_data' => 'announce:year:4'],
                    ],
                ]);

                return;
            }

            if ($arg === 'course') {
                $link->update(['pending_action' => ['action' => 'announce', 'step' => 'course_query', 'lecture_id' => null, 'data' => $pendingData]]);
                $bot->sendMessage($chatId, '📘 اكتب اسم المادة أو رمزها:');

                return;
            }

            return;
        }

        if ($key === 'year') {
            if (($pending['step'] ?? null) !== 'pick_year') {
                $bot->answerCallbackQuery($callbackId);

                return;
            }

            $year = (int) $arg;

            if ($year < 1 || $year > 4) {
                $bot->answerCallbackQuery($callbackId);

                return;
            }

            $bot->answerCallbackQuery($callbackId);
            $pendingData['audience'] = 'year';
            $pendingData['audience_year'] = $year;
            $link->update(['pending_action' => ['action' => 'announce', 'step' => 'confirm', 'lecture_id' => null, 'data' => $pendingData]]);
            $this->sendAnnouncePreview($bot, $chatId, $pendingData);

            return;
        }

        if ($key === 'pickcourse') {
            if (($pending['step'] ?? null) !== 'course_query') {
                $bot->answerCallbackQuery($callbackId);

                return;
            }

            $course = Course::query()->where('key', (string) $arg)->where('is_active', true)->first();

            if (! $course) {
                $bot->answerCallbackQuery($callbackId, 'المادة غير موجودة.');

                return;
            }

            $bot->answerCallbackQuery($callbackId);
            $pendingData['audience'] = 'course';
            $pendingData['course_id'] = $course->id;
            $pendingData['course_name'] = (string) ($course->name_ar ?: $course->name_en);
            $link->update(['pending_action' => ['action' => 'announce', 'step' => 'confirm', 'lecture_id' => null, 'data' => $pendingData]]);
            $this->sendAnnouncePreview($bot, $chatId, $pendingData);

            return;
        }

        if ($key === 'publish') {
            if (($pending['step'] ?? null) !== 'confirm') {
                $bot->answerCallbackQuery($callbackId);

                return;
            }

            $title = trim((string) ($pendingData['title'] ?? ''));
            $audience = $pendingData['audience'] ?? null;

            if ($title === '' || ! in_array($audience, ['all', 'year', 'course'], true)) {
                $link->update(['pending_action' => null]);
                $bot->answerCallbackQuery($callbackId, 'خطأ بالبيانات، ابدأ من جديد.');

                return;
            }

            $announcement = Announcement::create([
                'title' => $title,
                'body' => $pendingData['body'] ?? null,
                'active' => true,
                'audience' => $audience,
                'audience_year' => $audience === 'year' ? (int) $pendingData['audience_year'] : null,
                'course_id' => $audience === 'course' ? (int) $pendingData['course_id'] : null,
                'created_by' => $link->user_id,
            ]);

            $link->update(['pending_action' => null]);
            $bot->answerCallbackQuery($callbackId, 'تم النشر ✅');

            $sentCount = $this->broadcastAnnouncement($bot, $announcement);

            $bot->sendMessage(
                $chatId,
                "✅ تم نشر الإعلان بنجاح وهو ظاهر الآن بالموقع، وتم إرساله مباشرة لـ{$sentCount} طالب مربوط حسابهم بالبوت."
            );

            return;
        }

        $bot->answerCallbackQuery($callbackId);
    }

    /*
     * بث الإعلان مباشرة عبر تيليجرام لكل طالب مستهدف مربوط حسابه —
     * نفس قواعد مطابقة الجمهور المستخدمة بـAnnouncementController::
     * visibleTo() العامة بالضبط (audience=all لكل حد، audience=year
     * بمطابقة users.year، audience=course بمطابقة تسجيل فعّال
     * my_courses.status بـ['registered','completed']) — بدون فرع
     * year_semester (غير مدعوم، راجع التعليق أعلى دالة handleAnnounceCallback).
     * إرسال معزول لكل مستلم (try/catch مستقل) حتى فشل واحد ما يوقف الباقي.
     * يرجّع عدد الرسائل المُرسَلة فعليًا (لعرضه بتأكيد النشر للأدمن).
     */
    private function broadcastAnnouncement(TelegramBotApi $bot, Announcement $announcement): int
    {
        if ($announcement->audience === 'all') {
            $chatIds = TelegramLink::query()->whereNotNull('telegram_chat_id')->pluck('telegram_chat_id');
        } elseif ($announcement->audience === 'year') {
            $userIds = \App\Models\User::query()->where('year', $announcement->audience_year)->pluck('id');
            $chatIds = TelegramLink::query()->whereIn('user_id', $userIds)->whereNotNull('telegram_chat_id')->pluck('telegram_chat_id');
        } elseif ($announcement->audience === 'course') {
            $userIds = \App\Models\User::query()
                ->whereHas('myCourses', function ($query) use ($announcement) {
                    $query->where('courses.id', $announcement->course_id)->whereIn('my_courses.status', ['registered', 'completed']);
                })
                ->pluck('id');
            $chatIds = TelegramLink::query()->whereIn('user_id', $userIds)->whereNotNull('telegram_chat_id')->pluck('telegram_chat_id');
        } else {
            $chatIds = collect();
        }

        $message = '📢 <b>'.TelegramBotApi::escapeHtml((string) $announcement->title).'</b>';

        if (! empty($announcement->body)) {
            $message .= "\n\n".TelegramBotApi::escapeHtml((string) $announcement->body);
        }

        $sent = 0;

        foreach ($chatIds as $chatId) {
            try {
                $bot->sendMessage($chatId, $message);
                $sent++;
            } catch (\Throwable $error) {
                report($error);
            }
        }

        return $sent;
    }
}
