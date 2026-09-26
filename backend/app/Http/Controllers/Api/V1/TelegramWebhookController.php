<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Mail\ContactMessageMail;
use App\Models\Announcement;
use App\Models\Course;
use App\Models\CourseContentProgress;
use App\Models\CourseFile;
use App\Models\CourseSection;
use App\Models\CourseUnit;
use App\Models\Favorite;
use App\Models\GpaEntry;
use App\Models\ScheduleLecture;
use App\Models\TelegramLink;
use App\Models\Tool;
use App\Services\PlanCalculator;
use App\Services\TelegramAiAssistant;
use App\Services\TelegramBotApi;
use App\Services\TelegramContentNotifier;
use App\Services\TelegramGpaCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;

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
        'debug' => '🐛 ورشة الأكواد',
        'quiz' => '📝 مولّد أسئلة',
        'summarize' => '📄 تلخيص ملفات',
        // تفريعات داخلية لورشة الأكواد (لا تظهر بالبوابة الرئيسية،
        // بس جوّا بطاقة "ورشة الأكواد" نفسها — راجع sendDebugHubCard).
        'debug_explain' => '📖 شارح المنطق',
        'debug_optimize' => '⚡ محسّن الأداء',
    ];

    // تسميات بطاقة "ورشة الأكواد" الفرعية بترتيب ظهورها كأزرار — نفس
    // مفاتيح MODE_LABELS أعلاه (debug/debug_explain/debug_optimize).
    private const DEBUG_ACTION_KEYS = ['debug', 'debug_explain', 'debug_optimize'];

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
    private const MAIN_MENU_MY_COURSES = '📖 مساقاتي الحالية';
    private const MAIN_MENU_FAVORITES = '⭐ مفضلاتي';
    private const MAIN_MENU_CONTACT = '📨 تواصل معنا';
    private const MAIN_MENU_CONTRIBUTE = '📤 شارك ملف/مصدر';

    private const MAIN_MENU_KEYBOARD = [
        [['text' => self::MAIN_MENU_PLAN], ['text' => self::MAIN_MENU_GPA]],
        [['text' => self::MAIN_MENU_SCHEDULE], ['text' => self::MAIN_MENU_COURSES]],
        [['text' => self::MAIN_MENU_SEARCH], ['text' => self::MAIN_MENU_TOOLS]],
        [['text' => self::MAIN_MENU_MY_COURSES], ['text' => self::MAIN_MENU_FAVORITES]],
        [['text' => self::MAIN_MENU_CONTACT], ['text' => self::MAIN_MENU_CONTRIBUTE]],
        [['text' => self::MAIN_MENU_HELP]],
    ];

    // أزرار إضافية تظهر فقط لحسابات الإدارة (User::isStaff()) — راجع
    // buildMainMenuKeyboard().
    private const MAIN_MENU_ADMIN_ANNOUNCE = '📢 نشر إعلان';
    private const MAIN_MENU_ADMIN_TOOLS = '🧰 إدارة الأدوات';
    private const MAIN_MENU_ADMIN_CONTENT = '📚 إدارة المحتوى';
    private const MAIN_MENU_ADMIN_COURSES = '🎓 إدارة المساقات';

    // نفس ٣ قيم CourseFile... لا، نفس ٣ قيم Course::course_type — لكن
    // الطاقم من داخل البوت لا يختار إلا بين إجباري/اختياري (placeholder
    // نوع داخلي قديم غير مطروح هون).
    private const ADMIN_COURSE_TYPE_LABELS = [
        'required' => 'إجباري',
        'elective' => 'اختياري',
    ];

    // تسميات حقول تعديل الأداة — نفس أعمدة Tool (Staff/ToolController::validated()).
    private const ADMIN_TOOL_FIELD_LABELS = [
        'name' => 'الاسم', 'type' => 'النوع', 'description' => 'الوصف',
        'official_url' => 'الرابط الرسمي', 'explanation' => 'الشرح',
        'video_url' => 'رابط فيديو الشرح', 'sort_order' => 'ترتيب الظهور',
    ];

    /*
     * إدارة المحتوى (أقسام/وحدات/تصنيفات/ملفات) — نفس ١٤ نوع محتوى
     * بالضبط الموجودة بـStaff/CourseFileController::validatedData()
     * ونفس التسميات العربية المستخدمة أصلًا بـTelegramContentNotifier
     * وبالواجهة (admin.html::FILE_KIND_LABELS) حتى ما يقرأ الطاقم تسمية
     * مختلفة هون عن يلي يشوفه بلوحة "بناء المادة".
     */
    private const ADMIN_CONTENT_KIND_LABELS = [
        'pdf' => 'PDF', 'doc' => 'مستند', 'vid' => 'فيديو', 'youtube' => 'يوتيوب',
        'drive' => 'درايف', 'assignment' => 'تعيين', 'exercise' => 'تدريب',
        'exam' => 'اختبار', 'book' => 'مرجع', 'software' => 'برنامج',
        'github' => 'GitHub', 'link' => 'رابط', 'image' => 'صورة', 'other' => 'أخرى',
    ];

    // نفس ٤ قيم CourseFile::visibility بالضبط (راجع validatedData()).
    private const ADMIN_CONTENT_VISIBILITY_LABELS = [
        'public' => 'عام (لأي زائر)',
        'authenticated' => 'لأي حساب مسجّل دخول',
        'course_students' => 'لطلاب المادة المسجَّلين فقط',
        'staff_only' => 'لحسابات الإدارة فقط',
    ];

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
            } elseif (str_starts_with($callbackData, 'admtool:')) {
                $this->handleAdminToolCallback($bot, $callbackQuery);
            } elseif (str_starts_with($callbackData, 'admcontent:')) {
                $this->handleAdminContentCallback($bot, $callbackQuery);
            } elseif (str_starts_with($callbackData, 'admcourse:')) {
                $this->handleAdminCourseCallback($bot, $callbackQuery);
            } elseif (str_starts_with($callbackData, 'mycourse:')) {
                $this->handleMyCourseCallback($bot, $callbackQuery);
            } elseif (str_starts_with($callbackData, 'content:')) {
                $this->handleContentCallback($bot, $callbackQuery);
            } elseif (str_starts_with($callbackData, 'contact:')) {
                $this->handleContactCallback($bot, $callbackQuery);
            } elseif (str_starts_with($callbackData, 'contribute:')) {
                $this->handleContributeCallback($bot, $callbackQuery);
            } elseif (str_starts_with($callbackData, 'toolsnav:')) {
                $this->handleToolsNavCallback($bot, $callbackQuery);
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
                self::MAIN_MENU_HELP, self::MAIN_MENU_ADMIN_ANNOUNCE, self::MAIN_MENU_ADMIN_TOOLS,
                self::MAIN_MENU_ADMIN_CONTENT, self::MAIN_MENU_ADMIN_COURSES, self::MAIN_MENU_MY_COURSES,
                self::MAIN_MENU_FAVORITES, self::MAIN_MENU_CONTACT, self::MAIN_MENU_CONTRIBUTE,
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
            } elseif (str_starts_with($pendingAction, 'admtool')) {
                $this->handleAdminToolTextInput($bot, $link, $chatId, $text);
            } elseif (str_starts_with($pendingAction, 'admcontent')) {
                $this->handleAdminContentTextInput($bot, $link, $chatId, $text);
            } elseif (str_starts_with($pendingAction, 'admcourse')) {
                $this->handleAdminCourseTextInput($bot, $link, $chatId, $text);
            } elseif (str_starts_with($pendingAction, 'mycourse')) {
                $this->handleMyCourseTextInput($bot, $link, $chatId, $text);
            } elseif (str_starts_with($pendingAction, 'contact')) {
                $this->handleContactTextInput($bot, $link, $chatId, $text);
            } elseif (str_starts_with($pendingAction, 'contribute')) {
                $this->handleContributeTextInput($bot, $link, $chatId, $text);
            } else {
                $this->handleScheduleTextInput($bot, $link, $chatId, $text);
            }

            return response()->json(['ok' => true]);
        }

        if ($hasMedia) {
            /*
             * بأي محطة من محطات "ورشة الأكواد" الثلاث (فحص/شرح/تحسين)،
             * ملف بامتداد كود معروف (html/css/js/php...) يروح لمسار
             * الورشة لا التلخيص — أي ملف/صورة تانية (بأي وضع) يفضل
             * يروح للتلخيص العادي كما كان دايمًا.
             */
            $documentExtension = is_array($document)
                ? strtolower((string) pathinfo((string) ($document['file_name'] ?? ''), PATHINFO_EXTENSION))
                : '';

            if (
                in_array($link->currentMode(), self::DEBUG_ACTION_KEYS, true)
                && is_array($document)
                && in_array($documentExtension, self::CODE_FILE_EXTENSIONS, true)
            ) {
                $this->replyWithCodeFileDebug($bot, $aiAssistant, $chatId, $link->user, $document, $link->currentMode());

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
                "📚 المساقات — تصفّح مساقات الخطة حسب السنة والفصل (أو المساقات الاختيارية أو الأدوات الهندسية أو 🌳 شجرة المساقات الكاملة بضغطة وحدة)، وشوف تفاصيل أي مادة: الساعات المعتمدة، المتطلبات السابقة واللاحقة، مواضيع تحضيرية، الأدوات المرتبطة، ومحتواها العام — كل هذا من غير ما تفتح الموقع.\n".
                "📖 مساقاتي الحالية — مساقاتك المسجَّلة فعليًا، مع أزرار إضافة/حذف مساق مباشرة (تتزامن مع الصفحة الشخصية بالموقع فورًا).\n".
                "⭐ مفضلاتي — كل الملفات يلي حفظتها من أي مادة، بروابطها المباشرة، بمكان وحد.\n".
                "📤 شارك ملف/مصدر — عندك ملف أو مصدر مفيد لمادة معيّنة؟ اختر المادة (أو ادخل من داخل تفاصيلها مباشرة) وبنوصلك برابط جاهز لبوت رفع الملفات ببياناتك ومادتك معبّاة تلقائيًا.\n".
                "🔍 بحث — دور بكلمة وحدة عن مادة أو محتوى أو أداة بنفس الوقت.\n".
                "🧪 القائمة الذكية — معمل فيه أربع محطات ذكاء اصطناعي: مساعد أسئلة، ورشة أكواد (فحص/شرح/تحسين)، مولّد أسئلة، وتلخيص ملفات — بدّل بينها وقت ما بدك.\n".
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

        /*
         * "📖 مساقاتي الحالية" — مساقات الطالب المسجَّلة فعليًا
         * (my_courses.status = registered)، نفس الجدول الذي تقرأ/تكتب
         * منه الصفحة الشخصية بالموقع (MyCourseController) — إضافة/حذف
         * من هون تنعكس مباشرة هناك وبالعكس.
         */
        if (in_array($normalized, [self::MAIN_MENU_MY_COURSES, 'مساقاتي'], true)) {
            $this->sendMyCoursesList($bot, $chatId, $link->user);

            return response()->json(['ok' => true]);
        }

        /*
         * "⭐ مفضلاتي" — زر رئيسي مستقل (كان قبل هيك زر ثانوي مدفون
         * تحت "المساقات" فقط، فلم يلاحظه الطاقم بالتجربة الأولى).
         */
        if (in_array($normalized, [self::MAIN_MENU_FAVORITES, 'مفضلاتي', 'المفضلة'], true)) {
            $this->sendContentFavoritesList($bot, $chatId, $link->user, 1);

            return response()->json(['ok' => true]);
        }

        /*
         * "📨 تواصل معنا" — نفس مسار الموقع بالضبط (ContactController +
         * ContactMessageMail، بلا أي جدول جديد)، لكن بما إنه الحساب هون
         * مربوط أصلًا نعبّي الاسم والإيميل تلقائيًا من حساب الطالب
         * ونعرضهم بمعاينة قابلة للتعديل قبل الإرسال — أسرع من فورم
         * الموقع، ومصمَّمة لتبقى شغّالة لو صار بالمستقبل دعم لطلاب غير
         * مسجَّلين (بس تبدأ الحقول فاضية بدل مُعبَّأة).
         */
        if (in_array($normalized, [self::MAIN_MENU_CONTACT, 'تواصل معنا', 'تواصل'], true)) {
            $this->startContactFlow($bot, $link, $chatId);

            return response()->json(['ok' => true]);
        }

        /*
         * "📤 شارك ملف/مصدر" — زر مستقل بالقائمة الرئيسية، بيسأل عن
         * المادة أول شي (بعكس نفس الزر جوّا تفاصيل مادة محددة يلي ما
         * بيحتاج سؤال). ما بيخزّن ولا يرفع أي شيء بنفسه إطلاقًا — فقط
         * بيولّد رابط دخول جاهز لبوت الرفع الموجود مسبقًا (راجع
         * generateContributionLink).
         */
        if (in_array($normalized, [self::MAIN_MENU_CONTRIBUTE, 'شارك ملف', 'مشاركة ملف'], true)) {
            $this->startContributeFlow($bot, $link, $chatId);

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
         * "🧰 إدارة الأدوات" — نفس فحص الصلاحية الحقيقي أعلاه بالضبط،
         * لا يعتمد على ظهور الزر بالواجهة فقط.
         */
        if ($normalized === self::MAIN_MENU_ADMIN_TOOLS) {
            if (! $link->user->isStaff()) {
                $bot->sendMessage($chatId, '⛔ هذا الخيار متاح فقط لحسابات الإدارة.');

                return response()->json(['ok' => true]);
            }

            $this->sendAdminToolMenu($bot, $chatId, 1);

            return response()->json(['ok' => true]);
        }

        /*
         * "📚 إدارة المحتوى" — نفس فحص الصلاحية الحقيقي أعلاه بالضبط.
         * أول خطوة دايمًا: بحث عن المادة (بدل تصفّح كل المساقات).
         */
        if ($normalized === self::MAIN_MENU_ADMIN_CONTENT) {
            if (! $link->user->isStaff()) {
                $bot->sendMessage($chatId, '⛔ هذا الخيار متاح فقط لحسابات الإدارة.');

                return response()->json(['ok' => true]);
            }

            $this->startAdminContentFlow($bot, $link, $chatId);

            return response()->json(['ok' => true]);
        }

        /*
         * "🎓 إدارة المساقات" — نفس فحص الصلاحية الحقيقي أعلاه بالضبط.
         * إضافة مادة جديدة للخطة، أو تعديل مادة موجودة — نفس منطق
         * Staff/CourseController بالضبط (المفتاح، الصفحة المشتقة،
         * الترتيب)، فأي مادة تُضاف/تُعدَّل هون تظهر تلقائيًا بكل مكان
         * يقرأ من جدول courses (قوائم السنوات، الاختياريات، صفحة
         * المادة، والخطة الدراسية بالصفحة الشخصية) بلا أي خطوة إضافية.
         */
        if ($normalized === self::MAIN_MENU_ADMIN_COURSES) {
            if (! $link->user->isStaff()) {
                $bot->sendMessage($chatId, '⛔ هذا الخيار متاح فقط لحسابات الإدارة.');

                return response()->json(['ok' => true]);
            }

            $this->sendAdminCoursesMenu($bot, $chatId);

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
        // "ورشة الأكواد" فعليًا ثلاث أدوات (debug/debug_explain/debug_optimize)
        // — أي وحدة منها فعّالة حاليًا تعتبر الزر الرئيسي "مفعّل".
        $debugFamilyActive = in_array($currentMode, self::DEBUG_ACTION_KEYS, true);

        $label = function (string $key) use ($currentMode, $debugFamilyActive) {
            $text = self::MODE_LABELS[$key];
            $isActive = $key === 'debug' ? $debugFamilyActive : $key === $currentMode;

            return $isActive ? $text . ' ✅' : $text;
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
            "🧪 <b>معمل الذكاء الاصطناعي</b>\n\n".
            "أربع محطات جاهزة تشتغل عليها وقت ما بدك، كل وحدة بمهمتها:\n\n".
            "💬 مساعد أسئلة عام — لأي استفسار أكاديمي بتخصصك.\n".
            "🐛 ورشة الأكواد — تصحيح، شرح منطق، أو تحسين أداء أي كود.\n".
            "📝 مولّد أسئلة — أسئلة مراجعة اختيار من متعدد بلحظات.\n".
            "📄 تلخيص ملفات — صوّر صفحة أو ابعت PDF ويلخّصه لك.\n\n".
            'دوس على المحطة يلي بدك تدخلها 👇',
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

        /*
         * "ورشة الأكواد" بثلاث محطات فرعية (فحص/شرح/تحسين) — بطاقة
         * مخصصة فيها الثلاثة كأزرار بدل رسالة تأكيد عادية، تظهر مهما
         * كانت المحطة الفرعية المختارة، مع تمييز المفعّلة حاليًا.
         */
        if (in_array($mode, self::DEBUG_ACTION_KEYS, true)) {
            $this->sendDebugHubCard($bot, $chatId, $mode);

            return;
        }

        $confirmations = [
            'chat' => "💬 <b>غرفة الأسئلة الأكاديمية</b>\n\n".
                "اسأل بأي مجال بتخصصك — من دارة منطقية لغاية بنية بيانات — وبوصلك جواب واضح ومباشر.\n\n".
                '✍️ اكتب سؤالك الآن، أو ابعت صورة/PDF ورح يتلخّص لك مباشرة بغض النظر عن المحطة الحالية.',
            'quiz' => "📝 <b>محطة المراجعة السريعة</b>\n\n".
                "اختر موضوع جاهز من الأزرار تحت، أو اكتب أي عنوان دراسي تحب تراجعه، وبتوصلك ٥ أسئلة اختيار من متعدد فورًا (مع الإجابات بالنهاية).\n\n".
                '🔁 خلصت جولة وبدك وحدة جديدة؟ اكتب موضوع تاني وبس.',
            'summarize' => "📄 <b>ملخّص بضغطة</b>\n\n".
                'ابعتلي صورة صفحة أو ملف PDF، وبرجّعلك خلاصة نقاطها الأساسية جاهزة للمذاكرة — هاي شغّالة دايمًا بغض النظر عن المحطة المختارة.',
        ];

        if ($mode === 'quiz') {
            $bot->sendMessage($chatId, $confirmations[$mode], $this->buildQuizSubjectKeyboard());

            return;
        }

        $bot->sendMessage($chatId, $confirmations[$mode]);
    }

    /*
     * بطاقة "ورشة الأكواد" — محطة وحدة بواجهة البوابة، لكن جوّاها ثلاث
     * أدوات فعلية منفصلة (كل وحدة نداء Gemini مختلف تمامًا — راجع
     * TelegramAiAssistant::debugCode()/explainCode()/optimizeCode()):
     * فحص وتصحيح الأخطاء، شرح منطق الكود، أو اقتراح تحسينات أداء.
     * الزر المفعّل حاليًا (currentMode) يظهر بعلامة ✅، وأي كود يُبعث
     * بعدها (نص أو ملف) بيروح تلقائيًا لنفس الأداة المختارة.
     */
    private function sendDebugHubCard(TelegramBotApi $bot, int|string $chatId, string $currentMode): void
    {
        $actionDetails = [
            'debug' => ['title' => '🛠️ فحص وتصحيح الأخطاء', 'desc' => 'بيدلّك على الأخطاء البرمجية أو المنطقية سطر بسطر، وبيرجّعلك نسخة مصحَّحة كاملة كملف منفصل.'],
            'debug_explain' => ['title' => '📖 شارح المنطق', 'desc' => 'بيشرحلك شو بيسوي الكود وليش مكتوب هيك، خطوة بخطوة — من غير لا تصحيح ولا تعديل.'],
            'debug_optimize' => ['title' => '⚡ محسّن الأداء', 'desc' => 'الكود شغّال؟ بنراجعلك كفاءته وقراءته، وبنقترحلك نسخة أنظف وأسرع.'],
        ];

        $active = $actionDetails[$currentMode];
        $lines = [
            "🐛 <b>ورشة الأكواد</b>",
            '',
            "المحطة المفعّلة الآن: <b>{$active['title']}</b>",
            $active['desc'],
            '',
            '💻 يدعم: C / C++ / OOP / هياكل بيانات، Java / Python / خوارزميات، Verilog / VHDL / دارات رقمية، Assembly، وويب وقواعد بيانات (HTML/CSS/JS/SQL...).',
            '',
            '📤 ابعت الكود كنص عادي أو كملف — وبيوصلك الرد بنفس المحطة المختارة تحت. غيّر المحطة بأي وقت من الأزرار:',
        ];

        $keyboard = [];

        foreach (self::DEBUG_ACTION_KEYS as $key) {
            $label = $actionDetails[$key]['title'];
            $keyboard[] = [['text' => $key === $currentMode ? $label . ' ✅' : $label, 'callback_data' => 'mode:' . $key]];
        }

        $keyboard[] = [
            ['text' => '🔙 رجوع للمعمل', 'callback_data' => 'toolsnav:back'],
            ['text' => '🏠 القائمة الرئيسية', 'callback_data' => 'toolsnav:home'],
        ];

        $bot->sendMessage($chatId, implode("\n", $lines), $keyboard);
    }

    /*
     * أزرار التنقل بأسفل بطاقة "ورشة الأكواد" (وأي بطاقة مستقبلية
     * مشابهة) — "رجوع للمعمل" يعيد بطاقة sendMenu نفسها (لا يغيّر
     * الوضع الحالي إطلاقًا)، و"القائمة الرئيسية" مجرد تذكير بالأزرار
     * الدائمة تحت مربع الكتابة (بلا أي تأثير على الوضع أو أي بيانات).
     */
    private function handleToolsNavCallback(TelegramBotApi $bot, array $callbackQuery): void
    {
        $callbackId = (string) ($callbackQuery['id'] ?? '');
        $chatId = $callbackQuery['message']['chat']['id'] ?? null;
        $data = (string) ($callbackQuery['data'] ?? '');
        $action = substr($data, strlen('toolsnav:'));

        if (! $chatId) {
            $bot->answerCallbackQuery($callbackId);

            return;
        }

        $link = TelegramLink::query()->whereNotNull('telegram_chat_id')->where('telegram_chat_id', $chatId)->first();

        if (! $link) {
            $bot->answerCallbackQuery($callbackId, 'هذا الحساب مش مربوط.');

            return;
        }

        $bot->answerCallbackQuery($callbackId);

        if ($action === 'home') {
            $bot->sendMessageWithMainMenu($chatId, 'الأزرار الدائمة تحت مربع الكتابة 👇', $this->buildMainMenuKeyboard($link->user));

            return;
        }

        $this->sendMenu($bot, $chatId, $link->currentMode());
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
            if (in_array($mode, self::DEBUG_ACTION_KEYS, true)) {
                $this->sendCodeToolResult($bot, $chatId, $aiAssistant, $user, $mode, $text, 'code.txt');

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
     * ملف كود (html/css/js/php...) بأي محطة من "ورشة الأكواد" — يقرأ
     * محتوى الملف كنص عادي (لا رفع لـGemini Files مثل التلخيص، الكود
     * نص صرف أصلًا وحجمه صغير) ويمرّره لنفس sendCodeToolResult() يلي
     * يستخدمها مسار النص الحر كمان.
     */
    private function replyWithCodeFileDebug(
        TelegramBotApi $bot,
        TelegramAiAssistant $aiAssistant,
        int|string $chatId,
        \App\Models\User $user,
        array $document,
        string $mode = 'debug'
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
            $bot->sendMessage($chatId, 'الملف كبير جدًا لورشة الأكواد حاليًا (الحد ٣٠٠ كيلوبايت) — جرّب جزء أصغر من الكود.');

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
                $bot->sendMessage($chatId, 'الملف كبير جدًا لورشة الأكواد حاليًا (الحد ٣٠٠ كيلوبايت) — جرّب جزء أصغر من الكود.');

                return;
            }

            $code = (string) file_get_contents($localPath);
            $this->sendCodeToolResult($bot, $chatId, $aiAssistant, $user, $mode, $code, $originalName);
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
     * ملاحظات محطة كود (فحص/تحسين) كرسالة نصية قصيرة + نسخة الكود
     * الناتجة كملف منفصل (sendDocument) — بدل نص طويل يعمل سكرول
     * بالمحادثة، بناءً على طلب صريح من الطالب. يُستدعى من
     * sendCodeToolResult() لمحطتي "فحص وتصحيح" و"تحسين الأداء" (شارح
     * المنطق لا يمر من هون أصلًا — رد نصي فقط، بلا ملف).
     *
     * @param array{notes: string, fixed_code: string} $result
     */
    private function sendDebugResult(
        TelegramBotApi $bot,
        int|string $chatId,
        array $result,
        string $filename,
        string $titleLine = '🛠️ <b>ملاحظات الفحص</b>',
        string $fileCaption = '📄 الكود بعد المراجعة'
    ): void {
        $notes = trim($result['notes']);
        $safeNotes = $notes !== '' ? TelegramBotApi::escapeHtml($notes) : 'ما في ملاحظات إضافية.';
        $bot->sendMessage($chatId, "{$titleLine}\n\n{$safeNotes}");

        $fixedCode = $result['fixed_code'];

        if (trim($fixedCode) === '') {
            return;
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'tgcode_');

        try {
            file_put_contents($tmpPath, $fixedCode);
            $bot->sendDocument($chatId, $tmpPath, $filename, $fileCaption);
        } finally {
            @unlink($tmpPath);
        }
    }

    /*
     * نقطة دخول موحّدة لثلاث محطات "ورشة الأكواد" — تستقبل الكود مرة
     * وحدة وتوزّعه حسب المحطة المختارة حاليًا (mode) على الدالة
     * المناسبة بـTelegramAiAssistant، وتُخرج الرد بالشكل المناسب لكل
     * محطة (نص فقط لشارح المنطق، نص+ملف للفحص والتحسين). يُستدعى من
     * مسار النص الحر (routeFreeTextToAssistant) ومسار رفع الملف
     * (replyWithCodeFileDebug) كليهما.
     */
    private function sendCodeToolResult(
        TelegramBotApi $bot,
        int|string $chatId,
        TelegramAiAssistant $aiAssistant,
        \App\Models\User $user,
        string $mode,
        string $code,
        string $baseFilename
    ): void {
        if ($mode === 'debug_explain') {
            $explanation = trim($aiAssistant->explainCode($user, $code));
            $safeExplanation = $explanation !== '' ? TelegramBotApi::escapeHtml($explanation) : 'ما قدر المساعد يطلع بشرح لهذا الكود.';
            $bot->sendMessage($chatId, "📖 <b>شرح منطق الكود</b>\n\n{$safeExplanation}");

            return;
        }

        if ($mode === 'debug_optimize') {
            $result = $aiAssistant->optimizeCode($user, $code);
            $this->sendDebugResult(
                $bot,
                $chatId,
                ['notes' => $result['notes'], 'fixed_code' => $result['optimized_code']],
                'optimized_' . $baseFilename,
                '⚡ <b>ملاحظات تحسين الأداء</b>',
                '📄 النسخة بعد التحسين'
            );

            return;
        }

        $result = $aiAssistant->debugCode($user, $code);
        $this->sendDebugResult($bot, $chatId, $result, 'fixed_' . $baseFilename);
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
            $keyboard[] = [
                ['text' => self::MAIN_MENU_ADMIN_ANNOUNCE],
                ['text' => self::MAIN_MENU_ADMIN_TOOLS],
            ];
            $keyboard[] = [
                ['text' => self::MAIN_MENU_ADMIN_CONTENT],
                ['text' => self::MAIN_MENU_ADMIN_COURSES],
            ];
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
            [['text' => '🌳 شجرة المساقات الكاملة', 'callback_data' => 'hub:tree']],
            [['text' => '⭐ مفضلاتي', 'callback_data' => 'content:favorites:1']],
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

        if ($key === 'tree') {
            $this->sendCourseHubTree($bot, $chatId);

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

    /*
     * "🌳 شجرة المساقات الكاملة" — نظرة عامة على الخطة كلها (٤ سنوات)
     * بضغطة وحدة، بدل التنقّل سنة بسنة وفصل بفصل. تيليجرام ما بيدعم
     * رسم شجرة تفاعلية حقيقية متل الموقع، فالبديل هون: رسالة نصية لكل
     * سنة تسرد موادها الإجبارية مجمَّعة حسب الفصل (نظرة سريعة)، تحتها
     * زر لكل مادة يفتح نفس شاشة "تفاصيل المادة" الموجودة أصلًا
     * (المتطلبات السابقة واللاحقة، مواضيع تحضيرية، المحتوى، الأدوات) —
     * فالطالب يحصل عمليًا على نفس فائدة الشجرة بالموقع: يشوف مكان
     * أي مادة بالخطة، وبضغطة وحدة يعرف شو بتفتحله وشو لازم قبلها.
     */
    private function sendCourseHubTree(TelegramBotApi $bot, int|string $chatId): void
    {
        $bot->sendMessage($chatId, "🌳 <b>شجرة المساقات الكاملة</b>\n\nكل المواد الإجبارية بالخطة، مرتبة حسب السنة والفصل. اضغط أي مادة بالأزرار تحت رسالة سنتها لعرض تفاصيلها ومتطلباتها السابقة واللاحقة.");

        for ($year = 1; $year <= 4; $year++) {
            $firstSemester = ($year - 1) * 2 + 1;
            $secondSemester = $firstSemester + 1;

            $courses = Course::query()
                ->where('is_active', true)
                ->where('year', $year)
                ->where('course_type', 'required')
                ->whereIn('semester', [$firstSemester, $secondSemester])
                ->orderBy('semester')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(['key', 'code', 'name_ar', 'name_en', 'semester']);

            if ($courses->isEmpty()) {
                continue;
            }

            $bySemester = $courses->groupBy('semester');
            $lines = ["📘 <b>السنة {$year}</b>"];

            foreach ([$firstSemester => '1️⃣ الفصل الأول', $secondSemester => '2️⃣ الفصل الثاني'] as $semesterNumber => $label) {
                $semesterCourses = $bySemester->get($semesterNumber, collect());

                if ($semesterCourses->isEmpty()) {
                    continue;
                }

                $lines[] = '';
                $lines[] = "<b>{$label}:</b>";

                foreach ($semesterCourses as $course) {
                    $lines[] = '• '.TelegramBotApi::escapeHtml((string) ($course->name_ar ?: $course->name_en));
                }
            }

            $keyboard = $courses->map(function (Course $course) {
                $label = trim(($course->code ? $course->code.' — ' : '').(string) ($course->name_ar ?: $course->name_en));

                return [['text' => $label, 'callback_data' => 'hub:course:'.$course->key]];
            })->values()->all();

            $this->sendChunkedMessage($bot, $chatId, $lines, $keyboard);
        }

        $bot->sendMessage($chatId, '🔀 المساقات الاختيارية والأدوات الهندسية موجودة بقائمة "📚 المساقات" الرئيسية.', [[['text' => '🔙 رجوع للمساقات', 'callback_data' => 'hub:root']]]);
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

        /*
         * مواضيع يُنصح بمراجعتها قبل المادة (course_prep_topics) — طلب
         * صريح من الطاقم: "ماذا أراجع قبل المادة؟".
         */
        $prepTopics = $course->prepTopics()->get();

        if ($prepTopics->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '📚 <b>راجع هاي المواضيع قبل ما تبلش المادة:</b>';

            foreach ($prepTopics as $topic) {
                $lines[] = '• '.TelegramBotApi::escapeHtml((string) $topic->topic);

                if (! empty($topic->link)) {
                    $lines[] = '  🔗 '.$topic->link;
                }
            }
        }

        /*
         * المتطلبات اللاحقة: مواد تانية تحتاج هاي المادة كمتطلب سابق
         * (الاتجاه المعاكس لـ$course->prerequisites() أعلاه) — طلب
         * صريح من الطاقم.
         */
        $requiredFor = $course->requiredFor()->with('course')->get();

        if ($requiredFor->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '🔓 <b>مواد تانية تحتاج هاي المادة كمتطلب سابق:</b>';

            foreach ($requiredFor as $dependency) {
                $dependentName = $dependency->course?->name_ar ?? $dependency->course?->name_en;

                if ($dependentName) {
                    $lines[] = '• '.TelegramBotApi::escapeHtml((string) $dependentName);
                }
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
            [['text' => '⭐📊 تصفّح تفاعلي (مفضلة وإنجاز)', 'callback_data' => 'content:course:'.$course->key.':1']],
        ];

        if ($tools->isNotEmpty()) {
            $keyboard[] = [['text' => "🧰 أدوات المادة ({$tools->count()})", 'callback_data' => 'hub:coursetools:'.$course->key]];
        }

        $keyboard[] = [['text' => '📤 شارك ملف/مصدر لهاي المادة', 'callback_data' => 'contribute:course:'.$course->key]];
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

    /*
     * ============================================================
     * "🧰 إدارة الأدوات" — أول وحدة من صلاحيات الإدارة الكاملة
     * المؤجّلة بـstep90 (محتوى/أدوات/مساقات/خطة دراسية)، وأبسطها —
     * نفس تحقق الصلاحية الحقيقي (User::isStaff() عبر telegram_chat_id
     * المربوط فعليًا) المستخدم بميزة الإعلانات بالضبط. القواعد كلها
     * منقولة حرفيًا من Staff/ToolController::validated(): type من
     * Tool::TYPES، official_url إجباري إلا لو النوع "concept" (وحينها
     * explanation هو الإجباري بدلًا منه)، description بحد أقصى ٣٠٠
     * حرف، إلخ. ⚠️ ربط الأداة بمساقات معيّنة (course_ids) غير مدعوم
     * من داخل البوت حاليًا — أداة جديدة تُنشأ بلا مساقات مرتبطة، ويبقى
     * ربطها بالمساقات من لوحة تحكم الموقع فقط (تحسين مؤجَّل).
     *
     * تسلسل الإضافة (pending_action.action = 'admtool_add'): name →
     * type (أزرار) → description → (official_url أو explanation حسب
     * النوع) → video_url (اختياري) → confirm.
     * تسلسل التعديل (action = 'admtool_edit'): field picker (أزرار) →
     * قيمة جديدة (نص، أو أزرار مباشرة لو الحقل "type") → حفظ فوري.
     * ============================================================
     */
    private function sendAdminToolMenu(TelegramBotApi $bot, int|string $chatId, int $page): void
    {
        $query = Tool::query()->orderBy('sort_order')->orderBy('name');
        $total = (clone $query)->count();
        $tools = $query->forPage($page, self::COURSE_HUB_PAGE_SIZE)->get(['id', 'name', 'type', 'is_active']);

        $keyboard = [[['text' => '➕ إضافة أداة جديدة', 'callback_data' => 'admtool:new']]];

        foreach ($tools as $tool) {
            $typeLabel = self::INLINE_TOOL_TYPE_LABELS[$tool->type] ?? '';
            $statusIcon = $tool->is_active ? '🟢' : '🔴';
            $label = trim($statusIcon.' '.($typeLabel !== '' ? $typeLabel.' — ' : '').(string) $tool->name);
            $keyboard[] = [['text' => $label, 'callback_data' => 'admtool:detail:'.$tool->id]];
        }

        $lastPage = max(1, (int) ceil($total / self::COURSE_HUB_PAGE_SIZE));
        $pagerRow = [];

        if ($page > 1) {
            $pagerRow[] = ['text' => '⬅️ السابق', 'callback_data' => 'admtool:list:'.($page - 1)];
        }

        if ($page < $lastPage) {
            $pagerRow[] = ['text' => 'التالي ➡️', 'callback_data' => 'admtool:list:'.($page + 1)];
        }

        if ($pagerRow !== []) {
            $keyboard[] = $pagerRow;
        }

        $bot->sendMessage(
            $chatId,
            "🧰 <b>إدارة الأدوات</b> (صفحة {$page} من {$lastPage})\n\n🟢 مفعّلة / 🔴 معطّلة — اختر أداة لتعديلها أو حذفها، أو أضف أداة جديدة:",
            $keyboard
        );
    }

    private function sendAdminToolDetail(TelegramBotApi $bot, int|string $chatId, int $toolId): void
    {
        $tool = Tool::query()->find($toolId);

        if (! $tool) {
            $bot->sendMessage($chatId, '⚠️ هذه الأداة غير موجودة (يمكن اتحذفت).', [[['text' => '🔙 كل الأدوات', 'callback_data' => 'admtool:list:1']]]);

            return;
        }

        $typeLabel = self::INLINE_TOOL_TYPE_LABELS[$tool->type] ?? $tool->type;
        $lines = [
            '🧰 <b>'.TelegramBotApi::escapeHtml((string) $tool->name).'</b>',
            '🏷️ النوع: '.$typeLabel,
            'الحالة: '.($tool->is_active ? '🟢 مفعّلة' : '🔴 معطّلة'),
            '🔢 ترتيب الظهور: '.(int) $tool->sort_order,
            '',
            '📝 الوصف: '.TelegramBotApi::escapeHtml((string) $tool->description),
        ];

        if (! empty($tool->official_url)) {
            $lines[] = '🌐 الرابط الرسمي: '.$tool->official_url;
        }

        if (! empty($tool->explanation)) {
            $lines[] = '';
            $lines[] = '💡 الشرح: '.TelegramBotApi::escapeHtml((string) $tool->explanation);
        }

        if (! empty($tool->video_url)) {
            $lines[] = '🎬 فيديو شرح: '.$tool->video_url;
        }

        $keyboard = [
            [
                ['text' => '✏️ تعديل', 'callback_data' => 'admtool:edit:'.$tool->id],
                ['text' => $tool->is_active ? '🔴 تعطيل' : '🟢 تفعيل', 'callback_data' => 'admtool:toggle:'.$tool->id],
            ],
            [['text' => '🗑️ حذف الأداة', 'callback_data' => 'admtool:delconfirm:'.$tool->id]],
            [['text' => '🔙 كل الأدوات', 'callback_data' => 'admtool:list:1']],
        ];

        $bot->sendMessage($chatId, implode("\n", $lines), $keyboard);
    }

    private function sendAdminToolFieldPicker(TelegramBotApi $bot, int|string $chatId, int $toolId): void
    {
        $keyboard = [];
        $row = [];

        foreach (self::ADMIN_TOOL_FIELD_LABELS as $field => $label) {
            $row[] = ['text' => $label, 'callback_data' => 'admtool:editfield:'.$toolId.':'.$field];

            if (count($row) === 2) {
                $keyboard[] = $row;
                $row = [];
            }
        }

        if ($row !== []) {
            $keyboard[] = $row;
        }

        $keyboard[] = [['text' => '🔙 رجوع', 'callback_data' => 'admtool:detail:'.$toolId]];

        $bot->sendMessage($chatId, '✏️ أي حقل بدك تعدّل؟', $keyboard);
    }

    private function isValidHttpUrl(string $value): bool
    {
        return (bool) preg_match('#^https?://#i', $value) && filter_var($value, FILTER_VALIDATE_URL) !== false;
    }

    private function handleAdminToolCallback(TelegramBotApi $bot, array $callbackQuery): void
    {
        $callbackId = (string) ($callbackQuery['id'] ?? '');
        $chatId = $callbackQuery['message']['chat']['id'] ?? null;
        $data = (string) ($callbackQuery['data'] ?? '');
        $action = substr($data, strlen('admtool:'));
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

        $bot->answerCallbackQuery($callbackId);
        $pending = $link->pending_action;
        $pendingData = $pending['data'] ?? [];

        if ($key === 'list') {
            $this->sendAdminToolMenu($bot, $chatId, max(1, (int) $arg));

            return;
        }

        if ($key === 'detail') {
            $link->update(['pending_action' => null]);
            $this->sendAdminToolDetail($bot, $chatId, (int) $arg);

            return;
        }

        if ($key === 'new') {
            $link->update(['pending_action' => ['action' => 'admtool_add', 'step' => 'name', 'lecture_id' => null, 'data' => []]]);
            $bot->sendMessage($chatId, "🧰 <b>إضافة أداة جديدة</b>\n\nاكتب اسم الأداة (بحد أقصى ١٩٠ حرف)، أو اكتب \"إلغاء\":");

            return;
        }

        if ($key === 'newtype') {
            if (($pending['step'] ?? null) !== 'type' || ! in_array($arg, Tool::TYPES, true)) {
                return;
            }

            $pendingData['type'] = $arg;
            $link->update(['pending_action' => ['action' => 'admtool_add', 'step' => 'description', 'lecture_id' => null, 'data' => $pendingData]]);
            $bot->sendMessage($chatId, '📝 اكتب وصف مختصر للأداة (بحد أقصى ٣٠٠ حرف):');

            return;
        }

        if ($key === 'edit') {
            $this->sendAdminToolFieldPicker($bot, $chatId, (int) $arg);

            return;
        }

        if ($key === 'editfield') {
            [$toolId, $field] = array_pad(explode(':', (string) $arg, 2), 2, null);
            $tool = Tool::query()->find((int) $toolId);

            if (! $tool || ! array_key_exists($field, self::ADMIN_TOOL_FIELD_LABELS)) {
                return;
            }

            if ($field === 'type') {
                $keyboard = [];

                foreach (Tool::TYPES as $type) {
                    $keyboard[] = [[
                        'text' => (self::INLINE_TOOL_TYPE_LABELS[$type] ?? $type).($tool->type === $type ? ' ✅' : ''),
                        'callback_data' => 'admtool:settype:'.$tool->id.':'.$type,
                    ]];
                }

                $keyboard[] = [['text' => '🔙 رجوع', 'callback_data' => 'admtool:edit:'.$tool->id]];
                $bot->sendMessage($chatId, '🏷️ اختر النوع الجديد:', $keyboard);

                return;
            }

            $link->update(['pending_action' => ['action' => 'admtool_edit', 'step' => $field, 'lecture_id' => null, 'data' => ['id' => $tool->id, 'field' => $field]]]);

            $currentValue = TelegramBotApi::escapeHtml((string) ($tool->{$field} ?? '')) ?: '(فاضي)';
            $nullable = in_array($field, ['official_url', 'explanation', 'video_url'], true);
            $hint = $nullable ? "\nاكتب \"-\" لمسح القيمة الحالية (لو مسموح لهذا الحقل)." : '';

            $bot->sendMessage(
                $chatId,
                '✏️ القيمة الحالية لـ"'.self::ADMIN_TOOL_FIELD_LABELS[$field]."\":\n{$currentValue}\n\nاكتب القيمة الجديدة:{$hint}"
            );

            return;
        }

        if ($key === 'settype') {
            [$toolId, $type] = array_pad(explode(':', (string) $arg, 2), 2, null);
            $tool = Tool::query()->find((int) $toolId);

            if (! $tool || ! in_array($type, Tool::TYPES, true)) {
                return;
            }

            $tool->update(['type' => $type]);
            $bot->sendMessage($chatId, '✅ تم تحديث النوع.');
            $this->sendAdminToolDetail($bot, $chatId, $tool->id);

            return;
        }

        if ($key === 'toggle') {
            $tool = Tool::query()->find((int) $arg);

            if (! $tool) {
                return;
            }

            $tool->update(['is_active' => ! $tool->is_active]);
            $this->sendAdminToolDetail($bot, $chatId, $tool->id);

            return;
        }

        if ($key === 'delconfirm') {
            $tool = Tool::query()->find((int) $arg);

            if (! $tool) {
                return;
            }

            $bot->sendMessage(
                $chatId,
                '⚠️ متأكد تريد حذف أداة "'.TelegramBotApi::escapeHtml((string) $tool->name).'"؟ هذا الإجراء لا يمكن التراجع عنه.',
                [[
                    ['text' => '✅ نعم، احذف', 'callback_data' => 'admtool:delyes:'.$tool->id],
                    ['text' => '❌ لا، رجوع', 'callback_data' => 'admtool:delno:'.$tool->id],
                ]]
            );

            return;
        }

        if ($key === 'delno') {
            $this->sendAdminToolDetail($bot, $chatId, (int) $arg);

            return;
        }

        if ($key === 'delyes') {
            $tool = Tool::query()->find((int) $arg);

            if ($tool) {
                $name = (string) $tool->name;
                $tool->delete();
                $bot->sendMessage($chatId, '🗑️ تم حذف أداة "'.TelegramBotApi::escapeHtml($name).'".');
            }

            $this->sendAdminToolMenu($bot, $chatId, 1);

            return;
        }

        if ($key === 'cancel') {
            $link->update(['pending_action' => null]);
            $bot->sendMessage($chatId, 'تم الإلغاء.');
            $this->sendAdminToolMenu($bot, $chatId, 1);

            return;
        }

        if ($key === 'create') {
            if (($pending['step'] ?? null) !== 'confirm' || ($pending['action'] ?? null) !== 'admtool_add') {
                return;
            }

            $nextSortOrder = ((int) Tool::query()->max('sort_order')) + 1;

            $tool = Tool::create([
                'name' => $pendingData['name'],
                'type' => $pendingData['type'],
                'description' => $pendingData['description'],
                'official_url' => $pendingData['official_url'] ?? null,
                'explanation' => $pendingData['explanation'] ?? null,
                'video_url' => $pendingData['video_url'] ?? null,
                'is_active' => true,
                'sort_order' => $nextSortOrder,
            ]);

            $link->update(['pending_action' => null]);
            $bot->sendMessage($chatId, '✅ تمت إضافة الأداة بنجاح.');
            $this->sendAdminToolDetail($bot, $chatId, $tool->id);

            return;
        }
    }

    private function handleAdminToolTextInput(TelegramBotApi $bot, TelegramLink $link, int|string $chatId, string $text): void
    {
        $normalized = trim($text);

        if (in_array($normalized, ['إلغاء', 'الغاء', 'cancel'], true)) {
            $link->update(['pending_action' => null]);
            $bot->sendMessage($chatId, 'تم الإلغاء.');
            $this->sendAdminToolMenu($bot, $chatId, 1);

            return;
        }

        if (! $link->user || ! $link->user->isStaff()) {
            $link->update(['pending_action' => null]);
            $bot->sendMessage($chatId, '⛔ هذا الخيار متاح فقط لحسابات الإدارة.');

            return;
        }

        $pending = $link->pending_action;
        $action = $pending['action'] ?? null;
        $step = $pending['step'] ?? null;
        $data = $pending['data'] ?? [];

        if ($action === 'admtool_add') {
            if ($step === 'name') {
                if ($normalized === '' || mb_strlen($normalized) > 190) {
                    $bot->sendMessage($chatId, 'اسم غير صالح 🙂 اكتب اسم الأداة (نص غير فاضي، بحد أقصى ١٩٠ حرف):');

                    return;
                }

                $data['name'] = $normalized;
                $link->update(['pending_action' => ['action' => 'admtool_add', 'step' => 'type', 'lecture_id' => null, 'data' => $data]]);

                $keyboard = [];

                foreach (Tool::TYPES as $type) {
                    $keyboard[] = [['text' => self::INLINE_TOOL_TYPE_LABELS[$type] ?? $type, 'callback_data' => 'admtool:newtype:'.$type]];
                }

                $bot->sendMessage($chatId, '🏷️ اختر نوع الأداة:', $keyboard);

                return;
            }

            if ($step === 'description') {
                if ($normalized === '' || mb_strlen($normalized) > 300) {
                    $bot->sendMessage($chatId, 'وصف غير صالح 🙂 اكتب وصف مختصر (نص غير فاضي، بحد أقصى ٣٠٠ حرف):');

                    return;
                }

                $data['description'] = $normalized;
                $isConcept = ($data['type'] ?? null) === 'concept';
                $nextStep = $isConcept ? 'explanation' : 'official_url';
                $link->update(['pending_action' => ['action' => 'admtool_add', 'step' => $nextStep, 'lecture_id' => null, 'data' => $data]]);

                $bot->sendMessage(
                    $chatId,
                    $isConcept
                        ? '💡 اكتب شرح المفهوم (بحد أقصى ٥٠٠٠ حرف):'
                        : '🌐 اكتب الرابط الرسمي للأداة (لازم يبدأ بـ http:// أو https://):'
                );

                return;
            }

            if ($step === 'official_url') {
                if (! $this->isValidHttpUrl($normalized) || mb_strlen($normalized) > 500) {
                    $bot->sendMessage($chatId, 'رابط غير صالح 🙂 لازم يبدأ بـ http:// أو https:// (بحد أقصى ٥٠٠ حرف):');

                    return;
                }

                $data['official_url'] = $normalized;
                $link->update(['pending_action' => ['action' => 'admtool_add', 'step' => 'video_url', 'lecture_id' => null, 'data' => $data]]);
                $bot->sendMessage($chatId, '🎬 اكتب رابط فيديو شرح (اختياري)، أو ارسل "تخطي":');

                return;
            }

            if ($step === 'explanation') {
                if ($normalized === '' || mb_strlen($normalized) > 5000) {
                    $bot->sendMessage($chatId, 'شرح غير صالح 🙂 اكتب نص غير فاضي (بحد أقصى ٥٠٠٠ حرف):');

                    return;
                }

                $data['explanation'] = $normalized;
                $link->update(['pending_action' => ['action' => 'admtool_add', 'step' => 'video_url', 'lecture_id' => null, 'data' => $data]]);
                $bot->sendMessage($chatId, '🎬 اكتب رابط فيديو شرح (اختياري)، أو ارسل "تخطي":');

                return;
            }

            if ($step === 'video_url') {
                if (in_array($normalized, ['تخطي', 'skip', '-'], true)) {
                    $data['video_url'] = null;
                } elseif ($this->isValidHttpUrl($normalized) && mb_strlen($normalized) <= 500) {
                    $data['video_url'] = $normalized;
                } else {
                    $bot->sendMessage($chatId, 'رابط غير صالح 🙂 لازم يبدأ بـ http:// أو https://، أو ارسل "تخطي":');

                    return;
                }

                $link->update(['pending_action' => ['action' => 'admtool_add', 'step' => 'confirm', 'lecture_id' => null, 'data' => $data]]);

                $typeLabel = self::INLINE_TOOL_TYPE_LABELS[$data['type']] ?? $data['type'];
                $preview = "🧰 <b>معاينة الأداة الجديدة</b>\n\n".
                    '<b>'.TelegramBotApi::escapeHtml((string) $data['name'])."</b>\n".
                    "🏷️ {$typeLabel}\n".
                    '📝 '.TelegramBotApi::escapeHtml((string) $data['description']);

                if (! empty($data['official_url'])) {
                    $preview .= "\n🌐 ".$data['official_url'];
                }

                if (! empty($data['explanation'])) {
                    $preview .= "\n💡 ".TelegramBotApi::escapeHtml((string) $data['explanation']);
                }

                if (! empty($data['video_url'])) {
                    $preview .= "\n🎬 ".$data['video_url'];
                }

                $bot->sendMessage($chatId, $preview, [[
                    ['text' => '✅ إضافة الأداة', 'callback_data' => 'admtool:create'],
                    ['text' => '❌ إلغاء', 'callback_data' => 'admtool:cancel'],
                ]]);

                return;
            }

            $bot->sendMessage($chatId, 'استخدم الأزرار يلي فوق 🙂 أو اكتب "إلغاء" لإيقاف العملية.');

            return;
        }

        if ($action === 'admtool_edit') {
            $tool = Tool::query()->find((int) ($data['id'] ?? 0));
            $field = $data['field'] ?? null;

            if (! $tool || ! $field) {
                $link->update(['pending_action' => null]);
                $bot->sendMessage($chatId, '⚠️ تعذّر إيجاد الأداة، ابدأ من جديد.');

                return;
            }

            $isClear = in_array($normalized, ['-', 'تخطي', 'skip'], true) && in_array($field, ['official_url', 'explanation', 'video_url'], true);

            if ($field === 'name') {
                if ($normalized === '' || mb_strlen($normalized) > 190) {
                    $bot->sendMessage($chatId, 'اسم غير صالح 🙂 اكتب نص غير فاضي (بحد أقصى ١٩٠ حرف):');

                    return;
                }

                $tool->update(['name' => $normalized]);
            } elseif ($field === 'description') {
                if ($normalized === '' || mb_strlen($normalized) > 300) {
                    $bot->sendMessage($chatId, 'وصف غير صالح 🙂 اكتب نص غير فاضي (بحد أقصى ٣٠٠ حرف):');

                    return;
                }

                $tool->update(['description' => $normalized]);
            } elseif ($field === 'official_url') {
                if ($isClear) {
                    if ($tool->type !== 'concept') {
                        $bot->sendMessage($chatId, 'ما بقدر أمسح الرابط الرسمي — إجباري لكل الأنواع ما عدا "مفهوم". غيّر النوع أول، أو اكتب رابط صالح:');

                        return;
                    }

                    $tool->update(['official_url' => null]);
                } else {
                    if (! $this->isValidHttpUrl($normalized) || mb_strlen($normalized) > 500) {
                        $bot->sendMessage($chatId, 'رابط غير صالح 🙂 لازم يبدأ بـ http:// أو https:// (بحد أقصى ٥٠٠ حرف):');

                        return;
                    }

                    $tool->update(['official_url' => $normalized]);
                }
            } elseif ($field === 'explanation') {
                if ($isClear) {
                    if ($tool->type === 'concept') {
                        $bot->sendMessage($chatId, 'ما بقدر أمسح الشرح — إجباري لنوع "مفهوم". غيّر النوع أول، أو اكتب شرح:');

                        return;
                    }

                    $tool->update(['explanation' => null]);
                } else {
                    if ($normalized === '' || mb_strlen($normalized) > 5000) {
                        $bot->sendMessage($chatId, 'شرح غير صالح 🙂 اكتب نص غير فاضي (بحد أقصى ٥٠٠٠ حرف):');

                        return;
                    }

                    $tool->update(['explanation' => $normalized]);
                }
            } elseif ($field === 'video_url') {
                if ($isClear) {
                    $tool->update(['video_url' => null]);
                } else {
                    if (! $this->isValidHttpUrl($normalized) || mb_strlen($normalized) > 500) {
                        $bot->sendMessage($chatId, 'رابط غير صالح 🙂 لازم يبدأ بـ http:// أو https:// (بحد أقصى ٥٠٠ حرف)، أو ارسل "-" لمسحه:');

                        return;
                    }

                    $tool->update(['video_url' => $normalized]);
                }
            } elseif ($field === 'sort_order') {
                if (! preg_match('/^\d{1,5}$/', $normalized) || (int) $normalized > 65535) {
                    $bot->sendMessage($chatId, 'رقم غير صالح 🙂 اكتب رقم صحيح بين ٠ و٦٥٥٣٥:');

                    return;
                }

                $tool->update(['sort_order' => (int) $normalized]);
            }

            $link->update(['pending_action' => null]);
            $bot->sendMessage($chatId, '✅ تم الحفظ.');
            $this->sendAdminToolDetail($bot, $chatId, $tool->id);

            return;
        }
    }

    /*
     * ============================================================
     * "📚 إدارة المحتوى" — إدارة كاملة لبنية محتوى المساقات من داخل
     * البوت (قسم ← وحدة ← تصنيف ← ملف)، بنفس منطق ونماذج الموقع تمامًا
     * (Staff/CourseStructureController + Staff/CourseFileController)،
     * فأي عملية هون تُخزَّن مباشرة بنفس الجداول التي تقرأ/تكتب منها لوحة
     * "بناء المادة" بالموقع — لا مسار منفصل ولا مزامنة لاحقة، الكتابة
     * نفسها متزامنة فورًا. الملاحظة الوحيدة المتفق عليها مع الطاقم: حاليًا
     * روابط خارجية (https) فقط، بلا رفع ملفات فعلي عبر تيليجرام، تمامًا
     * كحال لوحة الموقع نفسها اليوم (external_url هو المسار الوحيد الحي).
     *
     * التنبيه المتزامن مع الموقع مطلوب فقط عند إضافة ملف منشور — بنفس
     * نموذج TelegramContentNotifier::notifyNewFile() المستخدَم أصلًا من
     * CourseFileController@store (طلاب المادة المسجَّلين تلقائيًا).
     * تنبيه جرس الموقع نفسه مبني على استعلام حي (CourseFile.is_published +
     * created_at) فلا يحتاج أي كتابة إضافية هون — يكفي أن نضبط is_published
     * وcreated_at بشكل صحيح كما تفعل CourseFile::create() افتراضيًا.
     *
     * تصنيف CourseSection: نفس الصف والجدول لكلا الدورين تمامًا — قسم
     * رئيسي (course_unit_id = null) أو تصنيف داخل وحدة (course_unit_id
     * محدد). أي محتوى (CourseFile) دومًا مرتبط بـcourse_section_id (سواء
     * قسم مباشرة أو تصنيف)، وcourse_unit_id يُشتق من نفس القسم/التصنيف.
     * ============================================================
     */
    
    /*
     * نقطة الدخول: بحث عن مادة بدل تصفّح كل المساقات — أسرع للطاقم
     * (طلب صريح من الأدمن).
     */
    private function startAdminContentFlow(TelegramBotApi $bot, TelegramLink $link, int|string $chatId): void
    {
        $link->update(['pending_action' => ['action' => 'admcontent_course_query', 'step' => 'query', 'lecture_id' => null, 'data' => []]]);
        $bot->sendMessage($chatId, "📚 <b>إدارة المحتوى</b>\n\nاكتب اسم المادة أو رمزها للبحث عنها (أو اكتب \"إلغاء\"):");
    }
    
    /*
     * إحصاء المحتوى المضاف بالمادة (أقسام/وحدات/تصنيفات/ملفات) + قائمة
     * الأقسام الرئيسية كأزرار — طلب صريح من الأدمن.
     */
    private function sendAdminContentCourseMenu(TelegramBotApi $bot, int|string $chatId, int $courseId): void
    {
        $course = Course::query()->find($courseId);
    
        if (! $course) {
            $bot->sendMessage($chatId, '⚠️ هذه المادة غير موجودة.');
    
            return;
        }
    
        $topSections = CourseSection::query()
            ->where('course_id', $course->id)
            ->whereNull('course_unit_id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    
        $unitsCount = CourseUnit::query()->where('course_id', $course->id)->count();
        $categoriesCount = CourseSection::query()->where('course_id', $course->id)->whereNotNull('course_unit_id')->count();
        $filesCount = CourseFile::query()->where('course_id', $course->id)->count();
        $publishedFilesCount = CourseFile::query()->where('course_id', $course->id)->where('is_published', true)->count();
    
        $courseName = TelegramBotApi::escapeHtml((string) ($course->name_ar ?? $course->name_en ?? $course->code));
    
        $lines = [
            '📚 <b>'.$courseName.'</b>',
            '',
            '📊 <b>إحصاء المحتوى:</b>',
            '🗂️ أقسام رئيسية: '.$topSections->count(),
            '📦 وحدات: '.$unitsCount,
            '🏷️ تصنيفات: '.$categoriesCount,
            '📄 عناصر محتوى: '.$filesCount.' ('.$publishedFilesCount.' منشور)',
            '',
            $topSections->isEmpty() ? 'لا يوجد أقسام رئيسية بعد.' : '🗂️ <b>الأقسام الرئيسية:</b>',
        ];
    
        $keyboard = [];
    
        foreach ($topSections as $section) {
            $icon = $section->is_published ? '🟢' : '🔴';
            $keyboard[] = [['text' => $icon.' '.$section->title, 'callback_data' => 'admcontent:section:'.$section->id]];
        }
    
        $keyboard[] = [['text' => '➕ إضافة قسم جديد', 'callback_data' => 'admcontent:addsection:'.$course->id]];
        $keyboard[] = [['text' => '🔍 بحث عن مادة أخرى', 'callback_data' => 'admcontent:searchagain']];
    
        $bot->sendMessage($chatId, implode("\n", $lines), $keyboard);
    }
    
    /*
     * تفاصيل قسم رئيسي أو تصنيف (نفس CourseSection، الفرق فقط
     * course_unit_id) — يعرض وحداته الفرعية (إن كان قسمًا رئيسيًا فقط)
     * ومحتواه المباشر، مع أزرار الإضافة/التعديل/النشر/الحذف.
     */
    private function sendAdminContentSectionDetail(TelegramBotApi $bot, int|string $chatId, int $sectionId): void
    {
        $section = CourseSection::query()->with('course')->find($sectionId);
    
        if (! $section) {
            $bot->sendMessage($chatId, '⚠️ هذا القسم/التصنيف غير موجود (يمكن اتحذف).');
    
            return;
        }
    
        $isCategory = $section->course_unit_id !== null;
        $title = TelegramBotApi::escapeHtml((string) $section->title);
        $statusIcon = $section->is_published ? '🟢 منشور' : '🔴 غير منشور';
    
        $lines = [
            ($isCategory ? '🏷️ <b>تصنيف:</b> ' : '🗂️ <b>قسم رئيسي:</b> ').$title,
            $statusIcon.' | إنجاز الطالب: '.($section->counts_toward_progress ? 'نعم' : 'لا'),
        ];
    
        if (! empty($section->description)) {
            $lines[] = '📝 '.TelegramBotApi::escapeHtml((string) $section->description);
        }
    
        $keyboard = [];
    
        if (! $isCategory) {
            $units = CourseUnit::query()->where('course_section_id', $section->id)->orderBy('sort_order')->orderBy('id')->get();
            $lines[] = '';
            $lines[] = $units->isEmpty() ? '📦 لا يوجد وحدات بهذا القسم بعد.' : '📦 <b>الوحدات:</b>';
    
            foreach ($units as $unit) {
                $icon = $unit->is_published ? '🟢' : '🔴';
                $keyboard[] = [['text' => $icon.' '.$unit->title, 'callback_data' => 'admcontent:unit:'.$unit->id]];
            }
    
            $keyboard[] = [['text' => '➕ إضافة وحدة', 'callback_data' => 'admcontent:addunit:'.$section->id]];
        }
    
        $files = CourseFile::query()->where('course_section_id', $section->id)->orderBy('sort_order')->orderBy('id')->get();
        $lines[] = '';
        $lines[] = $files->isEmpty() ? '📄 لا يوجد محتوى هون مباشرة بعد.' : '📄 <b>المحتوى هون مباشرة:</b>';
    
        foreach ($files as $file) {
            $icon = $file->is_published ? '🟢' : '🔴';
            $keyboard[] = [['text' => $icon.' '.$file->title, 'callback_data' => 'admcontent:file:'.$file->id]];
        }
    
        $keyboard[] = [['text' => '➕ إضافة محتوى هون', 'callback_data' => 'admcontent:addfile:'.$section->id]];
        $keyboard[] = [
            ['text' => '✏️ تعديل', 'callback_data' => 'admcontent:editsection:'.$section->id],
            ['text' => $section->is_published ? '🔴 إلغاء النشر' : '🟢 نشر', 'callback_data' => 'admcontent:toggle:section:'.$section->id],
        ];
        $keyboard[] = [['text' => '🗑️ حذف', 'callback_data' => 'admcontent:del:section:'.$section->id]];
        $keyboard[] = $isCategory
            ? [['text' => '🔙 رجوع للوحدة', 'callback_data' => 'admcontent:unit:'.$section->course_unit_id]]
            : [['text' => '🔙 رجوع للمادة', 'callback_data' => 'admcontent:course:'.$section->course_id]];
    
        $this->sendChunkedMessage($bot, $chatId, $lines, $keyboard);
    }
    
    /*
     * تفاصيل وحدة — تعرض تصنيفاتها كأزرار.
     */
    private function sendAdminContentUnitDetail(TelegramBotApi $bot, int|string $chatId, int $unitId): void
    {
        $unit = CourseUnit::query()->find($unitId);
    
        if (! $unit) {
            $bot->sendMessage($chatId, '⚠️ هذه الوحدة غير موجودة (يمكن اتحذفت).');
    
            return;
        }
    
        $title = TelegramBotApi::escapeHtml((string) $unit->title);
        $statusIcon = $unit->is_published ? '🟢 منشورة' : '🔴 غير منشورة';
    
        $lines = ['📦 <b>وحدة:</b> '.$title, $statusIcon];
    
        if (! empty($unit->description)) {
            $lines[] = '📝 '.TelegramBotApi::escapeHtml((string) $unit->description);
        }
    
        $categories = CourseSection::query()->where('course_unit_id', $unit->id)->orderBy('sort_order')->orderBy('id')->get();
        $lines[] = '';
        $lines[] = $categories->isEmpty() ? '🏷️ لا يوجد تصنيفات بهذه الوحدة بعد.' : '🏷️ <b>التصنيفات:</b>';
    
        $keyboard = [];
    
        foreach ($categories as $category) {
            $icon = $category->is_published ? '🟢' : '🔴';
            $keyboard[] = [['text' => $icon.' '.$category->title, 'callback_data' => 'admcontent:section:'.$category->id]];
        }
    
        $keyboard[] = [['text' => '➕ إضافة تصنيف', 'callback_data' => 'admcontent:addcategory:'.$unit->id]];
        $keyboard[] = [
            ['text' => '✏️ تعديل', 'callback_data' => 'admcontent:editunit:'.$unit->id],
            ['text' => $unit->is_published ? '🔴 إلغاء النشر' : '🟢 نشر', 'callback_data' => 'admcontent:toggle:unit:'.$unit->id],
        ];
        $keyboard[] = [['text' => '🗑️ حذف', 'callback_data' => 'admcontent:del:unit:'.$unit->id]];
        $keyboard[] = [['text' => '🔙 رجوع للقسم', 'callback_data' => 'admcontent:section:'.$unit->course_section_id]];
    
        $this->sendChunkedMessage($bot, $chatId, $lines, $keyboard);
    }
    
    /*
     * تفاصيل ملف محتوى — كل الحقول المطلوبة صراحةً بطلب الطاقم: العنوان،
     * النوع، الوصف، الرابط، والخيارات الثلاثة (منشور/إنجاز/تلخيص AI).
     */
    private function sendAdminContentFileDetail(TelegramBotApi $bot, int|string $chatId, int $fileId): void
    {
        $file = CourseFile::query()->find($fileId);
    
        if (! $file) {
            $bot->sendMessage($chatId, '⚠️ هذا المحتوى غير موجود (يمكن اتحذف).');
    
            return;
        }
    
        $kindLabel = self::ADMIN_CONTENT_KIND_LABELS[$file->kind] ?? $file->kind;
        $visibilityLabel = self::ADMIN_CONTENT_VISIBILITY_LABELS[$file->visibility] ?? $file->visibility;
    
        $lines = [
            '📄 <b>'.TelegramBotApi::escapeHtml((string) $file->title).'</b>',
            '🏷️ النوع: '.$kindLabel,
        ];
    
        if (! empty($file->description)) {
            $lines[] = '📝 '.TelegramBotApi::escapeHtml((string) $file->description);
        }
    
        $lines[] = '🔗 '.$file->external_url;
        $lines[] = '';
        $lines[] = ($file->is_published ? '🟢 منشور للطلاب' : '🔴 غير منشور');
        $lines[] = ($file->counts_toward_progress ? '✅ يُحتسب ضمن إنجاز الطالب' : '➖ لا يُحتسب ضمن الإنجاز');
        $lines[] = ($file->ai_summarizable ? '🤖 يسمح بتلخيصه/تحويله لبطاقات مراجعة' : '🚫 لا يسمح بالتلخيص/البطاقات');
        $lines[] = '👁️ الظهور: '.$visibilityLabel;
    
        $keyboard = [
            [
                ['text' => '✏️ تعديل', 'callback_data' => 'admcontent:editfile:'.$file->id],
                ['text' => $file->is_published ? '🔴 إلغاء النشر' : '🟢 نشر', 'callback_data' => 'admcontent:toggle:file:'.$file->id],
            ],
            [['text' => '🗑️ حذف', 'callback_data' => 'admcontent:del:file:'.$file->id]],
            [['text' => '🔙 رجوع للقسم', 'callback_data' => 'admcontent:section:'.$file->course_section_id]],
        ];
    
        $bot->sendMessage($chatId, implode("\n", $lines), $keyboard);
    }
    
    /*
     * اشتقاق افتراضي لـai_summarizable وقت الإنشاء فقط — نفس منطق
     * Staff/CourseFileController::defaultAiSummarizable() بالضبط، مكرَّر
     * هون عمدًا (دالة خاصة بكنترولر آخر) بدل تغيير رؤية الأصل.
     */
    private function defaultAiSummarizable(?string $kind): bool
    {
        return ! in_array($kind, ['vid', 'youtube', 'link', 'github', 'software', 'image'], true);
    }
    
    private function isValidHttpsUrl(string $value): bool
    {
        return (bool) preg_match('#^https://#i', $value) && filter_var($value, FILTER_VALIDATE_URL) !== false;
    }
    
    /*
     * توجيه "رجوع" عام بعد إتمام/إلغاء أي معالج (إضافة قسم/وحدة/ملف) —
     * $returnTo بصيغة ['type' => 'course'|'section'|'unit', 'id' => ...].
     */
    private function renderAdminContentReturn(TelegramBotApi $bot, int|string $chatId, array $returnTo): void
    {
        $type = $returnTo['type'] ?? null;
        $id = (int) ($returnTo['id'] ?? 0);
    
        if ($type === 'course') {
            $this->sendAdminContentCourseMenu($bot, $chatId, $id);
        } elseif ($type === 'unit') {
            $this->sendAdminContentUnitDetail($bot, $chatId, $id);
        } elseif ($type === 'section') {
            $this->sendAdminContentSectionDetail($bot, $chatId, $id);
        }
    }
    
    /*
     * أزرار تعديل حقول قسم/تصنيف/وحدة/ملف — نفس فلسفة
     * sendAdminToolFieldPicker: حقول نصية تُفتح كخطوة كتابة، وحقول
     * منطقية/تعداد تُعرض كأزرار مباشرة.
     */
    private function sendAdminContentEditFieldPicker(TelegramBotApi $bot, int|string $chatId, string $type, int $id): void
    {
        $labels = match ($type) {
            'section' => ['title' => 'العنوان', 'description' => 'الوصف', 'counts_toward_progress' => 'إنجاز الطالب', 'is_published' => 'النشر'],
            'unit' => ['title' => 'العنوان', 'description' => 'الوصف', 'is_published' => 'النشر'],
            'file' => [
                'title' => 'العنوان', 'kind' => 'النوع', 'description' => 'الوصف', 'external_url' => 'الرابط',
                'is_published' => 'منشور للطلاب', 'counts_toward_progress' => 'إنجاز الطالب',
                'ai_summarizable' => 'تلخيص/بطاقات AI', 'visibility' => 'الظهور',
            ],
            default => [],
        };
    
        $keyboard = [];
    
        foreach ($labels as $field => $label) {
            $keyboard[] = [['text' => $label, 'callback_data' => 'admcontent:editfield:'.$type.':'.$id.':'.$field]];
        }
    
        $backCallback = match ($type) {
            'section' => 'admcontent:section:'.$id,
            'unit' => 'admcontent:unit:'.$id,
            'file' => 'admcontent:file:'.$id,
            default => 'admcontent:searchagain',
        };
    
        $keyboard[] = [['text' => '🔙 رجوع', 'callback_data' => $backCallback]];
    
        $bot->sendMessage($chatId, 'اختر الحقل يلي بدك تعدّله:', $keyboard);
    }
    
    /*
     * معالج كل أزرار "admcontent:" — تصفّح (course/section/unit/file)،
     * الإضافة (addsection/addcategory/addunit/addfile وأزرار المعالج
     * التدريجي wizbtn)، التعديل (editfield/setval)، والنشر/الحذف
     * (toggle/del/delyes/delno).
     */
    private function handleAdminContentCallback(TelegramBotApi $bot, array $callbackQuery): void
    {
        $callbackId = (string) ($callbackQuery['id'] ?? '');
        $chatId = $callbackQuery['message']['chat']['id'] ?? null;
        $data = (string) ($callbackQuery['data'] ?? '');
        $action = substr($data, strlen('admcontent:'));
        $parts = explode(':', $action);
        $key = $parts[0] ?? '';
    
        if (! $chatId) {
            $bot->answerCallbackQuery($callbackId);
    
            return;
        }
    
        $link = TelegramLink::query()->whereNotNull('telegram_chat_id')->where('telegram_chat_id', $chatId)->first();
    
        if (! $link || ! $link->user || ! $link->user->isStaff()) {
            $bot->answerCallbackQuery($callbackId, 'غير مخوّل.');
    
            return;
        }
    
        $bot->answerCallbackQuery($callbackId);
    
        if ($key === 'searchagain') {
            $this->startAdminContentFlow($bot, $link, $chatId);
    
            return;
        }
    
        if ($key === 'course') {
            $link->update(['pending_action' => null]);
            $this->sendAdminContentCourseMenu($bot, $chatId, (int) ($parts[1] ?? 0));
    
            return;
        }
    
        if ($key === 'section') {
            $link->update(['pending_action' => null]);
            $this->sendAdminContentSectionDetail($bot, $chatId, (int) ($parts[1] ?? 0));
    
            return;
        }
    
        if ($key === 'unit') {
            $link->update(['pending_action' => null]);
            $this->sendAdminContentUnitDetail($bot, $chatId, (int) ($parts[1] ?? 0));
    
            return;
        }
    
        if ($key === 'file') {
            $link->update(['pending_action' => null]);
            $this->sendAdminContentFileDetail($bot, $chatId, (int) ($parts[1] ?? 0));
    
            return;
        }
    
        if ($key === 'addsection') {
            $courseId = (int) ($parts[1] ?? 0);
            $link->update(['pending_action' => [
                'action' => 'admcontent_addsection', 'step' => 'title', 'lecture_id' => null,
                'data' => ['course_id' => $courseId, 'course_unit_id' => null, 'return_to' => ['type' => 'course', 'id' => $courseId]],
            ]]);
            $bot->sendMessage($chatId, '🗂️ اكتب عنوان القسم الجديد:');
    
            return;
        }
    
        if ($key === 'addcategory') {
            $unitId = (int) ($parts[1] ?? 0);
            $unit = CourseUnit::query()->find($unitId);
    
            if (! $unit) {
                $bot->sendMessage($chatId, '⚠️ هذه الوحدة غير موجودة.');
    
                return;
            }
    
            $link->update(['pending_action' => [
                'action' => 'admcontent_addsection', 'step' => 'title', 'lecture_id' => null,
                'data' => ['course_id' => $unit->course_id, 'course_unit_id' => $unit->id, 'return_to' => ['type' => 'unit', 'id' => $unit->id]],
            ]]);
            $bot->sendMessage($chatId, '🏷️ اكتب عنوان التصنيف الجديد:');
    
            return;
        }
    
        if ($key === 'addunit') {
            $sectionId = (int) ($parts[1] ?? 0);
            $section = CourseSection::query()->find($sectionId);
    
            if (! $section || $section->course_unit_id !== null) {
                $bot->sendMessage($chatId, '⚠️ لا يمكن إضافة وحدة إلا داخل قسم رئيسي.');
    
                return;
            }
    
            $link->update(['pending_action' => [
                'action' => 'admcontent_addunit', 'step' => 'title', 'lecture_id' => null,
                'data' => ['course_id' => $section->course_id, 'course_section_id' => $section->id, 'return_to' => ['type' => 'section', 'id' => $section->id]],
            ]]);
            $bot->sendMessage($chatId, '📦 اكتب عنوان الوحدة الجديدة:');
    
            return;
        }
    
        if ($key === 'addfile') {
            $sectionId = (int) ($parts[1] ?? 0);
            $section = CourseSection::query()->find($sectionId);
    
            if (! $section) {
                $bot->sendMessage($chatId, '⚠️ هذا القسم/التصنيف غير موجود.');
    
                return;
            }
    
            $link->update(['pending_action' => [
                'action' => 'admcontent_addfile', 'step' => 'title', 'lecture_id' => null,
                'data' => [
                    'course_id' => $section->course_id,
                    'course_section_id' => $section->id,
                    'course_unit_id' => $section->course_unit_id,
                    'return_to' => ['type' => 'section', 'id' => $section->id],
                ],
            ]]);
            $bot->sendMessage($chatId, '📄 اكتب عنوان المحتوى الجديد:');
    
            return;
        }
    
        if ($key === 'editsection') {
            $this->sendAdminContentEditFieldPicker($bot, $chatId, 'section', (int) ($parts[1] ?? 0));
    
            return;
        }
    
        if ($key === 'editunit') {
            $this->sendAdminContentEditFieldPicker($bot, $chatId, 'unit', (int) ($parts[1] ?? 0));
    
            return;
        }
    
        if ($key === 'editfile') {
            $this->sendAdminContentEditFieldPicker($bot, $chatId, 'file', (int) ($parts[1] ?? 0));
    
            return;
        }
    
        if ($key === 'editfield') {
            $type = $parts[1] ?? '';
            $id = (int) ($parts[2] ?? 0);
            $field = $parts[3] ?? '';
    
            $textFields = ['title', 'description', 'external_url'];
            $boolFields = ['is_published', 'counts_toward_progress', 'ai_summarizable'];
    
            if (in_array($field, $textFields, true)) {
                $link->update(['pending_action' => [
                    'action' => 'admcontent_edit_'.$type, 'step' => $field, 'lecture_id' => null,
                    'data' => ['id' => $id, 'field' => $field],
                ]]);
    
                $prompts = [
                    'title' => '✏️ اكتب العنوان الجديد:',
                    'description' => '📝 اكتب الوصف الجديد (أو ارسل "-" لمسحه):',
                    'external_url' => '🔗 اكتب الرابط الجديد (لازم يبدأ بـ https://):',
                ];
                $bot->sendMessage($chatId, $prompts[$field] ?? 'اكتب القيمة الجديدة:');
    
                return;
            }
    
            if (in_array($field, $boolFields, true)) {
                $keyboard = [[
                    ['text' => '✅ نعم', 'callback_data' => 'admcontent:setval:'.$type.':'.$id.':'.$field.':1'],
                    ['text' => '❌ لا', 'callback_data' => 'admcontent:setval:'.$type.':'.$id.':'.$field.':0'],
                ]];
                $bot->sendMessage($chatId, 'اختر القيمة:', $keyboard);
    
                return;
            }
    
            if ($field === 'kind') {
                $keyboard = [];
                $row = [];
                foreach (self::ADMIN_CONTENT_KIND_LABELS as $kindKey => $label) {
                    $row[] = ['text' => $label, 'callback_data' => 'admcontent:setval:file:'.$id.':kind:'.$kindKey];
                    if (count($row) === 2) {
                        $keyboard[] = $row;
                        $row = [];
                    }
                }
                if ($row !== []) {
                    $keyboard[] = $row;
                }
                $bot->sendMessage($chatId, 'اختر النوع الجديد:', $keyboard);
    
                return;
            }
    
            if ($field === 'visibility') {
                $keyboard = [];
                foreach (self::ADMIN_CONTENT_VISIBILITY_LABELS as $visKey => $label) {
                    $keyboard[] = [['text' => $label, 'callback_data' => 'admcontent:setval:file:'.$id.':visibility:'.$visKey]];
                }
                $bot->sendMessage($chatId, 'اختر مستوى الظهور الجديد:', $keyboard);
    
                return;
            }
    
            return;
        }
    
        if ($key === 'setval') {
            $type = $parts[1] ?? '';
            $id = (int) ($parts[2] ?? 0);
            $field = $parts[3] ?? '';
            $rawValue = $parts[4] ?? null;
    
            $model = match ($type) {
                'section' => CourseSection::query()->find($id),
                'unit' => CourseUnit::query()->find($id),
                'file' => CourseFile::query()->find($id),
                default => null,
            };
    
            if (! $model || $rawValue === null) {
                $bot->sendMessage($chatId, '⚠️ تعذّر التحديث.');
    
                return;
            }
    
            $boolFields = ['is_published', 'counts_toward_progress', 'ai_summarizable'];
            $value = in_array($field, $boolFields, true) ? ($rawValue === '1') : $rawValue;
    
            $model->update([$field => $value]);
            $bot->sendMessage($chatId, '✅ تم الحفظ.');
    
            if ($type === 'section') {
                $this->sendAdminContentSectionDetail($bot, $chatId, $id);
            } elseif ($type === 'unit') {
                $this->sendAdminContentUnitDetail($bot, $chatId, $id);
            } else {
                $this->sendAdminContentFileDetail($bot, $chatId, $id);
            }
    
            return;
        }
    
        if ($key === 'toggle') {
            $type = $parts[1] ?? '';
            $id = (int) ($parts[2] ?? 0);
    
            $model = match ($type) {
                'section' => CourseSection::query()->find($id),
                'unit' => CourseUnit::query()->find($id),
                'file' => CourseFile::query()->find($id),
                default => null,
            };
    
            if (! $model) {
                $bot->sendMessage($chatId, '⚠️ تعذّر إيجاد العنصر.');
    
                return;
            }
    
            $model->update(['is_published' => ! $model->is_published]);
    
            if ($type === 'section') {
                $this->sendAdminContentSectionDetail($bot, $chatId, $id);
            } elseif ($type === 'unit') {
                $this->sendAdminContentUnitDetail($bot, $chatId, $id);
            } else {
                $this->sendAdminContentFileDetail($bot, $chatId, $id);
            }
    
            return;
        }
    
        if ($key === 'del') {
            $type = $parts[1] ?? '';
            $id = (int) ($parts[2] ?? 0);
    
            $labels = ['section' => 'هذا القسم/التصنيف ووحداته وتصنيفاته ومحتواه', 'unit' => 'هذه الوحدة وتصنيفاتها ومحتواها', 'file' => 'هذا المحتوى'];
            $keyboard = [[
                ['text' => '✅ نعم، احذف', 'callback_data' => 'admcontent:delyes:'.$type.':'.$id],
                ['text' => '❌ لا، رجوع', 'callback_data' => 'admcontent:delno:'.$type.':'.$id],
            ]];
            $bot->sendMessage($chatId, '⚠️ متأكد إنك بدك تحذف '.($labels[$type] ?? 'هذا العنصر').'؟ العملية لا يمكن التراجع عنها.', $keyboard);
    
            return;
        }
    
        if ($key === 'delno') {
            $type = $parts[1] ?? '';
            $id = (int) ($parts[2] ?? 0);
    
            if ($type === 'section') {
                $this->sendAdminContentSectionDetail($bot, $chatId, $id);
            } elseif ($type === 'unit') {
                $this->sendAdminContentUnitDetail($bot, $chatId, $id);
            } elseif ($type === 'file') {
                $this->sendAdminContentFileDetail($bot, $chatId, $id);
            }
    
            return;
        }
    
        if ($key === 'delyes') {
            $type = $parts[1] ?? '';
            $id = (int) ($parts[2] ?? 0);
    
            if ($type === 'file') {
                $file = CourseFile::query()->find($id);
    
                if ($file) {
                    $returnTo = ['type' => 'section', 'id' => $file->course_section_id];
                    $file->delete();
                    $bot->sendMessage($chatId, '🗑️ تم حذف المحتوى.');
                    $this->renderAdminContentReturn($bot, $chatId, $returnTo);
                }
    
                return;
            }
    
            if ($type === 'unit') {
                $unit = CourseUnit::query()->find($id);
    
                if ($unit) {
                    $returnTo = ['type' => 'section', 'id' => $unit->course_section_id];
                    \Illuminate\Support\Facades\DB::transaction(function () use ($unit) {
                        $categoryIds = CourseSection::query()->where('course_unit_id', $unit->id)->pluck('id');
    
                        if ($categoryIds->isNotEmpty()) {
                            CourseFile::query()->whereIn('course_section_id', $categoryIds)->delete();
                            CourseSection::query()->whereIn('id', $categoryIds)->delete();
                        }
    
                        CourseFile::query()->where('course_unit_id', $unit->id)->delete();
                        $unit->delete();
                    });
                    $bot->sendMessage($chatId, '🗑️ تم حذف الوحدة وتصنيفاتها ومحتواها.');
                    $this->renderAdminContentReturn($bot, $chatId, $returnTo);
                }
    
                return;
            }
    
            if ($type === 'section') {
                $section = CourseSection::query()->find($id);
    
                if ($section) {
                    $returnTo = $section->course_unit_id !== null
                        ? ['type' => 'unit', 'id' => $section->course_unit_id]
                        : ['type' => 'course', 'id' => $section->course_id];
    
                    \Illuminate\Support\Facades\DB::transaction(function () use ($section) {
                        if ($section->course_unit_id) {
                            CourseFile::query()->where('course_section_id', $section->id)->delete();
                            $section->delete();
    
                            return;
                        }
    
                        $unitIds = CourseUnit::query()->where('course_id', $section->course_id)->where('course_section_id', $section->id)->pluck('id');
    
                        if ($unitIds->isNotEmpty()) {
                            $categoryIds = CourseSection::query()->whereIn('course_unit_id', $unitIds)->pluck('id');
    
                            if ($categoryIds->isNotEmpty()) {
                                CourseFile::query()->whereIn('course_section_id', $categoryIds)->delete();
                                CourseSection::query()->whereIn('id', $categoryIds)->delete();
                            }
    
                            CourseFile::query()->whereIn('course_unit_id', $unitIds)->delete();
                            CourseUnit::query()->whereIn('id', $unitIds)->delete();
                        }
    
                        CourseFile::query()->where('course_section_id', $section->id)->delete();
                        $section->delete();
                    });
    
                    $bot->sendMessage($chatId, '🗑️ تم حذف القسم ووحداته وتصنيفاته ومحتواه.');
                    $this->renderAdminContentReturn($bot, $chatId, $returnTo);
                }
    
                return;
            }
    
            return;
        }
    
        // أزرار المعالج التدريجي (wizbtn) — تُتابَع أثناء إضافة قسم/وحدة/ملف.
        if ($key === 'wizbtn') {
            $this->handleAdminContentWizardButton($bot, $link, $chatId, $parts);
    
            return;
        }
    
        if ($key === 'wizcreate') {
            $this->handleAdminContentWizardCreate($bot, $link, $chatId);
    
            return;
        }
    
        if ($key === 'wizcancel') {
            $returnTo = $link->pending_action['data']['return_to'] ?? null;
            $link->update(['pending_action' => null]);
            $bot->sendMessage($chatId, 'تم الإلغاء.');
    
            if ($returnTo) {
                $this->renderAdminContentReturn($bot, $chatId, $returnTo);
            }
    
            return;
        }
    }
    
    /*
     * زر أثناء معالج تدريجي — بصيغة admcontent:wizbtn:{field}:{value}.
     * يقرأ الخطوة الحالية من pending_action ويتحقق أنها تطابق الحقل
     * المضغوط قبل أي تعديل (حماية من ضغط زر قديم بعد تغيّر الخطوة).
     */
    private function handleAdminContentWizardButton(TelegramBotApi $bot, TelegramLink $link, int|string $chatId, array $parts): void
    {
        $field = $parts[1] ?? '';
        $value = $parts[2] ?? '';
        $pending = $link->pending_action;
        $action = $pending['action'] ?? null;
        $step = $pending['step'] ?? null;
        $data = $pending['data'] ?? [];
    
        if (! $action || $step !== $field) {
            return;
        }
    
        if ($action === 'admcontent_addsection') {
            if ($field === 'counts_toward_progress') {
                $data['counts_toward_progress'] = $value === '1';
                $link->update(['pending_action' => ['action' => $action, 'step' => 'published', 'lecture_id' => null, 'data' => $data]]);
                $bot->sendMessage($chatId, 'هل ينشر مباشرة للطلاب؟', [[
                    ['text' => '🟢 نعم', 'callback_data' => 'admcontent:wizbtn:published:1'],
                    ['text' => '🔴 لا (مسودة)', 'callback_data' => 'admcontent:wizbtn:published:0'],
                ]]);
    
                return;
            }
    
            if ($field === 'published') {
                $data['is_published'] = $value === '1';
                $link->update(['pending_action' => ['action' => $action, 'step' => 'confirm', 'lecture_id' => null, 'data' => $data]]);
                $this->sendAdminContentSectionConfirm($bot, $chatId, $data);
    
                return;
            }
        }
    
        if ($action === 'admcontent_addunit') {
            if ($field === 'defaultcats') {
                $data['create_default_categories'] = $value === '1';
                $link->update(['pending_action' => ['action' => $action, 'step' => 'published', 'lecture_id' => null, 'data' => $data]]);
                $bot->sendMessage($chatId, 'هل تُنشر الوحدة مباشرة للطلاب؟', [[
                    ['text' => '🟢 نعم', 'callback_data' => 'admcontent:wizbtn:published:1'],
                    ['text' => '🔴 لا (مسودة)', 'callback_data' => 'admcontent:wizbtn:published:0'],
                ]]);
    
                return;
            }
    
            if ($field === 'published') {
                $data['is_published'] = $value === '1';
                $link->update(['pending_action' => ['action' => $action, 'step' => 'confirm', 'lecture_id' => null, 'data' => $data]]);
                $this->sendAdminContentUnitConfirm($bot, $chatId, $data);
    
                return;
            }
        }
    
        if ($action === 'admcontent_addfile') {
            if ($field === 'kind') {
                $data['kind'] = $value;
                $link->update(['pending_action' => ['action' => $action, 'step' => 'url', 'lecture_id' => null, 'data' => $data]]);
                $bot->sendMessage($chatId, '🔗 اكتب رابط المحتوى (لازم يبدأ بـ https://):');
    
                return;
            }
    
            if ($field === 'published') {
                $data['is_published'] = $value === '1';
                $link->update(['pending_action' => ['action' => $action, 'step' => 'counts', 'lecture_id' => null, 'data' => $data]]);
                $bot->sendMessage($chatId, 'هل يُحتسب ضمن إنجاز الطالب؟', [[
                    ['text' => '✅ نعم', 'callback_data' => 'admcontent:wizbtn:counts:1'],
                    ['text' => '➖ لا', 'callback_data' => 'admcontent:wizbtn:counts:0'],
                ]]);
    
                return;
            }
    
            if ($field === 'counts') {
                $data['counts_toward_progress'] = $value === '1';
                $link->update(['pending_action' => ['action' => $action, 'step' => 'visibility', 'lecture_id' => null, 'data' => $data]]);
                $keyboard = [];
                foreach (self::ADMIN_CONTENT_VISIBILITY_LABELS as $visKey => $label) {
                    $keyboard[] = [['text' => $label, 'callback_data' => 'admcontent:wizbtn:visibility:'.$visKey]];
                }
                $bot->sendMessage($chatId, '👁️ اختر مستوى الظهور:', $keyboard);
    
                return;
            }
    
            if ($field === 'visibility') {
                $data['visibility'] = $value;
                $default = $this->defaultAiSummarizable($data['kind'] ?? null);
                $link->update(['pending_action' => ['action' => $action, 'step' => 'aisum', 'lecture_id' => null, 'data' => $data]]);
                $bot->sendMessage(
                    $chatId,
                    '🤖 هل يسمح بتلخيص هذا الملف وتحويله لبطاقات مراجعة بالمساعد الذكي؟ (الافتراض حسب النوع: '.($default ? 'نعم' : 'لا').')',
                    [[
                        ['text' => '✅ نعم', 'callback_data' => 'admcontent:wizbtn:aisum:1'],
                        ['text' => '🚫 لا', 'callback_data' => 'admcontent:wizbtn:aisum:0'],
                    ]]
                );
    
                return;
            }
    
            if ($field === 'aisum') {
                $data['ai_summarizable'] = $value === '1';
                $link->update(['pending_action' => ['action' => $action, 'step' => 'confirm', 'lecture_id' => null, 'data' => $data]]);
                $this->sendAdminContentFileConfirm($bot, $chatId, $data);
    
                return;
            }
        }
    }
    
    private function sendAdminContentSectionConfirm(TelegramBotApi $bot, int|string $chatId, array $data): void
    {
        $preview = "🗂️ <b>معاينة</b>\n\n".
            '<b>'.TelegramBotApi::escapeHtml((string) ($data['title'] ?? '')).'</b>'.
            (! empty($data['description']) ? "\n📝 ".TelegramBotApi::escapeHtml((string) $data['description']) : '').
            "\n".($data['counts_toward_progress'] ?? false ? '✅ يُحتسب ضمن الإنجاز' : '➖ لا يُحتسب ضمن الإنجاز').
            "\n".($data['is_published'] ?? true ? '🟢 سينشر مباشرة' : '🔴 مسودة (غير منشور)');
    
        $bot->sendMessage($chatId, $preview, [[
            ['text' => '✅ إضافة', 'callback_data' => 'admcontent:wizcreate'],
            ['text' => '❌ إلغاء', 'callback_data' => 'admcontent:wizcancel'],
        ]]);
    }
    
    private function sendAdminContentUnitConfirm(TelegramBotApi $bot, int|string $chatId, array $data): void
    {
        $preview = "📦 <b>معاينة</b>\n\n".
            '<b>'.TelegramBotApi::escapeHtml((string) ($data['title'] ?? '')).'</b>'.
            (! empty($data['description']) ? "\n📝 ".TelegramBotApi::escapeHtml((string) $data['description']) : '').
            "\n".($data['create_default_categories'] ?? false ? '🏷️ ستُنشأ التصنيفات الأساسية الأربعة تلقائيًا' : '🏷️ بلا تصنيفات افتراضية').
            "\n".($data['is_published'] ?? true ? '🟢 ستنشر مباشرة' : '🔴 مسودة (غير منشورة)');
    
        $bot->sendMessage($chatId, $preview, [[
            ['text' => '✅ إضافة', 'callback_data' => 'admcontent:wizcreate'],
            ['text' => '❌ إلغاء', 'callback_data' => 'admcontent:wizcancel'],
        ]]);
    }
    
    private function sendAdminContentFileConfirm(TelegramBotApi $bot, int|string $chatId, array $data): void
    {
        $kindLabel = self::ADMIN_CONTENT_KIND_LABELS[$data['kind'] ?? ''] ?? ($data['kind'] ?? '');
        $visibilityLabel = self::ADMIN_CONTENT_VISIBILITY_LABELS[$data['visibility'] ?? ''] ?? ($data['visibility'] ?? '');
    
        $preview = "📄 <b>معاينة المحتوى الجديد</b>\n\n".
            '<b>'.TelegramBotApi::escapeHtml((string) ($data['title'] ?? '')).'</b>'."\n".
            '🏷️ '.$kindLabel.
            (! empty($data['description']) ? "\n📝 ".TelegramBotApi::escapeHtml((string) $data['description']) : '').
            "\n🔗 ".($data['external_url'] ?? '').
            "\n".($data['is_published'] ?? true ? '🟢 سينشر مباشرة للطلاب' : '🔴 مسودة (غير منشور)').
            "\n".($data['counts_toward_progress'] ?? false ? '✅ يُحتسب ضمن الإنجاز' : '➖ لا يُحتسب ضمن الإنجاز').
            "\n".($data['ai_summarizable'] ?? true ? '🤖 يسمح بالتلخيص/البطاقات' : '🚫 لا يسمح بالتلخيص/البطاقات').
            "\n👁️ ".$visibilityLabel;
    
        $bot->sendMessage($chatId, $preview, [[
            ['text' => '✅ إضافة المحتوى', 'callback_data' => 'admcontent:wizcreate'],
            ['text' => '❌ إلغاء', 'callback_data' => 'admcontent:wizcancel'],
        ]]);
    }
    
    /*
     * تنفيذ الإنشاء الفعلي بعد "✅ إضافة" — نفس الحقول/الترتيب الافتراضي
     * المستخدَم بالضبط بـStaff/CourseStructureController وStaff/CourseFileController
     * (sort_order = آخر قيمة + ١ ضمن نفس النطاق).
     */
    private function handleAdminContentWizardCreate(TelegramBotApi $bot, TelegramLink $link, int|string $chatId): void
    {
        $pending = $link->pending_action;
        $action = $pending['action'] ?? null;
        $data = $pending['data'] ?? [];
    
        if ($action === 'admcontent_addsection') {
            $sortOrder = ((int) CourseSection::query()
                ->where('course_id', $data['course_id'])
                ->where('course_unit_id', $data['course_unit_id'])
                ->max('sort_order')) + 1;
    
            $section = new CourseSection();
            $section->forceFill([
                'course_id' => $data['course_id'],
                'course_unit_id' => $data['course_unit_id'],
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'sort_order' => $sortOrder,
                'counts_toward_progress' => $data['counts_toward_progress'] ?? false,
                'is_published' => $data['is_published'] ?? true,
            ]);
            $section->save();
    
            $link->update(['pending_action' => null]);
            $bot->sendMessage($chatId, ($data['course_unit_id'] ? '✅ تمت إضافة التصنيف.' : '✅ تمت إضافة القسم.'));
            $this->sendAdminContentSectionDetail($bot, $chatId, $section->id);
    
            return;
        }
    
        if ($action === 'admcontent_addunit') {
            $sortOrder = ((int) CourseUnit::query()
                ->where('course_id', $data['course_id'])
                ->where('course_section_id', $data['course_section_id'])
                ->max('sort_order')) + 1;
    
            $unit = \Illuminate\Support\Facades\DB::transaction(function () use ($data, $sortOrder) {
                $unit = new CourseUnit();
                $unit->forceFill([
                    'course_id' => $data['course_id'],
                    'course_section_id' => $data['course_section_id'],
                    'title' => $data['title'],
                    'description' => $data['description'] ?? null,
                    'sort_order' => $sortOrder,
                    'is_published' => $data['is_published'] ?? true,
                ]);
                $unit->save();
    
                if ($data['create_default_categories'] ?? false) {
                    $defaults = [
                        ['title' => 'المحاضرات وملفاتها', 'sort_order' => 1, 'counts_toward_progress' => true],
                        ['title' => 'التعيينات والواجبات', 'sort_order' => 2, 'counts_toward_progress' => true],
                        ['title' => 'التدريبات والأسئلة', 'sort_order' => 3, 'counts_toward_progress' => true],
                        ['title' => 'روابط مهمة', 'sort_order' => 4, 'counts_toward_progress' => false],
                    ];
    
                    foreach ($defaults as $categoryData) {
                        $category = new CourseSection();
                        $category->forceFill([
                            'course_id' => $data['course_id'],
                            'course_unit_id' => $unit->id,
                            'title' => $categoryData['title'],
                            'sort_order' => $categoryData['sort_order'],
                            'counts_toward_progress' => $categoryData['counts_toward_progress'],
                            'is_published' => true,
                        ]);
                        $category->save();
                    }
                }
    
                return $unit;
            });
    
            $link->update(['pending_action' => null]);
            $bot->sendMessage($chatId, '✅ تمت إضافة الوحدة.');
            $this->sendAdminContentUnitDetail($bot, $chatId, $unit->id);
    
            return;
        }
    
        if ($action === 'admcontent_addfile') {
            $sortOrder = ((int) CourseFile::query()
                ->where('course_id', $data['course_id'])
                ->where('course_section_id', $data['course_section_id'])
                ->where('course_unit_id', $data['course_unit_id'])
                ->max('sort_order')) + 1;
    
            $file = CourseFile::create([
                'course_id' => $data['course_id'],
                'course_section_id' => $data['course_section_id'],
                'course_unit_id' => $data['course_unit_id'],
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'kind' => $data['kind'],
                'external_url' => $data['external_url'],
                'sort_order' => $sortOrder,
                'counts_toward_progress' => $data['counts_toward_progress'] ?? false,
                'is_published' => $data['is_published'] ?? true,
                'visibility' => $data['visibility'] ?? 'public',
                'ai_summarizable' => $data['ai_summarizable'] ?? $this->defaultAiSummarizable($data['kind'] ?? null),
                'status' => 'ready',
                'created_by' => $link->user_id,
            ]);
    
            /*
             * نفس تنبيه Staff/CourseFileController@store بالضبط — معزول
             * بـtry/catch حتى لو تعطّل البوت ما يمنع حفظ المحتوى نفسه.
             * تنبيه جرس الموقع مبني على استعلام حي (is_published + created_at)
             * فيكفي أنه انضبط هون بلا أي كتابة إضافية.
             */
            try {
                app(TelegramContentNotifier::class)->notifyNewFile($file);
            } catch (\Throwable $error) {
                report($error);
            }
    
            $returnTo = $data['return_to'] ?? ['type' => 'section', 'id' => $data['course_section_id']];
            $link->update(['pending_action' => null]);
            $bot->sendMessage($chatId, '✅ تم إضافة المحتوى'.($file->is_published ? ' ونُشر مباشرة للطلاب.' : ' كمسودة (غير منشور).'), [[
                ['text' => '➕ أضف محتوى آخر هون', 'callback_data' => 'admcontent:addfile:'.$data['course_section_id']],
                ['text' => '🔙 رجوع للقسم', 'callback_data' => 'admcontent:section:'.$data['course_section_id']],
            ]]);
    
            return;
        }
    }
    
    /*
     * إدخال نصي أثناء أي معالج "admcontent_*" — بحث عن مادة، إنشاء
     * قسم/وحدة/ملف تدريجيًا، أو تعديل حقل نصي بملف/قسم/وحدة موجود.
     */
    private function handleAdminContentTextInput(TelegramBotApi $bot, TelegramLink $link, int|string $chatId, string $text): void
    {
        $normalized = trim($text);
    
        if (in_array($normalized, ['إلغاء', 'الغاء', 'cancel'], true)) {
            $returnTo = $link->pending_action['data']['return_to'] ?? null;
            $link->update(['pending_action' => null]);
            $bot->sendMessage($chatId, 'تم الإلغاء.');
    
            if ($returnTo) {
                $this->renderAdminContentReturn($bot, $chatId, $returnTo);
            }
    
            return;
        }
    
        if (! $link->user || ! $link->user->isStaff()) {
            $link->update(['pending_action' => null]);
            $bot->sendMessage($chatId, '⛔ هذا الخيار متاح فقط لحسابات الإدارة.');
    
            return;
        }
    
        $pending = $link->pending_action;
        $action = $pending['action'] ?? null;
        $step = $pending['step'] ?? null;
        $data = $pending['data'] ?? [];
    
        if ($action === 'admcontent_course_query' && $step === 'query') {
            if (mb_strlen($normalized) < 2) {
                $bot->sendMessage($chatId, 'اكتب حرفين على الأقل 🙂');
    
                return;
            }
    
            $courses = Course::query()
                ->where('is_active', true)
                ->where(function ($query) use ($normalized) {
                    $query->where('name_ar', 'like', "%{$normalized}%")
                        ->orWhere('name_en', 'like', "%{$normalized}%")
                        ->orWhere('code', 'like', "%{$normalized}%");
                })
                ->orderBy('name_ar')
                ->limit(8)
                ->get();
    
            if ($courses->isEmpty()) {
                // لا نمسح pending_action هون (سلوك "لاصق" كالبحث العام) —
                // حتى تعمل إعادة المحاولة بلا إعادة ضغط الزر.
                $bot->sendMessage($chatId, '❌ ما لقيت مادة بهذا الاسم، جرّب اسم أو رمز مختلف (أو اكتب "إلغاء"):');
    
                return;
            }
    
            $keyboard = [];
            foreach ($courses as $course) {
                $label = (string) ($course->name_ar ?? $course->name_en ?? $course->code);
                $keyboard[] = [['text' => $label, 'callback_data' => 'admcontent:course:'.$course->id]];
            }
            $bot->sendMessage($chatId, '📚 اختر المادة:', $keyboard);
    
            return;
        }
    
        if ($action === 'admcontent_addsection' && $step === 'title') {
            if ($normalized === '' || mb_strlen($normalized) > 190) {
                $bot->sendMessage($chatId, 'عنوان غير صالح 🙂 اكتب نص غير فاضي (بحد أقصى ١٩٠ حرف):');
    
                return;
            }
    
            $data['title'] = $normalized;
            $link->update(['pending_action' => ['action' => $action, 'step' => 'description', 'lecture_id' => null, 'data' => $data]]);
            $bot->sendMessage($chatId, '📝 اكتب وصف مختصر (اختياري)، أو ارسل "تخطي":');
    
            return;
        }
    
        if ($action === 'admcontent_addsection' && $step === 'description') {
            $data['description'] = in_array($normalized, ['تخطي', 'skip', '-'], true) ? null : $normalized;
            $link->update(['pending_action' => ['action' => $action, 'step' => 'counts_toward_progress', 'lecture_id' => null, 'data' => $data]]);
            $bot->sendMessage($chatId, 'هل يُحتسب ضمن إنجاز الطالب؟', [[
                ['text' => '✅ نعم', 'callback_data' => 'admcontent:wizbtn:counts_toward_progress:1'],
                ['text' => '➖ لا', 'callback_data' => 'admcontent:wizbtn:counts_toward_progress:0'],
            ]]);
    
            return;
        }
    
        if ($action === 'admcontent_addunit' && $step === 'title') {
            if ($normalized === '' || mb_strlen($normalized) > 190) {
                $bot->sendMessage($chatId, 'عنوان غير صالح 🙂 اكتب نص غير فاضي (بحد أقصى ١٩٠ حرف):');
    
                return;
            }
    
            $data['title'] = $normalized;
            $link->update(['pending_action' => ['action' => $action, 'step' => 'description', 'lecture_id' => null, 'data' => $data]]);
            $bot->sendMessage($chatId, '📝 اكتب وصف مختصر (اختياري)، أو ارسل "تخطي":');
    
            return;
        }
    
        if ($action === 'admcontent_addunit' && $step === 'description') {
            $data['description'] = in_array($normalized, ['تخطي', 'skip', '-'], true) ? null : $normalized;
            $link->update(['pending_action' => ['action' => $action, 'step' => 'defaultcats', 'lecture_id' => null, 'data' => $data]]);
            $bot->sendMessage($chatId, '🏷️ هل تُنشأ التصنيفات الأساسية الأربعة تلقائيًا (المحاضرات/التعيينات/التدريبات/روابط مهمة)؟', [[
                ['text' => '✅ نعم', 'callback_data' => 'admcontent:wizbtn:defaultcats:1'],
                ['text' => '❌ لا', 'callback_data' => 'admcontent:wizbtn:defaultcats:0'],
            ]]);
    
            return;
        }
    
        if ($action === 'admcontent_addfile' && $step === 'title') {
            if ($normalized === '' || mb_strlen($normalized) > 190) {
                $bot->sendMessage($chatId, 'عنوان غير صالح 🙂 اكتب نص غير فاضي (بحد أقصى ١٩٠ حرف):');
    
                return;
            }
    
            $data['title'] = $normalized;
            $link->update(['pending_action' => ['action' => $action, 'step' => 'kind', 'lecture_id' => null, 'data' => $data]]);
    
            $keyboard = [];
            $row = [];
            foreach (self::ADMIN_CONTENT_KIND_LABELS as $kindKey => $label) {
                $row[] = ['text' => $label, 'callback_data' => 'admcontent:wizbtn:kind:'.$kindKey];
                if (count($row) === 2) {
                    $keyboard[] = $row;
                    $row = [];
                }
            }
            if ($row !== []) {
                $keyboard[] = $row;
            }
            $bot->sendMessage($chatId, '🏷️ اختر نوع المحتوى:', $keyboard);
    
            return;
        }
    
        if ($action === 'admcontent_addfile' && $step === 'url') {
            if (! $this->isValidHttpsUrl($normalized) || mb_strlen($normalized) > 1000) {
                $bot->sendMessage($chatId, 'رابط غير صالح 🙂 لازم يبدأ بـ https:// (بحد أقصى ١٠٠٠ حرف):');
    
                return;
            }
    
            $data['external_url'] = $normalized;
            $link->update(['pending_action' => ['action' => $action, 'step' => 'description', 'lecture_id' => null, 'data' => $data]]);
            $bot->sendMessage($chatId, '📝 اكتب وصف مختصر (اختياري)، أو ارسل "تخطي":');
    
            return;
        }
    
        if ($action === 'admcontent_addfile' && $step === 'description') {
            $data['description'] = in_array($normalized, ['تخطي', 'skip', '-'], true) ? null : $normalized;
            $link->update(['pending_action' => ['action' => $action, 'step' => 'published', 'lecture_id' => null, 'data' => $data]]);
            $bot->sendMessage($chatId, '👁️ هل يُنشر مباشرة للطلاب؟', [[
                ['text' => '🟢 نعم', 'callback_data' => 'admcontent:wizbtn:published:1'],
                ['text' => '🔴 لا (مسودة)', 'callback_data' => 'admcontent:wizbtn:published:0'],
            ]]);
    
            return;
        }
    
        // تعديل حقل نصي بقسم/تصنيف/وحدة/ملف موجود (title/description/external_url).
        if (str_starts_with((string) $action, 'admcontent_edit_')) {
            $type = substr((string) $action, strlen('admcontent_edit_'));
            $id = (int) ($data['id'] ?? 0);
            $field = $data['field'] ?? null;
    
            $model = match ($type) {
                'section' => CourseSection::query()->find($id),
                'unit' => CourseUnit::query()->find($id),
                'file' => CourseFile::query()->find($id),
                default => null,
            };
    
            if (! $model || ! $field) {
                $link->update(['pending_action' => null]);
                $bot->sendMessage($chatId, '⚠️ تعذّر إيجاد العنصر، ابدأ من جديد.');
    
                return;
            }
    
            if ($field === 'title') {
                if ($normalized === '' || mb_strlen($normalized) > 190) {
                    $bot->sendMessage($chatId, 'عنوان غير صالح 🙂 اكتب نص غير فاضي (بحد أقصى ١٩٠ حرف):');
    
                    return;
                }
                $model->update(['title' => $normalized]);
            } elseif ($field === 'description') {
                $isClear = in_array($normalized, ['-', 'تخطي', 'skip'], true);
                $model->update(['description' => $isClear ? null : $normalized]);
            } elseif ($field === 'external_url') {
                if (! $this->isValidHttpsUrl($normalized) || mb_strlen($normalized) > 1000) {
                    $bot->sendMessage($chatId, 'رابط غير صالح 🙂 لازم يبدأ بـ https:// (بحد أقصى ١٠٠٠ حرف):');
    
                    return;
                }
                $model->update(['external_url' => $normalized]);
            }
    
            $link->update(['pending_action' => null]);
            $bot->sendMessage($chatId, '✅ تم الحفظ.');
    
            if ($type === 'section') {
                $this->sendAdminContentSectionDetail($bot, $chatId, $id);
            } elseif ($type === 'unit') {
                $this->sendAdminContentUnitDetail($bot, $chatId, $id);
            } else {
                $this->sendAdminContentFileDetail($bot, $chatId, $id);
            }
    
            return;
        }
    
        $bot->sendMessage($chatId, 'استخدم الأزرار يلي فوق 🙂 أو اكتب "إلغاء" لإيقاف العملية.');
    }

    /*
     * ============================================================
     * "🎓 إدارة المساقات" — إضافة مادة جديدة للخطة الدراسية أو تعديل
     * مادة موجودة، بنفس منطق Staff/CourseController بالضبط (توليد
     * المفتاح "key"، اشتقاق الصفحة "page" من السنة/النوع، ترتيب الإدخال
     * الافتراضي، فحص التكرار مع سلة المحذوفات). لا مسار منفصل: الكتابة
     * على نفس جدول courses الذي يقرأ منه كل مكان بالموقع (dynamic-courses.js
     * بصفحات السنوات/الاختياريات، صفحة المادة، وPlanCalculator بالخطة
     * الدراسية بالصفحة الشخصية) — فالمادة المضافة/المعدَّلة تظهر تلقائيًا
     * بكل هذي الأماكن بمجرد الحفظ، بلا أي خطوة إضافية.
     *
     * الفصل بالواجهة هون "الأول/الثاني ضمن السنة" (كما طلب الطاقم) بينما
     * عمود semester بالقاعدة رقم عالمي ١..٨ (فصلا السنة N هما 2N-1 وN2) —
     * التحويل بالدالتين adminCourseGlobalSemester/adminCourseLocalSemester
     * أدناه، نفس الصيغة المستخدمة أصلًا بالواجهة (dynamic-courses.js).
     * ============================================================
     */
    
    private function sendAdminCoursesMenu(TelegramBotApi $bot, int|string $chatId): void
    {
        $keyboard = [
            [['text' => '➕ إضافة مادة جديدة للخطة', 'callback_data' => 'admcourse:addnew']],
            [['text' => '✏️ تعديل مادة موجودة', 'callback_data' => 'admcourse:searchagain']],
        ];
    
        $bot->sendMessage($chatId, '🎓 <b>إدارة المساقات والخطة الدراسية</b>', $keyboard);
    }
    
    private function adminCourseGlobalSemester(int $year, int $local): int
    {
        return ($year - 1) * 2 + $local;
    }
    
    /** @return array{0:int,1:int} [السنة, الفصل ضمنها (١ أو ٢)] */
    private function adminCourseLocalSemester(int $semester): array
    {
        $year = (int) max(1, min(4, ceil($semester / 2)));
        $local = $semester - ($year - 1) * 2;
    
        return [$year, $local < 1 ? 1 : $local];
    }
    
    private function adminCourseMakeKey(string $code): string
    {
        $clean = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '', $code) ?: \Illuminate\Support\Str::random(8));
    
        return 'c_'.$clean;
    }
    
    /*
     * نفس Staff/CourseController::pageFor() بالضبط — الصفحة تُشتقّ ولا
     * تُدخَل يدويًا، لأنه dynamic-courses.js يرشّح المواد بمطابقة page
     * حرفيًا مع اسم الصفحة الحالية.
     */
    private function adminCoursePageFor(int $year, string $courseType): string
    {
        if ($courseType === 'elective') {
            return 'electives.html';
        }
    
        return 'year'.max(1, min(4, $year)).'.html';
    }
    
    private function adminCourseNextSortOrder(int $year, int $semester): int
    {
        return ((int) Course::query()->where('year', $year)->where('semester', $semester)->max('sort_order')) + 1;
    }
    
    private function sendAdminCourseDetail(TelegramBotApi $bot, int|string $chatId, int $courseId): void
    {
        $course = Course::query()->withTrashed()->find($courseId);
    
        if (! $course) {
            $bot->sendMessage($chatId, '⚠️ هذه المادة غير موجودة.');
    
            return;
        }
    
        if ($course->trashed()) {
            $bot->sendMessage($chatId, '⚠️ هذه المادة بسلة المحذوفات بالموقع — التعديل/الاسترجاع من البوت غير متاح حاليًا، استخدم لوحة الموقع.');
    
            return;
        }
    
        [$year, $local] = $this->adminCourseLocalSemester((int) $course->semester);
        $typeLabel = self::ADMIN_COURSE_TYPE_LABELS[$course->course_type] ?? $course->course_type;
    
        $lines = [
            '🎓 <b>'.TelegramBotApi::escapeHtml((string) $course->name_ar).'</b>',
            '🏷️ الرمز: '.TelegramBotApi::escapeHtml((string) $course->code),
        ];
    
        if (! empty($course->name_en)) {
            $lines[] = '🇬🇧 '.TelegramBotApi::escapeHtml((string) $course->name_en);
        }
    
        $lines[] = '📅 السنة '.$year.' — الفصل '.($local === 1 ? 'الأول' : 'الثاني');
        $lines[] = '⏱️ الساعات المعتمدة: '.($course->credit_hours ?? '—');
        $lines[] = '📚 النوع: '.$typeLabel;
        $lines[] = $course->is_active ? '🟢 مفعّلة (ظاهرة للطلاب)' : '🔴 معطّلة (مخفية عن الطلاب)';
    
        if (! empty($course->description)) {
            $lines[] = '📝 '.TelegramBotApi::escapeHtml((string) $course->description);
        }
    
        $keyboard = [
            [
                ['text' => '✏️ تعديل', 'callback_data' => 'admcourse:editfields:'.$course->id],
                ['text' => $course->is_active ? '🔴 تعطيل' : '🟢 تفعيل', 'callback_data' => 'admcourse:toggleactive:'.$course->id],
            ],
            [['text' => '🗑️ حذف (نقل لسلة المحذوفات)', 'callback_data' => 'admcourse:delconfirm:'.$course->id]],
            [['text' => '🔍 بحث عن مادة أخرى', 'callback_data' => 'admcourse:searchagain']],
        ];
    
        $bot->sendMessage($chatId, implode("\n", $lines), $keyboard);
    }
    
    private function sendAdminCourseFieldPicker(TelegramBotApi $bot, int|string $chatId, int $courseId): void
    {
        $labels = [
            'code' => 'الرمز', 'name_ar' => 'الاسم بالعربية', 'name_en' => 'الاسم بالإنجليزية',
            'credit_hours' => 'الساعات المعتمدة', 'placement' => 'السنة والفصل',
            'course_type' => 'النوع', 'description' => 'الوصف',
        ];
    
        $keyboard = [];
    
        foreach ($labels as $field => $label) {
            $keyboard[] = [['text' => $label, 'callback_data' => 'admcourse:editfield:'.$courseId.':'.$field]];
        }
    
        $keyboard[] = [['text' => '🔙 رجوع', 'callback_data' => 'admcourse:detail:'.$courseId]];
    
        $bot->sendMessage($chatId, 'اختر الحقل يلي بدك تعدّله:', $keyboard);
    }
    
    private function handleAdminCourseCallback(TelegramBotApi $bot, array $callbackQuery): void
    {
        $callbackId = (string) ($callbackQuery['id'] ?? '');
        $chatId = $callbackQuery['message']['chat']['id'] ?? null;
        $data = (string) ($callbackQuery['data'] ?? '');
        $action = substr($data, strlen('admcourse:'));
        $parts = explode(':', $action);
        $key = $parts[0] ?? '';
    
        if (! $chatId) {
            $bot->answerCallbackQuery($callbackId);
    
            return;
        }
    
        $link = TelegramLink::query()->whereNotNull('telegram_chat_id')->where('telegram_chat_id', $chatId)->first();
    
        if (! $link || ! $link->user || ! $link->user->isStaff()) {
            $bot->answerCallbackQuery($callbackId, 'غير مخوّل.');
    
            return;
        }
    
        $bot->answerCallbackQuery($callbackId);
    
        if ($key === 'searchagain') {
            $link->update(['pending_action' => ['action' => 'admcourse_search', 'step' => 'query', 'lecture_id' => null, 'data' => []]]);
            $bot->sendMessage($chatId, '🔍 اكتب اسم المادة أو رمزها (أو اكتب "إلغاء"):');
    
            return;
        }
    
        if ($key === 'addnew') {
            $link->update(['pending_action' => ['action' => 'admcourse_add', 'step' => 'code', 'lecture_id' => null, 'data' => []]]);
            $bot->sendMessage($chatId, "➕ <b>إضافة مادة جديدة للخطة</b>\n\n🏷️ اكتب رمز المادة (مثال: CS101):");
    
            return;
        }
    
        if ($key === 'detail') {
            $link->update(['pending_action' => null]);
            $this->sendAdminCourseDetail($bot, $chatId, (int) ($parts[1] ?? 0));
    
            return;
        }
    
        if ($key === 'editfields') {
            $this->sendAdminCourseFieldPicker($bot, $chatId, (int) ($parts[1] ?? 0));
    
            return;
        }
    
        if ($key === 'editfield') {
            $courseId = (int) ($parts[1] ?? 0);
            $field = $parts[2] ?? '';
            $course = Course::query()->find($courseId);
    
            if (! $course) {
                $bot->sendMessage($chatId, '⚠️ هذه المادة غير موجودة.');
    
                return;
            }
    
            $textFields = ['code', 'name_ar', 'name_en', 'description', 'credit_hours'];
    
            if (in_array($field, $textFields, true)) {
                $link->update(['pending_action' => [
                    'action' => 'admcourse_edit', 'step' => $field, 'lecture_id' => null,
                    'data' => ['id' => $courseId],
                ]]);
    
                $prompts = [
                    'code' => '🏷️ اكتب الرمز الجديد:',
                    'name_ar' => '🇵🇸 اكتب الاسم الجديد بالعربية:',
                    'name_en' => '🇬🇧 اكتب الاسم الجديد بالإنجليزية (أو "-" لمسحه):',
                    'description' => '📝 اكتب الوصف الجديد (أو "-" لمسحه):',
                    'credit_hours' => '⏱️ اكتب عدد الساعات المعتمدة الجديد (رقم بين ٠ و٢٠):',
                ];
                $bot->sendMessage($chatId, $prompts[$field]);
    
                return;
            }
    
            if ($field === 'course_type') {
                $keyboard = [];
                foreach (self::ADMIN_COURSE_TYPE_LABELS as $typeKey => $label) {
                    $keyboard[] = [['text' => $label, 'callback_data' => 'admcourse:setval:'.$courseId.':course_type:'.$typeKey]];
                }
                $bot->sendMessage($chatId, '📚 اختر النوع الجديد:', $keyboard);
    
                return;
            }
    
            if ($field === 'placement') {
                $link->update(['pending_action' => [
                    'action' => 'admcourse_edit_placement', 'step' => 'year', 'lecture_id' => null,
                    'data' => ['id' => $courseId],
                ]]);
                $bot->sendMessage($chatId, '📅 اختر السنة الجديدة:', [
                    [
                        ['text' => 'السنة ١', 'callback_data' => 'admcourse:placeyear:'.$courseId.':1'],
                        ['text' => 'السنة ٢', 'callback_data' => 'admcourse:placeyear:'.$courseId.':2'],
                    ],
                    [
                        ['text' => 'السنة ٣', 'callback_data' => 'admcourse:placeyear:'.$courseId.':3'],
                        ['text' => 'السنة ٤', 'callback_data' => 'admcourse:placeyear:'.$courseId.':4'],
                    ],
                ]);
    
                return;
            }
    
            return;
        }
    
        if ($key === 'placeyear') {
            $courseId = (int) ($parts[1] ?? 0);
            $year = (int) ($parts[2] ?? 0);
            $pending = $link->pending_action;
    
            if (($pending['action'] ?? null) !== 'admcourse_edit_placement' || ($pending['step'] ?? null) !== 'year') {
                return;
            }
    
            $data = $pending['data'] ?? [];
            $data['year'] = $year;
            $link->update(['pending_action' => ['action' => 'admcourse_edit_placement', 'step' => 'semester', 'lecture_id' => null, 'data' => $data]]);
            $bot->sendMessage($chatId, 'وأي فصل ضمن السنة '.$year.'؟', [[
                ['text' => 'الفصل الأول', 'callback_data' => 'admcourse:placesem:'.$courseId.':1'],
                ['text' => 'الفصل الثاني', 'callback_data' => 'admcourse:placesem:'.$courseId.':2'],
            ]]);
    
            return;
        }
    
        if ($key === 'placesem') {
            $courseId = (int) ($parts[1] ?? 0);
            $local = (int) ($parts[2] ?? 0);
            $pending = $link->pending_action;
    
            if (($pending['action'] ?? null) !== 'admcourse_edit_placement' || ($pending['step'] ?? null) !== 'semester') {
                return;
            }
    
            $course = Course::query()->find($courseId);
    
            if (! $course) {
                $link->update(['pending_action' => null]);
                $bot->sendMessage($chatId, '⚠️ هذه المادة غير موجودة.');
    
                return;
            }
    
            $year = (int) ($pending['data']['year'] ?? $course->year);
            $semester = $this->adminCourseGlobalSemester($year, $local);
    
            $course->update([
                'year' => $year,
                'semester' => $semester,
                'page' => $this->adminCoursePageFor($year, (string) $course->course_type),
            ]);
    
            $link->update(['pending_action' => null]);
            $bot->sendMessage($chatId, '✅ تم نقل المادة للسنة '.$year.' — الفصل '.($local === 1 ? 'الأول' : 'الثاني').'.');
            $this->sendAdminCourseDetail($bot, $chatId, $courseId);
    
            return;
        }
    
        if ($key === 'setval') {
            $courseId = (int) ($parts[1] ?? 0);
            $field = $parts[2] ?? '';
            $value = $parts[3] ?? null;
            $course = Course::query()->find($courseId);
    
            if (! $course || $value === null) {
                $bot->sendMessage($chatId, '⚠️ تعذّر التحديث.');
    
                return;
            }
    
            if ($field === 'course_type') {
                $course->update([
                    'course_type' => $value,
                    'page' => $this->adminCoursePageFor((int) $course->year, $value),
                ]);
            }
    
            $bot->sendMessage($chatId, '✅ تم الحفظ.');
            $this->sendAdminCourseDetail($bot, $chatId, $courseId);
    
            return;
        }
    
        if ($key === 'toggleactive') {
            $courseId = (int) ($parts[1] ?? 0);
            $course = Course::query()->find($courseId);
    
            if (! $course) {
                $bot->sendMessage($chatId, '⚠️ هذه المادة غير موجودة.');
    
                return;
            }
    
            $course->update(['is_active' => ! $course->is_active]);
            $this->sendAdminCourseDetail($bot, $chatId, $courseId);
    
            return;
        }
    
        if ($key === 'delconfirm') {
            $courseId = (int) ($parts[1] ?? 0);
            $bot->sendMessage($chatId, '⚠️ متأكد إنك بدك تحذف هذه المادة؟ (حذف ناعم — قابل للاسترجاع من لوحة الموقع فقط حاليًا)', [[
                ['text' => '✅ نعم، احذف', 'callback_data' => 'admcourse:delyes:'.$courseId],
                ['text' => '❌ لا، رجوع', 'callback_data' => 'admcourse:detail:'.$courseId],
            ]]);
    
            return;
        }
    
        if ($key === 'delyes') {
            $courseId = (int) ($parts[1] ?? 0);
            $course = Course::query()->find($courseId);
    
            if ($course) {
                $course->delete();
                $bot->sendMessage($chatId, '🗑️ تم حذف المادة (نقلها لسلة المحذوفات).');
            }
    
            return;
        }
    
        if ($key === 'wizyear') {
            $year = (int) ($parts[1] ?? 0);
            $pending = $link->pending_action;
    
            if (($pending['action'] ?? null) !== 'admcourse_add' || ($pending['step'] ?? null) !== 'year') {
                return;
            }
    
            $data = $pending['data'] ?? [];
            $data['year'] = $year;
            $link->update(['pending_action' => ['action' => 'admcourse_add', 'step' => 'semester', 'lecture_id' => null, 'data' => $data]]);
            $bot->sendMessage($chatId, 'وأي فصل ضمن السنة '.$year.'؟', [[
                ['text' => 'الفصل الأول', 'callback_data' => 'admcourse:wizsem:1'],
                ['text' => 'الفصل الثاني', 'callback_data' => 'admcourse:wizsem:2'],
            ]]);
    
            return;
        }
    
        if ($key === 'wizsem') {
            $local = (int) ($parts[1] ?? 0);
            $pending = $link->pending_action;
    
            if (($pending['action'] ?? null) !== 'admcourse_add' || ($pending['step'] ?? null) !== 'semester') {
                return;
            }
    
            $data = $pending['data'] ?? [];
            $data['semester_local'] = $local;
            $link->update(['pending_action' => ['action' => 'admcourse_add', 'step' => 'type', 'lecture_id' => null, 'data' => $data]]);
    
            $keyboard = [];
            foreach (self::ADMIN_COURSE_TYPE_LABELS as $typeKey => $label) {
                $keyboard[] = [['text' => $label, 'callback_data' => 'admcourse:wiztype:'.$typeKey]];
            }
            $bot->sendMessage($chatId, '📚 اختر نوع المادة:', $keyboard);
    
            return;
        }
    
        if ($key === 'wiztype') {
            $type = $parts[1] ?? '';
            $pending = $link->pending_action;
    
            if (($pending['action'] ?? null) !== 'admcourse_add' || ($pending['step'] ?? null) !== 'type') {
                return;
            }
    
            $data = $pending['data'] ?? [];
            $data['course_type'] = $type;
            $link->update(['pending_action' => ['action' => 'admcourse_add', 'step' => 'description', 'lecture_id' => null, 'data' => $data]]);
            $bot->sendMessage($chatId, '📝 اكتب وصف المادة (اختياري)، أو ارسل "تخطي":');
    
            return;
        }
    
        if ($key === 'wizcreate') {
            $this->handleAdminCourseCreate($bot, $link, $chatId);
    
            return;
        }
    
        if ($key === 'wizcancel') {
            $link->update(['pending_action' => null]);
            $bot->sendMessage($chatId, 'تم الإلغاء.');
            $this->sendAdminCoursesMenu($bot, $chatId);
    
            return;
        }
    }
    
    /*
     * تنفيذ الإنشاء الفعلي — نفس منطق Staff/CourseController@store بالضبط:
     * توليد المفتاح من الرمز، فحص التكرار (بما فيه المحذوف ناعمًا)، اشتقاق
     * الصفحة، وترتيب افتراضي آخر الفصل.
     */
    private function handleAdminCourseCreate(TelegramBotApi $bot, TelegramLink $link, int|string $chatId): void
    {
        $pending = $link->pending_action;
        $data = $pending['data'] ?? [];
    
        $key = $this->adminCourseMakeKey((string) $data['code']);
        $existing = Course::query()->withTrashed()->where('key', $key)->first();
    
        if ($existing) {
            if ($existing->trashed()) {
                $link->update(['pending_action' => null]);
                $bot->sendMessage($chatId, '⚠️ يوجد مساق بنفس الرمز بسلة المحذوفات بالموقع — استرجعه من لوحة الموقع بدل إضافة رمز مكرر، أو استخدم رمز مختلف.');
    
                return;
            }
    
            $link->update(['pending_action' => ['action' => 'admcourse_add', 'step' => 'code', 'lecture_id' => null, 'data' => []]]);
            $bot->sendMessage($chatId, '⚠️ يوجد مساق آخر بنفس الرمز أو المفتاح فعلًا. اكتب رمزًا مختلفًا:');
    
            return;
        }
    
        $year = (int) $data['year'];
        $semester = $this->adminCourseGlobalSemester($year, (int) $data['semester_local']);
        $courseType = (string) $data['course_type'];
    
        $course = Course::create([
            'key' => $key,
            'code' => $data['code'],
            'name_ar' => $data['name_ar'],
            'name_en' => $data['name_en'] ?? null,
            'year' => $year,
            'semester' => $semester,
            'credit_hours' => $data['credit_hours'] ?? null,
            'course_type' => $courseType,
            'description' => $data['description'] ?? null,
            'page' => $this->adminCoursePageFor($year, $courseType),
            'is_active' => true,
            'sort_order' => $this->adminCourseNextSortOrder($year, $semester),
        ]);
    
        $link->update(['pending_action' => null]);
        $bot->sendMessage($chatId, '✅ تمت إضافة المادة للخطة، وهي ظاهرة الآن تلقائيًا بصفحة سنتها وبالخطة الدراسية لأي طالب يطابق سنته وفصله.');
        $this->sendAdminCourseDetail($bot, $chatId, $course->id);
    }
    
    private function handleAdminCourseTextInput(TelegramBotApi $bot, TelegramLink $link, int|string $chatId, string $text): void
    {
        $normalized = trim($text);
    
        if (in_array($normalized, ['إلغاء', 'الغاء', 'cancel'], true)) {
            $link->update(['pending_action' => null]);
            $bot->sendMessage($chatId, 'تم الإلغاء.');
            $this->sendAdminCoursesMenu($bot, $chatId);
    
            return;
        }
    
        if (! $link->user || ! $link->user->isStaff()) {
            $link->update(['pending_action' => null]);
            $bot->sendMessage($chatId, '⛔ هذا الخيار متاح فقط لحسابات الإدارة.');
    
            return;
        }
    
        $pending = $link->pending_action;
        $action = $pending['action'] ?? null;
        $step = $pending['step'] ?? null;
        $data = $pending['data'] ?? [];
    
        if ($action === 'admcourse_search' && $step === 'query') {
            if (mb_strlen($normalized) < 2) {
                $bot->sendMessage($chatId, 'اكتب حرفين على الأقل 🙂');
    
                return;
            }
    
            $courses = Course::query()
                ->where(function ($query) use ($normalized) {
                    $query->where('name_ar', 'like', "%{$normalized}%")
                        ->orWhere('name_en', 'like', "%{$normalized}%")
                        ->orWhere('code', 'like', "%{$normalized}%");
                })
                ->orderBy('name_ar')
                ->limit(8)
                ->get();
    
            if ($courses->isEmpty()) {
                $bot->sendMessage($chatId, '❌ ما لقيت مادة بهذا الاسم، جرّب اسم أو رمز مختلف (أو اكتب "إلغاء"):');
    
                return;
            }
    
            $keyboard = [];
            foreach ($courses as $course) {
                $icon = $course->is_active ? '🟢' : '🔴';
                $label = $icon.' '.(string) ($course->name_ar ?? $course->name_en ?? $course->code);
                $keyboard[] = [['text' => $label, 'callback_data' => 'admcourse:detail:'.$course->id]];
            }
            $bot->sendMessage($chatId, '📚 اختر المادة:', $keyboard);
    
            return;
        }
    
        if ($action === 'admcourse_add') {
            if ($step === 'code') {
                if ($normalized === '' || mb_strlen($normalized) > 50) {
                    $bot->sendMessage($chatId, 'رمز غير صالح 🙂 اكتب رمزًا غير فاضٍ (بحد أقصى ٥٠ حرف):');
    
                    return;
                }
    
                $data['code'] = $normalized;
                $link->update(['pending_action' => ['action' => $action, 'step' => 'name_ar', 'lecture_id' => null, 'data' => $data]]);
                $bot->sendMessage($chatId, '🇵🇸 اكتب اسم المادة بالعربية:');
    
                return;
            }
    
            if ($step === 'name_ar') {
                if ($normalized === '' || mb_strlen($normalized) > 190) {
                    $bot->sendMessage($chatId, 'اسم غير صالح 🙂 اكتب نص غير فاضٍ (بحد أقصى ١٩٠ حرف):');
    
                    return;
                }
    
                $data['name_ar'] = $normalized;
                $link->update(['pending_action' => ['action' => $action, 'step' => 'name_en', 'lecture_id' => null, 'data' => $data]]);
                $bot->sendMessage($chatId, '🇬🇧 اكتب اسم المادة بالإنجليزية (اختياري)، أو ارسل "تخطي":');
    
                return;
            }
    
            if ($step === 'name_en') {
                $data['name_en'] = in_array($normalized, ['تخطي', 'skip', '-'], true) ? null : $normalized;
                $link->update(['pending_action' => ['action' => $action, 'step' => 'credit_hours', 'lecture_id' => null, 'data' => $data]]);
                $bot->sendMessage($chatId, '⏱️ اكتب عدد الساعات المعتمدة (رقم بين ٠ و٢٠)، أو ارسل "تخطي":');
    
                return;
            }
    
            if ($step === 'credit_hours') {
                if (in_array($normalized, ['تخطي', 'skip', '-'], true)) {
                    $data['credit_hours'] = null;
                } elseif (preg_match('/^\d{1,2}$/', $normalized) && (int) $normalized <= 20) {
                    $data['credit_hours'] = (int) $normalized;
                } else {
                    $bot->sendMessage($chatId, 'رقم غير صالح 🙂 اكتب رقم صحيح بين ٠ و٢٠، أو ارسل "تخطي":');
    
                    return;
                }
    
                $link->update(['pending_action' => ['action' => $action, 'step' => 'year', 'lecture_id' => null, 'data' => $data]]);
                $bot->sendMessage($chatId, '📅 اختر السنة:', [
                    [
                        ['text' => 'السنة ١', 'callback_data' => 'admcourse:wizyear:1'],
                        ['text' => 'السنة ٢', 'callback_data' => 'admcourse:wizyear:2'],
                    ],
                    [
                        ['text' => 'السنة ٣', 'callback_data' => 'admcourse:wizyear:3'],
                        ['text' => 'السنة ٤', 'callback_data' => 'admcourse:wizyear:4'],
                    ],
                ]);
    
                return;
            }
    
            if ($step === 'description') {
                $data['description'] = in_array($normalized, ['تخطي', 'skip', '-'], true) ? null : $normalized;
                $link->update(['pending_action' => ['action' => $action, 'step' => 'confirm', 'lecture_id' => null, 'data' => $data]]);
    
                $typeLabel = self::ADMIN_COURSE_TYPE_LABELS[$data['course_type']] ?? $data['course_type'];
                $preview = "🎓 <b>معاينة المادة الجديدة</b>\n\n".
                    '<b>'.TelegramBotApi::escapeHtml((string) $data['name_ar']).'</b>'.
                    (! empty($data['name_en']) ? ' / '.TelegramBotApi::escapeHtml((string) $data['name_en']) : '').
                    "\n🏷️ ".TelegramBotApi::escapeHtml((string) $data['code']).
                    "\n📅 السنة {$data['year']} — الفصل ".($data['semester_local'] == 1 ? 'الأول' : 'الثاني').
                    "\n⏱️ الساعات: ".($data['credit_hours'] ?? '—').
                    "\n📚 النوع: {$typeLabel}".
                    (! empty($data['description']) ? "\n📝 ".TelegramBotApi::escapeHtml((string) $data['description']) : '');
    
                $bot->sendMessage($chatId, $preview, [[
                    ['text' => '✅ إضافة المادة', 'callback_data' => 'admcourse:wizcreate'],
                    ['text' => '❌ إلغاء', 'callback_data' => 'admcourse:wizcancel'],
                ]]);
    
                return;
            }
    
            $bot->sendMessage($chatId, 'استخدم الأزرار يلي فوق 🙂 أو اكتب "إلغاء" لإيقاف العملية.');
    
            return;
        }
    
        if ($action === 'admcourse_edit') {
            $courseId = (int) ($data['id'] ?? 0);
            $course = Course::query()->find($courseId);
    
            if (! $course) {
                $link->update(['pending_action' => null]);
                $bot->sendMessage($chatId, '⚠️ تعذّر إيجاد المادة، ابدأ من جديد.');
    
                return;
            }
    
            if ($step === 'code') {
                if ($normalized === '' || mb_strlen($normalized) > 50) {
                    $bot->sendMessage($chatId, 'رمز غير صالح 🙂 اكتب رمزًا غير فاضٍ (بحد أقصى ٥٠ حرف):');
    
                    return;
                }
                $course->update(['code' => $normalized]);
            } elseif ($step === 'name_ar') {
                if ($normalized === '' || mb_strlen($normalized) > 190) {
                    $bot->sendMessage($chatId, 'اسم غير صالح 🙂 اكتب نص غير فاضٍ (بحد أقصى ١٩٠ حرف):');
    
                    return;
                }
                $course->update(['name_ar' => $normalized]);
            } elseif ($step === 'name_en') {
                $isClear = in_array($normalized, ['-', 'تخطي', 'skip'], true);
                $course->update(['name_en' => $isClear ? null : $normalized]);
            } elseif ($step === 'description') {
                $isClear = in_array($normalized, ['-', 'تخطي', 'skip'], true);
                $course->update(['description' => $isClear ? null : $normalized]);
            } elseif ($step === 'credit_hours') {
                if (preg_match('/^\d{1,2}$/', $normalized) && (int) $normalized <= 20) {
                    $course->update(['credit_hours' => (int) $normalized]);
                } else {
                    $bot->sendMessage($chatId, 'رقم غير صالح 🙂 اكتب رقم صحيح بين ٠ و٢٠:');
    
                    return;
                }
            }
    
            $link->update(['pending_action' => null]);
            $bot->sendMessage($chatId, '✅ تم الحفظ.');
            $this->sendAdminCourseDetail($bot, $chatId, $courseId);
    
            return;
        }
    
        $bot->sendMessage($chatId, 'استخدم الأزرار يلي فوق 🙂 أو اكتب "إلغاء" لإيقاف العملية.');
    }

    /*
     * ============================================================
     * "📖 مساقاتي الحالية" — مساقات الطالب المسجَّلة فعليًا (my_courses)،
     * بنفس منطق MyCourseController بالضبط (قيد الخانة الاختيارية المتاحة،
     * وسلوك الحذف الناعم/الصريح حسب مصدر التسجيل) — فأي إضافة/حذف هون
     * ينعكس مباشرة بالصفحة الشخصية بالموقع وبالعكس، لأنه نفس الجدول تمامًا.
     * ============================================================
     */
    
    private function sendMyCoursesList(TelegramBotApi $bot, int|string $chatId, \App\Models\User $user): void
    {
        $courses = $user->myCourses()->wherePivot('status', 'registered')->orderBy('semester')->get();
    
        $lines = ['📖 <b>مساقاتي الحالية</b>'];
        $keyboard = [];
    
        if ($courses->isEmpty()) {
            $lines[] = '';
            $lines[] = 'ما في عندك مساقات مسجَّلة حاليًا.';
        } else {
            foreach ($courses as $course) {
                $label = trim(($course->code ? $course->code.' — ' : '').(string) ($course->name_ar ?: $course->name_en));
                $keyboard[] = [['text' => $label, 'callback_data' => 'mycourse:view:'.$course->key]];
            }
        }
    
        $keyboard[] = [['text' => '➕ إضافة مساق', 'callback_data' => 'mycourse:addsearch']];
    
        $bot->sendMessage($chatId, implode("\n", $lines), $keyboard);
    }
    
    private function sendMyCourseActions(TelegramBotApi $bot, int|string $chatId, string $courseKey): void
    {
        $course = Course::query()->where('key', $courseKey)->where('is_active', true)->first();
    
        if (! $course) {
            $bot->sendMessage($chatId, '⚠️ هذه المادة غير موجودة أو غير مفعّلة حاليًا.', [[['text' => '🔙 رجوع', 'callback_data' => 'mycourse:list']]]);
    
            return;
        }
    
        $name = TelegramBotApi::escapeHtml((string) ($course->name_ar ?: $course->name_en));
        $keyboard = [
            [['text' => '📂 التفاصيل والمحتوى', 'callback_data' => 'hub:course:'.$course->key]],
            [['text' => '⭐📊 تصفّح تفاعلي (مفضلة وإنجاز)', 'callback_data' => 'content:course:'.$course->key.':1']],
            [['text' => '🗑️ حذف من مساقاتي', 'callback_data' => 'mycourse:removeconfirm:'.$course->key]],
            [['text' => '🔙 رجوع لمساقاتي', 'callback_data' => 'mycourse:list']],
        ];
    
        $bot->sendMessage($chatId, "📘 <b>{$name}</b>", $keyboard);
    }
    
    private function handleMyCourseCallback(TelegramBotApi $bot, array $callbackQuery): void
    {
        $callbackId = (string) ($callbackQuery['id'] ?? '');
        $chatId = $callbackQuery['message']['chat']['id'] ?? null;
        $data = (string) ($callbackQuery['data'] ?? '');
        $action = substr($data, strlen('mycourse:'));
        $parts = explode(':', $action);
        $key = $parts[0] ?? '';
    
        if (! $chatId) {
            $bot->answerCallbackQuery($callbackId);
    
            return;
        }
    
        $link = TelegramLink::query()->whereNotNull('telegram_chat_id')->where('telegram_chat_id', $chatId)->first();
    
        if (! $link || ! $link->user) {
            $bot->answerCallbackQuery($callbackId, 'حسابك غير مربوط.');
    
            return;
        }
    
        $bot->answerCallbackQuery($callbackId);
        $user = $link->user;
    
        if ($key === 'list') {
            $link->update(['pending_action' => null]);
            $this->sendMyCoursesList($bot, $chatId, $user);
    
            return;
        }
    
        if ($key === 'view') {
            $link->update(['pending_action' => null]);
            $this->sendMyCourseActions($bot, $chatId, (string) ($parts[1] ?? ''));
    
            return;
        }
    
        if ($key === 'addsearch') {
            $link->update(['pending_action' => ['action' => 'mycourse_add', 'step' => 'query', 'lecture_id' => null, 'data' => []]]);
            $bot->sendMessage($chatId, '🔍 اكتب اسم المادة أو رمزها للإضافة (أو اكتب "إلغاء"):');
    
            return;
        }
    
        if ($key === 'add') {
            $this->handleMyCourseAdd($bot, $user, $chatId, (string) ($parts[1] ?? ''));
    
            return;
        }
    
        if ($key === 'removeconfirm') {
            $courseKey = (string) ($parts[1] ?? '');
            $bot->sendMessage($chatId, '⚠️ متأكد إنك بدك تحذف هذا المساق من مساقاتك الحالية؟', [[
                ['text' => '✅ نعم، احذف', 'callback_data' => 'mycourse:removeyes:'.$courseKey],
                ['text' => '❌ لا، رجوع', 'callback_data' => 'mycourse:view:'.$courseKey],
            ]]);
    
            return;
        }
    
        if ($key === 'removeyes') {
            $this->handleMyCourseRemove($bot, $user, $chatId, (string) ($parts[1] ?? ''));
    
            return;
        }
    }
    
    /*
     * نفس منطق MyCourseController@store بالضبط: قيد الخانة الاختيارية
     * المتاحة (لا يسجّل طالب مادة اختيارية إلا ضمن خانة placeholder مفتوحة
     * بسنته وفصله الحالي)، والتعامل مع صفّ "dropped" سابق بإعادة تفعيله
     * بدل إدخال مكرر.
     */
    private function handleMyCourseAdd(TelegramBotApi $bot, \App\Models\User $user, int|string $chatId, string $courseKey): void
    {
        $course = Course::query()->where('key', $courseKey)->where('is_active', true)->first();
    
        if (! $course) {
            $bot->sendMessage($chatId, '⚠️ هذه المادة غير موجودة أو غير مفعّلة حاليًا.');
    
            return;
        }
    
        if ($course->course_type === 'elective') {
            $user->loadMissing('currentTerm');
            $term = $user->currentTerm;
            $year = (int) $user->year;
            $planSemester = ($year && $term && in_array((int) $term->semester, [1, 2], true))
                ? (($year - 1) * 2) + (int) $term->semester
                : null;
    
            $hasOpenSlot = $planSemester && Course::query()
                ->where('is_active', true)
                ->where('course_type', 'placeholder')
                ->where('year', $year)
                ->where('semester', $planSemester)
                ->exists();
    
            if (! $hasOpenSlot) {
                $bot->sendMessage($chatId, '⚠️ ما في خانة اختيارية متاحة إلك بفصلك الدراسي الحالي.');
    
                return;
            }
        }
    
        $existing = \Illuminate\Support\Facades\DB::table('my_courses')->where('user_id', $user->id)->where('course_id', $course->id)->first();
    
        if (! $existing) {
            \Illuminate\Support\Facades\DB::table('my_courses')->insert([
                'user_id' => $user->id,
                'course_id' => $course->id,
                'source' => 'manual',
                'term_id' => $user->current_term_id,
                'status' => 'registered',
                'completed_at' => null,
                'grade' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $update = ['source' => 'manual', 'updated_at' => now()];
    
            if (($existing->status ?? 'registered') === 'dropped') {
                $update['status'] = 'registered';
                $update['term_id'] = $user->current_term_id;
                $update['completed_at'] = null;
            } elseif (($existing->status ?? 'registered') === 'registered' && $user->current_term_id) {
                $update['term_id'] = $user->current_term_id;
            }
    
            \Illuminate\Support\Facades\DB::table('my_courses')->where('user_id', $user->id)->where('course_id', $course->id)->update($update);
        }
    
        $bot->sendMessage($chatId, '✅ تمت إضافة المساق لمساقاتك الحالية.');
        $this->sendMyCoursesList($bot, $chatId, $user);
    }
    
    /*
     * نفس منطق MyCourseController@destroy بالضبط: مساق الفصل الحالي
     * (تلقائي أو مقترَح لسنة/فصل الطالق المُعلَنين) يُعلَّم "dropped" لا
     * يُحذف نهائيًا (لأن المزامنة التلقائية ستعيده)، وأي مساق آخر يُحذف
     * صفّه فعليًا.
     */
    private function handleMyCourseRemove(TelegramBotApi $bot, \App\Models\User $user, int|string $chatId, string $courseKey): void
    {
        $course = Course::query()->where('key', $courseKey)->first();
    
        if (! $course) {
            $bot->sendMessage($chatId, '⚠️ هذه المادة غير موجودة.');
    
            return;
        }
    
        $existing = \Illuminate\Support\Facades\DB::table('my_courses')->where('user_id', $user->id)->where('course_id', $course->id)->first();
    
        if ($existing) {
            $isAutomatic = ($existing->source ?? 'manual') === 'automatic';
            $isCurrentSuggested = $this->isCurrentSuggestedMyCourse($user, $course);
    
            if ($isAutomatic || $isCurrentSuggested) {
                \Illuminate\Support\Facades\DB::table('my_courses')->where('user_id', $user->id)->where('course_id', $course->id)->update([
                    'status' => 'dropped', 'source' => 'manual', 'completed_at' => null, 'updated_at' => now(),
                ]);
            } else {
                \Illuminate\Support\Facades\DB::table('my_courses')->where('user_id', $user->id)->where('course_id', $course->id)->delete();
            }
        }
    
        $bot->sendMessage($chatId, '🗑️ تم حذف المساق من مساقاتك الحالية.');
        $this->sendMyCoursesList($bot, $chatId, $user);
    }
    
    private function isCurrentSuggestedMyCourse(\App\Models\User $user, Course $course): bool
    {
        $user->loadMissing('currentTerm');
        $term = $user->currentTerm;
        $year = (int) $user->year;
    
        if (! $year || ! $term || ! in_array((int) $term->semester, [1, 2], true)) {
            return false;
        }
    
        $planSemester = (($year - 1) * 2) + (int) $term->semester;
    
        return (bool) $course->is_active
            && (int) $course->year === $year
            && (int) $course->semester === $planSemester
            && in_array($course->course_type, ['required', 'placeholder'], true);
    }
    
    private function handleMyCourseTextInput(TelegramBotApi $bot, TelegramLink $link, int|string $chatId, string $text): void
    {
        $normalized = trim($text);
    
        if (in_array($normalized, ['إلغاء', 'الغاء', 'cancel'], true)) {
            $link->update(['pending_action' => null]);
            $bot->sendMessage($chatId, 'تم الإلغاء.');
            $this->sendMyCoursesList($bot, $chatId, $link->user);
    
            return;
        }
    
        $pending = $link->pending_action;
    
        if (($pending['action'] ?? null) === 'mycourse_add' && ($pending['step'] ?? null) === 'query') {
            if (mb_strlen($normalized) < 2) {
                $bot->sendMessage($chatId, 'اكتب حرفين على الأقل 🙂');
    
                return;
            }
    
            $courses = Course::query()
                ->where('is_active', true)
                ->where(function ($query) use ($normalized) {
                    $query->where('name_ar', 'like', "%{$normalized}%")
                        ->orWhere('name_en', 'like', "%{$normalized}%")
                        ->orWhere('code', 'like', "%{$normalized}%");
                })
                ->orderBy('name_ar')
                ->limit(8)
                ->get();
    
            if ($courses->isEmpty()) {
                $bot->sendMessage($chatId, '❌ ما لقيت مادة بهذا الاسم، جرّب اسم أو رمز مختلف (أو اكتب "إلغاء"):');
    
                return;
            }
    
            $keyboard = [];
            foreach ($courses as $course) {
                $label = trim(($course->code ? $course->code.' — ' : '').(string) ($course->name_ar ?: $course->name_en));
                $keyboard[] = [['text' => $label, 'callback_data' => 'mycourse:add:'.$course->key]];
            }
            $bot->sendMessage($chatId, '📚 اختر المادة يلي بدك تضيفها:', $keyboard);
    
            return;
        }
    
        $bot->sendMessage($chatId, 'استخدم الأزرار يلي فوق 🙂 أو اكتب "إلغاء" لإيقاف العملية.');
    }

    /*
     * ============================================================
     * "⭐📊 تصفّح تفاعلي (مفضلة وإنجاز)" — نفس محتوى المادة العام المعروض
     * بـsendCourseHubCourseFiles (public/منشور/جاهز فقط — البوت هون بلا
     * حساب مطابق لحالة الطالب بالمادة، فنفس قيد anonymous المستخدَم أصلًا
     * بذاك العرض)، لكن كعناصر مستقلة بأزرار: تفضيل (favorites) وتعليم
     * إنجاز (course_content_progress) — شخصيان لكل طالب، بنفس الجداول
     * التي يقرأ/يكتب منها الموقع تمامًا.
     * ============================================================
     */
    
    private function contentVisibleFilesForCourse(Course $course)
    {
        return CourseFile::query()
            ->where('course_id', $course->id)
            ->where('is_published', true)
            ->where('status', 'ready')
            ->where('visibility', 'public')
            ->orderBy('course_section_id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }
    
    private function sendContentCourseFileList(TelegramBotApi $bot, int|string $chatId, \App\Models\User $user, string $courseKey, int $page): void
    {
        $course = Course::query()->where('key', $courseKey)->where('is_active', true)->first();
    
        if (! $course) {
            $bot->sendMessage($chatId, '⚠️ هذه المادة غير موجودة أو غير مفعّلة حاليًا.', [[['text' => '🔙 رجوع', 'callback_data' => 'hub:root']]]);
    
            return;
        }
    
        $files = $this->contentVisibleFilesForCourse($course);
        $courseName = TelegramBotApi::escapeHtml((string) ($course->name_ar ?: $course->name_en));
    
        if ($files->isEmpty()) {
            $bot->sendMessage(
                $chatId,
                "📭 لا يوجد محتوى عام متاح لمادة \"{$courseName}\" ضمن البوت حاليًا.",
                [[['text' => '🔙 رجوع لتفاصيل المادة', 'callback_data' => 'hub:course:'.$course->key]]]
            );
    
            return;
        }
    
        $countableIds = $files->where('counts_toward_progress', true)->pluck('id');
        $doneIds = $countableIds->isEmpty() ? collect() : CourseContentProgress::query()
            ->where('user_id', $user->id)
            ->whereIn('course_file_id', $countableIds)
            ->pluck('course_file_id');
        $favIds = Favorite::query()->where('user_id', $user->id)->whereIn('course_file_id', $files->pluck('id'))->pluck('course_file_id');
    
        $lines = ["📁 <b>محتوى {$courseName}</b>"];
    
        if ($countableIds->isNotEmpty()) {
            $pct = (int) round(($doneIds->count() / $countableIds->count()) * 100);
            $lines[] = "📊 إنجازك: {$doneIds->count()} من {$countableIds->count()} ({$pct}٪)";
        }
    
        $total = $files->count();
        $pageItems = $files->forPage($page, self::COURSE_HUB_PAGE_SIZE);
    
        $keyboard = [];
    
        foreach ($pageItems as $file) {
            $prefix = ($favIds->contains($file->id) ? '⭐' : '').($file->counts_toward_progress && $doneIds->contains($file->id) ? '✅' : '');
            $label = trim($prefix.' '.$file->title);
            $keyboard[] = [['text' => $label, 'callback_data' => 'content:file:'.$file->id]];
        }
    
        $lastPage = (int) ceil($total / self::COURSE_HUB_PAGE_SIZE);
        $pagerRow = [];
    
        if ($page > 1) {
            $pagerRow[] = ['text' => '⬅️ السابق', 'callback_data' => 'content:course:'.$course->key.':'.($page - 1)];
        }
    
        if ($page < $lastPage) {
            $pagerRow[] = ['text' => 'التالي ➡️', 'callback_data' => 'content:course:'.$course->key.':'.($page + 1)];
        }
    
        if ($pagerRow !== []) {
            $keyboard[] = $pagerRow;
        }
    
        $keyboard[] = [['text' => '🔙 رجوع لتفاصيل المادة', 'callback_data' => 'hub:course:'.$course->key]];
    
        $bot->sendMessage($chatId, implode("\n", $lines), $keyboard);
    }
    
    private function sendContentFileDetail(TelegramBotApi $bot, int|string $chatId, \App\Models\User $user, int $fileId): void
    {
        $file = CourseFile::query()->with('course')->find($fileId);
    
        if (! $file || ! $file->course || $file->visibility !== 'public' || ! $file->is_published || $file->status !== 'ready') {
            $bot->sendMessage($chatId, '⚠️ هذا المحتوى غير متاح حاليًا.');
    
            return;
        }
    
        $kindLabel = self::INLINE_CONTENT_KIND_LABELS[$file->kind] ?? 'محتوى';
        $isFav = Favorite::query()->where('user_id', $user->id)->where('course_file_id', $file->id)->exists();
        $isDone = $file->counts_toward_progress && CourseContentProgress::query()->where('user_id', $user->id)->where('course_file_id', $file->id)->exists();
    
        $lines = [
            '📄 <b>'.TelegramBotApi::escapeHtml((string) $file->title).'</b>',
            '🏷️ '.$kindLabel,
        ];
    
        if (! empty($file->description)) {
            $lines[] = '📝 '.TelegramBotApi::escapeHtml((string) $file->description);
        }
    
        if ($isFav) {
            $lines[] = '⭐ بمفضلاتك';
        }
    
        if ($file->counts_toward_progress) {
            $lines[] = $isDone ? '✅ معلَّم كمنجَز' : '⬜ لسا ما أنجزته';
        }
    
        $frontendUrl = rtrim((string) config('app.frontend_url'), '/');
        $externalUrl = trim((string) $file->external_url);
        $openUrl = $externalUrl !== '' ? $externalUrl : $frontendUrl.'/course.html?course='.urlencode((string) $file->course->key).'&content='.$file->id;
    
        $keyboard = [
            [['text' => '🔗 فتح الرابط', 'url' => $openUrl]],
            [['text' => $isFav ? '💔 إزالة من المفضلة' : '⭐ إضافة للمفضلة', 'callback_data' => 'content:fav:'.$file->id]],
        ];
    
        if ($file->counts_toward_progress) {
            $keyboard[] = [['text' => $isDone ? '↩️ إلغاء الإنجاز' : '✅ علّمه كمنجَز', 'callback_data' => 'content:done:'.$file->id]];
        }
    
        $keyboard[] = [['text' => '🔙 رجوع لمحتوى المادة', 'callback_data' => 'content:course:'.$file->course->key.':1']];
    
        $bot->sendMessage($chatId, implode("\n", $lines), $keyboard);
    }
    
    private function sendContentFavoritesList(TelegramBotApi $bot, int|string $chatId, \App\Models\User $user, int $page): void
    {
        $favFileIds = Favorite::query()->where('user_id', $user->id)->pluck('course_file_id');
    
        $files = CourseFile::query()
            ->with('course')
            ->whereIn('id', $favFileIds)
            ->where('is_published', true)
            ->where('status', 'ready')
            ->where('visibility', 'public')
            ->orderBy('id', 'desc')
            ->get();
    
        if ($files->isEmpty()) {
            $bot->sendMessage($chatId, '⭐ لسا ما ضفت أي محتوى للمفضلة.', [[['text' => '🔙 رجوع', 'callback_data' => 'hub:root']]]);
    
            return;
        }
    
        $total = $files->count();
        $pageItems = $files->forPage($page, self::COURSE_HUB_PAGE_SIZE);

        /*
         * الطلب صريح: القائمة نفسها لازم تعرض عنوان الملف، مادته،
         * ورابطه المباشر (يوتيوب/درايف/أي رابط) بدون ما يضطر الطالب
         * يفتح كل عنصر لحاله ليشوف الرابط — الأزرار تحته تبقى فقط
         * لإدارة المفضلة/الإنجاز بضغطة وحدة.
         */
        $frontendUrl = rtrim((string) config('app.frontend_url'), '/');
        $lastPageForHeader = (int) ceil($total / self::COURSE_HUB_PAGE_SIZE);
        $lines = ['⭐ <b>مفضلاتي</b> (صفحة '.$page.' من '.max(1, $lastPageForHeader).')', ''];
        $keyboard = [];

        foreach ($pageItems as $file) {
            $courseName = (string) ($file->course->name_ar ?: $file->course->name_en ?: $file->course->code);
            $kindLabel = self::INLINE_CONTENT_KIND_LABELS[$file->kind] ?? 'محتوى';
            $externalUrl = trim((string) $file->external_url);
            $link = $externalUrl !== '' ? $externalUrl : $frontendUrl.'/course.html?course='.urlencode((string) $file->course->key).'&content='.$file->id;

            $lines[] = '📄 <b>'.TelegramBotApi::escapeHtml((string) $file->title).'</b> — '.$kindLabel;
            $lines[] = '📘 '.TelegramBotApi::escapeHtml($courseName);
            $lines[] = '🔗 '.$link;
            $lines[] = '';

            $label = '⚙️ '.trim($file->title);
            $keyboard[] = [['text' => $label, 'callback_data' => 'content:file:'.$file->id]];
        }

        $lastPage = $lastPageForHeader;
        $pagerRow = [];

        if ($page > 1) {
            $pagerRow[] = ['text' => '⬅️ السابق', 'callback_data' => 'content:favorites:'.($page - 1)];
        }

        if ($page < $lastPage) {
            $pagerRow[] = ['text' => 'التالي ➡️', 'callback_data' => 'content:favorites:'.($page + 1)];
        }
    
        if ($pagerRow !== []) {
            $keyboard[] = $pagerRow;
        }
    
        $keyboard[] = [['text' => '🔙 رجوع', 'callback_data' => 'hub:root']];

        $this->sendChunkedMessage($bot, $chatId, $lines, $keyboard);
    }
    
    private function handleContentCallback(TelegramBotApi $bot, array $callbackQuery): void
    {
        $callbackId = (string) ($callbackQuery['id'] ?? '');
        $chatId = $callbackQuery['message']['chat']['id'] ?? null;
        $data = (string) ($callbackQuery['data'] ?? '');
        $action = substr($data, strlen('content:'));
        $parts = explode(':', $action);
        $key = $parts[0] ?? '';
    
        if (! $chatId) {
            $bot->answerCallbackQuery($callbackId);
    
            return;
        }
    
        $link = TelegramLink::query()->whereNotNull('telegram_chat_id')->where('telegram_chat_id', $chatId)->first();
    
        if (! $link || ! $link->user) {
            $bot->answerCallbackQuery($callbackId, 'حسابك غير مربوط.');
    
            return;
        }
    
        $bot->answerCallbackQuery($callbackId);
        $user = $link->user;
    
        if ($key === 'course') {
            $courseKey = (string) ($parts[1] ?? '');
            $page = max(1, (int) ($parts[2] ?? 1));
            $this->sendContentCourseFileList($bot, $chatId, $user, $courseKey, $page);
    
            return;
        }
    
        if ($key === 'file') {
            $this->sendContentFileDetail($bot, $chatId, $user, (int) ($parts[1] ?? 0));
    
            return;
        }
    
        if ($key === 'fav') {
            $fileId = (int) ($parts[1] ?? 0);
            $existing = Favorite::query()->where('user_id', $user->id)->where('course_file_id', $fileId)->first();
    
            if ($existing) {
                $existing->delete();
            } else {
                Favorite::create(['user_id' => $user->id, 'course_file_id' => $fileId]);
            }
    
            $this->sendContentFileDetail($bot, $chatId, $user, $fileId);
    
            return;
        }
    
        if ($key === 'done') {
            $fileId = (int) ($parts[1] ?? 0);
            $file = CourseFile::query()->find($fileId);
    
            if ($file && $file->counts_toward_progress) {
                $existing = CourseContentProgress::query()->where('user_id', $user->id)->where('course_file_id', $fileId)->first();
    
                if ($existing) {
                    $existing->delete();
                } else {
                    CourseContentProgress::create(['user_id' => $user->id, 'course_file_id' => $fileId, 'completed_at' => now()]);
                }
            }
    
            $this->sendContentFileDetail($bot, $chatId, $user, $fileId);
    
            return;
        }
    
        if ($key === 'favorites') {
            $page = max(1, (int) ($parts[1] ?? 1));
            $this->sendContentFavoritesList($bot, $chatId, $user, $page);
    
            return;
        }
    }

    /*
     * ============================================================
     * "📨 تواصل معنا" — نفس مسار الموقع بالضبط: ContactController العام
     * يستقبل (الاسم، الإيميل، الموضوع، الرسالة) ويرسلها بـContactMessageMail
     * على نفس صندوق الموقع (mail.contact.inbox) — بلا أي جدول قاعدة بيانات
     * جديد، تمامًا كما هو مصمَّم أصلًا (الجيميل نفسه هو "صندوق الوارد").
     *
     * الفرق هون: الحساب مربوط أصلًا، فنعبّي الاسم والإيميل تلقائيًا من
     * حساب الطالب ونعرضهم بمعاينة قابلة للتعديل قبل الإرسال (بدل ما نطلب
     * منه يكتبهم من الصفر متل فورم الموقع) — أسرع، وبلا حاجة لحقل الفخّ
     * (honeypot) لأنه فقط حساب حقيقي مربوط يقدر يوصل لهاي الخطوة أصلًا.
     * لو صار بالمستقبل دعم لطلاب غير مسجَّلين، نفس الخطوات هاي تشتغل
     * وبس تبدأ الحقول فاضية بدل مُعبَّأة من الحساب.
     * ============================================================
     */
    
    private function startContactFlow(TelegramBotApi $bot, TelegramLink $link, int|string $chatId): void
    {
        $user = $link->user;
        $name = trim(implode(' ', array_filter([$user->first_name ?? null, $user->father_name ?? null, $user->last_name ?? null])));
    
        $link->update(['pending_action' => [
            'action' => 'contact', 'step' => 'subject', 'lecture_id' => null,
            'data' => ['name' => $name, 'email' => (string) ($user->email ?? '')],
        ]]);
    
        $bot->sendMessage($chatId, "📨 <b>تواصل معنا</b>\n\nعندك استفسار أو اقتراح أو لاحظت خطأ بالموقع؟ اكتبلنا هون وبيوصلنا على بريدنا مباشرة.\n\n📝 اكتب موضوع رسالتك (٣ إلى ١٢٠ حرف)، أو اكتب \"إلغاء\":");
    }
    
    private function sendContactConfirm(TelegramBotApi $bot, int|string $chatId, array $data): void
    {
        $preview = "📨 <b>معاينة رسالتك</b>\n\n".
            '👤 '.TelegramBotApi::escapeHtml((string) $data['name'])."\n".
            '✉️ '.TelegramBotApi::escapeHtml((string) $data['email'])."\n".
            '📝 <b>'.TelegramBotApi::escapeHtml((string) $data['subject'])."</b>\n\n".
            TelegramBotApi::escapeHtml((string) $data['message']);
    
        $bot->sendMessage($chatId, $preview, [
            [
                ['text' => '✏️ الاسم', 'callback_data' => 'contact:editname'],
                ['text' => '✏️ الإيميل', 'callback_data' => 'contact:editemail'],
            ],
            [['text' => '✅ إرسال', 'callback_data' => 'contact:send']],
            [['text' => '❌ إلغاء', 'callback_data' => 'contact:cancel']],
        ]);
    }
    
    private function handleContactCallback(TelegramBotApi $bot, array $callbackQuery): void
    {
        $callbackId = (string) ($callbackQuery['id'] ?? '');
        $chatId = $callbackQuery['message']['chat']['id'] ?? null;
        $data = (string) ($callbackQuery['data'] ?? '');
        $key = substr($data, strlen('contact:'));
    
        if (! $chatId) {
            $bot->answerCallbackQuery($callbackId);
    
            return;
        }
    
        $link = TelegramLink::query()->whereNotNull('telegram_chat_id')->where('telegram_chat_id', $chatId)->first();
    
        if (! $link || ! $link->user) {
            $bot->answerCallbackQuery($callbackId, 'حسابك غير مربوط.');
    
            return;
        }
    
        $pending = $link->pending_action;
    
        if (($pending['action'] ?? null) !== 'contact') {
            $bot->answerCallbackQuery($callbackId);
    
            return;
        }
    
        $bot->answerCallbackQuery($callbackId);
        $pendingData = $pending['data'] ?? [];
    
        if ($key === 'cancel') {
            $link->update(['pending_action' => null]);
            $bot->sendMessage($chatId, 'تم الإلغاء.');
    
            return;
        }
    
        if ($key === 'editname') {
            $link->update(['pending_action' => ['action' => 'contact', 'step' => 'edit_name', 'lecture_id' => null, 'data' => $pendingData]]);
            $bot->sendMessage($chatId, '👤 اكتب الاسم الجديد:');
    
            return;
        }
    
        if ($key === 'editemail') {
            $link->update(['pending_action' => ['action' => 'contact', 'step' => 'edit_email', 'lecture_id' => null, 'data' => $pendingData]]);
            $bot->sendMessage($chatId, '✉️ اكتب الإيميل الجديد:');
    
            return;
        }
    
        if ($key === 'send') {
            if (($pending['step'] ?? null) !== 'confirm') {
                return;
            }
    
            $name = trim((string) ($pendingData['name'] ?? ''));
            $email = trim((string) ($pendingData['email'] ?? ''));
            $subject = trim((string) ($pendingData['subject'] ?? ''));
            $message = trim((string) ($pendingData['message'] ?? ''));
    
            if ($name === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL) || $subject === '' || $message === '') {
                $link->update(['pending_action' => null]);
                $bot->sendMessage($chatId, '⚠️ في خطأ بالبيانات، ابدأ من جديد من "📨 تواصل معنا".');
    
                return;
            }
    
            $mail = new ContactMessageMail(
                senderName: $name,
                senderEmail: $email,
                subjectLine: $subject,
                body: $message,
            );
    
            try {
                Mail::to(config('mail.contact.inbox') ?: config('mail.from.address'))->send($mail);
            } catch (TransportException $error) {
                Log::error('تعذّر إرسال رسالة تواصل من البوت.', ['error' => $error->getMessage()]);
                $bot->sendMessage($chatId, '⚠️ تعذّر إرسال رسالتك الآن. حاول بعد قليل.');
    
                return;
            }
    
            $link->update(['pending_action' => null]);
            $bot->sendMessage($chatId, '✅ وصلتنا رسالتك، وبيوصلك الرد على بريدك مباشرة.');
    
            return;
        }
    }
    
    private function handleContactTextInput(TelegramBotApi $bot, TelegramLink $link, int|string $chatId, string $text): void
    {
        $normalized = trim($text);
    
        if (in_array($normalized, ['إلغاء', 'الغاء', 'cancel'], true)) {
            $link->update(['pending_action' => null]);
            $bot->sendMessage($chatId, 'تم الإلغاء.');
    
            return;
        }
    
        $pending = $link->pending_action;
        $step = $pending['step'] ?? null;
        $data = $pending['data'] ?? [];
    
        if ($step === 'subject') {
            if (mb_strlen($normalized) < 3 || mb_strlen($normalized) > 120) {
                $bot->sendMessage($chatId, 'الموضوع لازم يكون بين ٣ و١٢٠ حرف 🙂 حاول كمان:');
    
                return;
            }
    
            $data['subject'] = $normalized;
            $link->update(['pending_action' => ['action' => 'contact', 'step' => 'message', 'lecture_id' => null, 'data' => $data]]);
            $bot->sendMessage($chatId, '📝 اكتب رسالتك بالتفصيل (١٠ إلى ٢٠٠٠ حرف):');
    
            return;
        }
    
        if ($step === 'message') {
            if (mb_strlen($normalized) < 10 || mb_strlen($normalized) > 2000) {
                $bot->sendMessage($chatId, 'الرسالة لازم تكون بين ١٠ و٢٠٠٠ حرف 🙂 حاول كمان:');
    
                return;
            }
    
            $data['message'] = $normalized;
    
            if (trim((string) ($data['name'] ?? '')) === '' || ! filter_var($data['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
                /*
                 * حساب بلا اسم/إيميل صالح (أو مستقبلًا: طالب غير مربوط) —
                 * نطلبهم صراحةً بدل ما نعرض معاينة بحقول فاضية.
                 */
                $link->update(['pending_action' => ['action' => 'contact', 'step' => 'edit_name', 'lecture_id' => null, 'data' => $data]]);
                $bot->sendMessage($chatId, '👤 اكتب اسمك:');
    
                return;
            }
    
            $link->update(['pending_action' => ['action' => 'contact', 'step' => 'confirm', 'lecture_id' => null, 'data' => $data]]);
            $this->sendContactConfirm($bot, $chatId, $data);
    
            return;
        }
    
        if ($step === 'edit_name') {
            if (mb_strlen($normalized) < 2 || mb_strlen($normalized) > 80) {
                $bot->sendMessage($chatId, 'اسم غير صالح 🙂 اكتب اسمًا بين حرفين و٨٠ حرف:');
    
                return;
            }
    
            $data['name'] = $normalized;
    
            if (! filter_var($data['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
                $link->update(['pending_action' => ['action' => 'contact', 'step' => 'edit_email', 'lecture_id' => null, 'data' => $data]]);
                $bot->sendMessage($chatId, '✉️ اكتب إيميلك:');
    
                return;
            }
    
            $nextStep = isset($data['subject'], $data['message']) ? 'confirm' : 'subject';
            $link->update(['pending_action' => ['action' => 'contact', 'step' => $nextStep, 'lecture_id' => null, 'data' => $data]]);
    
            if ($nextStep === 'confirm') {
                $this->sendContactConfirm($bot, $chatId, $data);
            } else {
                $bot->sendMessage($chatId, '📝 اكتب موضوع رسالتك (٣ إلى ١٢٠ حرف):');
            }
    
            return;
        }
    
        if ($step === 'edit_email') {
            if (! filter_var($normalized, FILTER_VALIDATE_EMAIL) || mb_strlen($normalized) > 120) {
                $bot->sendMessage($chatId, 'إيميل غير صالح 🙂 اكتب بريد إلكتروني صحيح:');
    
                return;
            }
    
            $data['email'] = $normalized;
            $nextStep = isset($data['subject'], $data['message']) ? 'confirm' : 'subject';
            $link->update(['pending_action' => ['action' => 'contact', 'step' => $nextStep, 'lecture_id' => null, 'data' => $data]]);
    
            if ($nextStep === 'confirm') {
                $this->sendContactConfirm($bot, $chatId, $data);
            } else {
                $bot->sendMessage($chatId, '📝 اكتب موضوع رسالتك (٣ إلى ١٢٠ حرف):');
            }
    
            return;
        }
    
        $bot->sendMessage($chatId, 'استخدم الأزرار يلي فوق 🙂 أو اكتب "إلغاء" لإيقاف العملية.');
    }

/*
 * ============================================================
 * "📤 شارك ملف/مصدر" — بلا أي منطق رفع/تخزين جديد إطلاقًا: نفس آلية
 * telegram_contributions المستخدَمة أصلًا بزر الموقع (TelegramUploadController)
 * بالضبط — نفس شكل الـpayload، نفس التوقيع HMAC، نفس الخدمة الخارجية
 * (services.telegram_contributions.web_app_url) — لكن مُولَّدة من داخل
 * بوت المساعد الأكاديمي مباشرة، فيوصل الطالب لبوت الرفع (@PTCHubFilesBot)
 * برابط جاهز مسبوق ببياناته، بلا ما يغادر تيليجرام يدويًا عبر الموقع.
 *
 * مصدر حقيقة واحد فقط للمشاركة: أي تغيير مستقبلي على منطق الرفع
 * (تحقق، صلاحية التوكن...) بمكان واحد بالباك-إند لكلا المسارين.
 * ============================================================
 */

private function generateContributionLink(\App\Models\User $user, Course $course): array
{
    $webAppUrl = (string) config('services.telegram_contributions.web_app_url', '');
    $sharedSecret = (string) config('services.telegram_contributions.shared_secret', '');

    if ($webAppUrl === '' || $sharedSecret === '') {
        return ['ok' => false, 'message' => 'إعدادات بوت رفع الملفات غير مكتملة حاليًا.'];
    }

    $studentName = trim(implode(' ', array_filter([
        $user->first_name ?? null, $user->father_name ?? null, $user->last_name ?? null,
    ])));

    if ($studentName === '') {
        $studentName = (string) $user->email;
    }

    $courseName = (string) ($course->name_ar ?: $course->name_en ?: $course->code ?: 'المادة');

    $payloadData = [
        'website_user_id' => (string) $user->id,
        'student_name' => $studentName,
        'student_email' => (string) $user->email,
        'course_id' => (string) ($course->key ?? $course->id),
        'course_code' => (string) ($course->code ?? ''),
        'course_name' => $courseName,
        'exp' => now()->addMinutes(5)->timestamp,
    ];

    $json = json_encode($payloadData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $payload = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    $signature = hash_hmac('sha256', $payload, $sharedSecret);

    try {
        $response = \Illuminate\Support\Facades\Http::timeout(15)
            ->withOptions(['allow_redirects' => true])
            ->get($webAppUrl, ['payload' => $payload, 'signature' => $signature, 'mode' => 'json']);

        if (! $response->successful()) {
            return ['ok' => false, 'message' => 'تعذّر الاتصال بخدمة تيليجرام حاليًا.'];
        }

        $result = $response->json();

        if (! is_array($result) || ! ($result['ok'] ?? false)) {
            return ['ok' => false, 'message' => (string) ($result['message'] ?? 'تعذّر إنشاء جلسة المشاركة.')];
        }

        $telegramUrl = $result['data']['url'] ?? null;

        if (! is_string($telegramUrl) || ! str_starts_with($telegramUrl, 'https://t.me/')) {
            return ['ok' => false, 'message' => 'رابط تيليجرام غير صالح.'];
        }

        return ['ok' => true, 'url' => $telegramUrl];
    } catch (\Throwable $error) {
        Log::error('تعذّر إنشاء رابط بوت رفع الملفات من داخل البوت.', ['error' => $error->getMessage()]);

        return ['ok' => false, 'message' => 'تعذّر إنشاء جلسة المشاركة مع بوت رفع الملفات الآن.'];
    }
}

private function sendContributionLinkForCourse(TelegramBotApi $bot, int|string $chatId, \App\Models\User $user, Course $course): void
{
    $courseName = TelegramBotApi::escapeHtml((string) ($course->name_ar ?: $course->name_en));
    $result = $this->generateContributionLink($user, $course);

    if (! $result['ok']) {
        $bot->sendMessage(
            $chatId,
            '⚠️ '.TelegramBotApi::escapeHtml((string) $result['message']),
            [[['text' => '🔙 رجوع لتفاصيل المادة', 'callback_data' => 'hub:course:'.$course->key]]]
        );

        return;
    }

    $bot->sendMessage(
        $chatId,
        "📤 <b>مشاركة ملف/مصدر لمادة {$courseName}</b>\n\n".
        "اضغط الزر تحت ليفتحلك بوت رفع الملفات (@PTCHubFilesBot) مباشرة، وبياناتك ومادتك معبّاة تلقائيًا — كل يلي عليك ترفع الملف أو تكتب اقتراحك هناك.\n\n".
        '⏱️ الرابط صالح لخمس دقائق فقط.',
        [
            [['text' => '📤 افتح بوت رفع الملفات', 'url' => $result['url']]],
            [['text' => '🔙 رجوع لتفاصيل المادة', 'callback_data' => 'hub:course:'.$course->key]],
        ]
    );
}

/*
 * زر مستقل بالقائمة الرئيسية (📤 شارك ملف/مصدر) — يسأل أول شي عن
 * المادة (نفس أسلوب بحث "مساقاتي الحالية" بالضبط)، بعكس زر المادة
 * المباشر يلي ما بيحتاج بحث لأنه أصلًا جوّا تفاصيل مادة محددة.
 */
private function startContributeFlow(TelegramBotApi $bot, TelegramLink $link, int|string $chatId): void
{
    $link->update(['pending_action' => ['action' => 'contribute_pick', 'step' => 'query', 'lecture_id' => null, 'data' => []]]);
    $bot->sendMessage($chatId, "📤 <b>مشاركة ملف/مصدر</b>\n\nلأي مادة بدك تشارك ملف أو مصدر؟ اكتب اسمها أو رمزها (حرفين على الأقل)، أو اكتب \"إلغاء\":");
}

private function handleContributeCallback(TelegramBotApi $bot, array $callbackQuery): void
{
    $callbackId = (string) ($callbackQuery['id'] ?? '');
    $chatId = $callbackQuery['message']['chat']['id'] ?? null;
    $data = (string) ($callbackQuery['data'] ?? '');
    $rest = substr($data, strlen('contribute:'));

    if (! $chatId) {
        $bot->answerCallbackQuery($callbackId);

        return;
    }

    $link = TelegramLink::query()->whereNotNull('telegram_chat_id')->where('telegram_chat_id', $chatId)->first();

    if (! $link || ! $link->user) {
        $bot->answerCallbackQuery($callbackId, 'حسابك غير مربوط.');

        return;
    }

    $bot->answerCallbackQuery($callbackId);

    [$action, $courseKey] = array_pad(explode(':', $rest, 2), 2, null);

    if (! in_array($action, ['course', 'pick'], true) || ! $courseKey) {
        return;
    }

    $course = Course::query()->where('key', $courseKey)->where('is_active', true)->first();

    if (! $course) {
        $bot->sendMessage($chatId, '⚠️ هذه المادة غير موجودة أو غير مفعّلة حاليًا.', [[['text' => '🔙 رجوع', 'callback_data' => 'hub:root']]]);

        return;
    }

    if ($action === 'pick') {
        $link->update(['pending_action' => null]);
    }

    $this->sendContributionLinkForCourse($bot, $chatId, $link->user, $course);
}

private function handleContributeTextInput(TelegramBotApi $bot, TelegramLink $link, int|string $chatId, string $text): void
{
    $normalized = trim($text);

    if (in_array($normalized, ['إلغاء', 'الغاء', 'cancel'], true)) {
        $link->update(['pending_action' => null]);
        $bot->sendMessage($chatId, 'تم الإلغاء.');

        return;
    }

    $pending = $link->pending_action;

    if (($pending['action'] ?? null) !== 'contribute_pick' || ($pending['step'] ?? null) !== 'query') {
        $bot->sendMessage($chatId, 'استخدم الأزرار يلي فوق 🙂 أو اكتب "إلغاء" لإيقاف العملية.');

        return;
    }

    if (mb_strlen($normalized) < 2) {
        $bot->sendMessage($chatId, 'اكتب حرفين على الأقل 🙂');

        return;
    }

    $courses = Course::query()
        ->where('is_active', true)
        ->where(function ($query) use ($normalized) {
            $query->where('name_ar', 'like', "%{$normalized}%")
                ->orWhere('name_en', 'like', "%{$normalized}%")
                ->orWhere('code', 'like', "%{$normalized}%");
        })
        ->orderBy('name_ar')
        ->limit(8)
        ->get();

    if ($courses->isEmpty()) {
        $bot->sendMessage($chatId, '❌ ما لقيت مادة بهذا الاسم، جرّب اسم أو رمز مختلف (أو اكتب "إلغاء"):');

        return;
    }

    $keyboard = [];
    foreach ($courses as $course) {
        $label = trim(($course->code ? $course->code.' — ' : '').(string) ($course->name_ar ?: $course->name_en));
        $keyboard[] = [['text' => $label, 'callback_data' => 'contribute:pick:'.$course->key]];
    }
    $bot->sendMessage($chatId, '📚 اختر المادة يلي بدك تشارك إلها ملف/مصدر:', $keyboard);
}

}
