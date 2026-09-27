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
use App\Exceptions\CourseStatusException;
use App\Services\MyCourseStatusService;
use App\Services\PlanCalculator;
use App\Services\TelegramAiAssistant;
use App\Services\TelegramBotApi;
use App\Services\TelegramContentNotifier;
use App\Services\TelegramGpaCalculator;
use App\Support\PlanBulkLabel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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

    // ميزة جديدة (جلسة سادسة، جزء 6): "حاسبة الهندسة السريعة" — 7 أدوات
    // حساب تفاعلية (مقاومات/مكثفات، 555 Timer، استهلاك طاقة، أنظمة عددية
    // ومنطق، شبكات/IP، تعقيد زمني Big-O، أداء معالج) — منفصلة تمامًا عن
    // "🧰 الأدوات الهندسية" (كتالوج برامج/أدوات المساقات الموجود مسبقًا)
    // حتى ما يصير لبس بالاسمين. راجع calc: (سابقة الـcallback_data) و
    // calc_* (سابقة pending_action.action) بالأسفل.
    private const MAIN_MENU_CALC = '🧮 حاسبة الهندسة السريعة';

    // ميزتان جديدتان (جلسة سابعة، جزء 1) من الـ6 أفكار المقترحة سابقًا:
    // "🌐 التطبيق المصغّر" يفتح الموقع الحالي فعليًا بنافذة Web App
    // مدمجة جوّا تيليجرام (بدل بناء موقع مصغّر منفصل مكرِّر — قرار
    // المستخدم بعد نقاش التكلفة/الفائدة)، و"🙋 مساعدة الطلاب" نظام
    // أسئلة وأجوبة لكل مادة (بث فوري لطلاب المادة + أرشيف دائم قابل
    // للتصفح — دمج فكرتي "سؤال يُبث" و"دفتر أسئلة/أجوبة" اللي اقترحهم
    // المستخدم بنفس الوقت).
    private const MAIN_MENU_MINIAPP = '🌐 التطبيق المصغّر';
    private const MAIN_MENU_PEER_HELP = '🙋 مساعدة الطلاب';

    // ثلاث ميزات إضافية (جلسة سابعة، جزء 2) — آخر 3 أفكار من الـ6
    // المقترحة أصلًا، اشتُغلن أثناء انقطاع جهاز المستخدم (لابتوبه بلا
    // شاحن) بناءً على تفويضه المباشر "اشتغل وجهّزهم بالكامل":
    // - "📖 دليل Multisim" محتوى ثابت (لا AI ولا DB) عن برنامج NI
    //   Multisim، منفصل تمامًا عن سجل "NI Multisim" الموجود مسبقًا
    //   بكتالوج self::MAIN_MENU_TOOLS (Tool model) — هذا دليل استخدام
    //   تفاعلي، وذاك كتالوج/رابط تحميل فقط (الدليل يربط له بزر تحميل).
    // - "🧩 مولّد UML" يستخدم Gemini (عبر TelegramAiAssistant) لتوليد
    //   كود Mermaid classDiagram من وصف الطالب بالعربي، ثم يرندره صورة
    //   PNG عبر mermaid.ink العامة (بلا أي مكتبة رسم على السيرفر).
    // - "📅 رادار الفرص والفعاليات" (events: بالأسفل) — الكود والجدول
    //   جاهزان بالكامل (راجع App\Models\Event وhandleEventCallback/
    //   handleEventTextInput تحت)، لكن الميزة **معطّلة عمدًا مؤقتًا**
    //   بقرار المستخدم (رجّأها لجولة لاحقة) — زر القائمة الرئيسية
    //   ومطابقة النص أُزيلا حتى ما توصل لأي طالب فعليًا، وجدول
    //   `events` نفسه لسا ما اتشغّل بـphpMyAdmin (step96) أصلًا. لتفعيلها
    //   لاحقًا: رجّع صف MAIN_MENU_EVENTS بـMAIN_MENU_KEYBOARD تحت + كتلة
    //   المطابقة النصية بـ__invoke() (كانتا موجودتين بجلسة سابعة/جزء 2
    //   قبل التعطيل)، وشغّل step96_run_this_in_phpmyadmin.sql أولًا.
    private const MAIN_MENU_MULTISIM = '📖 دليل Multisim';
    private const MAIN_MENU_UML = '🧩 مولّد UML';

    /*
     * إعادة تنظيم القائمة الرئيسية بفئات (جلسة سابعة، جزء 3) — بعد ما
     * وصل عدد الأزرار الدائمة لـ16 زر (8 صفوف) صار الطالب يحتاج سكرول
     * طويل وممكن يفوته زر بالأسفل بلا ما ينتبه. الحل: قائمة رئيسية
     * مختصرة بـ4 فئات فقط + "❓ مساعدة" مباشرة (٣ صفوف بدل ٨)، وكل فئة
     * لما تُضغط بترجع "قائمة فرعية" حقيقية (ReplyKeyboardMarkup تاني،
     * عبر sendMessageWithMainMenu نفسها) فيها نفس أزرار self::MAIN_MENU_*
     * الأصلية بالضبط + زر "🔙 القائمة الرئيسية" بالأسفل — فكل منطق
     * المطابقة النصية الموجود أصلًا بالأسفل (self::MAIN_MENU_PLAN،
     * self::MAIN_MENU_GPA...) يبقى بلا أي تغيير، لأنه نفس الزر بنفس
     * النص بيوصل بس من كيبورد مختلف. راجع SUBMENU_* والمطابقة النصية
     * لأزرار الفئات/الرجوع بالأسفل.
     */
    private const MAIN_MENU_CAT_ACADEMIC = '📊 الأكاديمي';
    private const MAIN_MENU_CAT_COURSES = '📚 المساقات والمحتوى';
    private const MAIN_MENU_CAT_TOOLS = '🧠 أدوات وذكاء اصطناعي';
    private const MAIN_MENU_CAT_COMMUNITY = '🙋 مجتمع ودعم';
    private const MAIN_MENU_CAT_ADMIN = '🛠️ إدارة';
    private const MAIN_MENU_BACK = '🔙 القائمة الرئيسية';

    // "🔍 بحث" بقيت زر مباشر بالقائمة الرئيسية (بدل ما تُدفن جوّا فئة
    // "📚 المساقات والمحتوى") بناءً على طلب صريح من المستخدم — أسهل
    // وأضمن وصول، وصار البحث نفسه يغطي أزرار/ميزات البوت كمان لا بس
    // المساقات/المحتوى/الأدوات (راجع FEATURE_INDEX وsendSearchResults).
    private const MAIN_MENU_KEYBOARD = [
        [['text' => self::MAIN_MENU_CAT_ACADEMIC], ['text' => self::MAIN_MENU_CAT_COURSES]],
        [['text' => self::MAIN_MENU_CAT_TOOLS], ['text' => self::MAIN_MENU_CAT_COMMUNITY]],
        [['text' => self::MAIN_MENU_SEARCH], ['text' => self::MAIN_MENU_HELP]],
    ];

    private const SUBMENU_ACADEMIC = [
        [['text' => self::MAIN_MENU_PLAN], ['text' => self::MAIN_MENU_GPA], ['text' => self::MAIN_MENU_SCHEDULE]],
        [['text' => self::MAIN_MENU_BACK]],
    ];

    private const SUBMENU_COURSES = [
        [['text' => self::MAIN_MENU_COURSES], ['text' => self::MAIN_MENU_MY_COURSES]],
        [['text' => self::MAIN_MENU_FAVORITES]],
        [['text' => self::MAIN_MENU_BACK]],
    ];

    private const SUBMENU_AI_TOOLS = [
        [['text' => self::MAIN_MENU_TOOLS], ['text' => self::MAIN_MENU_CALC]],
        [['text' => self::MAIN_MENU_MULTISIM], ['text' => self::MAIN_MENU_UML]],
        [['text' => self::MAIN_MENU_BACK]],
    ];

    private const SUBMENU_COMMUNITY = [
        [['text' => self::MAIN_MENU_PEER_HELP], ['text' => self::MAIN_MENU_MINIAPP]],
        [['text' => self::MAIN_MENU_CONTACT], ['text' => self::MAIN_MENU_CONTRIBUTE]],
        [['text' => self::MAIN_MENU_BACK]],
    ];

    private const SUBMENU_ADMIN = [
        [['text' => self::MAIN_MENU_ADMIN_ANNOUNCE], ['text' => self::MAIN_MENU_ADMIN_TOOLS]],
        [['text' => self::MAIN_MENU_ADMIN_CONTENT], ['text' => self::MAIN_MENU_ADMIN_COURSES]],
        [['text' => self::MAIN_MENU_BACK]],
    ];

    /*
     * فهرس "بحث عن زر/ميزة" (جلسة سابعة، جزء 4) — طلب صريح من المستخدم:
     * "🔍 بحث" ما عاد يقتصر على مساقات/محتوى/أدوات (Course/CourseFile/
     * Tool بقاعدة البيانات)، صار يفتّش كمان بأسماء/مرادفات كل زر رئيسي
     * بالبوت نفسه، ويعرض نتيجة قابلة للضغط (inline) توصّل الطالب
     * مباشرة لنفس الميزة بلا ما يدوّر يدويًا بأي فئة. كل مفتاح هون
     * مطابق تمامًا لنفس المرادفات المستخدمة أصلًا بمطابقة النص بالأسفل
     * (self::MAIN_MENU_GPA => ['معدلي','المعدل','gpa']...) حتى ما يصير
     * فرق سلوك بين "اكتب الزر يدويًا" و"دوره بالبحث". مخطط callback_data:
     * navjump:{key} → handleNavJumpCallback() ينفّذ بالضبط نفس كود
     * كتلة المطابقة النصية لهذا الزر (بما فيها مسح pending_action لو
     * كانت الكتلة الأصلية تعمل هيك).
     */
    private const FEATURE_INDEX = [
        'plan' => ['label' => self::MAIN_MENU_PLAN, 'keywords' => ['خطتي', 'خطة', 'تخرج', 'ساعات معتمدة', 'plan']],
        'gpa' => ['label' => self::MAIN_MENU_GPA, 'keywords' => ['معدلي', 'المعدل', 'معدل', 'علامات', 'علامة', 'gpa']],
        'schedule' => ['label' => self::MAIN_MENU_SCHEDULE, 'keywords' => ['جدولي', 'جدول', 'محاضرات', 'محاضرة', 'تذكير', 'schedule']],
        'courses' => ['label' => self::MAIN_MENU_COURSES, 'keywords' => ['المساقات', 'مساقات', 'الخطة الدراسية', 'شجرة المساقات', 'courses']],
        'mycourses' => ['label' => self::MAIN_MENU_MY_COURSES, 'keywords' => ['مساقاتي', 'مساقاتي الحالية', 'mycourses']],
        'favorites' => ['label' => self::MAIN_MENU_FAVORITES, 'keywords' => ['مفضلاتي', 'المفضلة', 'favorites']],
        'tools' => ['label' => self::MAIN_MENU_TOOLS, 'keywords' => ['القائمة الذكية', 'ذكاء اصطناعي', 'مساعد أسئلة', 'ورشة أكواد', 'tools', 'ai']],
        'calc' => ['label' => self::MAIN_MENU_CALC, 'keywords' => ['حاسبة', 'حاسبة الهندسة', 'حاسبة المقاومات', 'calc']],
        'multisim' => ['label' => self::MAIN_MENU_MULTISIM, 'keywords' => ['multisim', 'ملتسيم', 'محاكي دوائر', 'دليل multisim']],
        'uml' => ['label' => self::MAIN_MENU_UML, 'keywords' => ['uml', 'مخطط', 'مولد uml', 'class diagram', 'مخطط اصناف']],
        'peerhelp' => ['label' => self::MAIN_MENU_PEER_HELP, 'keywords' => ['مساعدة الطلاب', 'اسأل زملائي', 'اسأل', 'qa']],
        'miniapp' => ['label' => self::MAIN_MENU_MINIAPP, 'keywords' => ['التطبيق المصغر', 'ميني اب', 'تطبيق مصغر', 'miniapp', 'app']],
        'contact' => ['label' => self::MAIN_MENU_CONTACT, 'keywords' => ['تواصل معنا', 'تواصل', 'دعم', 'شكوى', 'contact']],
        'contribute' => ['label' => self::MAIN_MENU_CONTRIBUTE, 'keywords' => ['شارك ملف', 'مشاركة ملف', 'رفع ملف', 'contribute']],
        'help' => ['label' => self::MAIN_MENU_HELP, 'keywords' => ['مساعدة', 'شرح البوت', 'أوامر', 'help']],
        'adminannounce' => ['label' => self::MAIN_MENU_ADMIN_ANNOUNCE, 'keywords' => ['نشر اعلان', 'اعلان', 'announce'], 'staffOnly' => true],
        'admintools' => ['label' => self::MAIN_MENU_ADMIN_TOOLS, 'keywords' => ['ادارة الادوات', 'admintools'], 'staffOnly' => true],
        'admincontent' => ['label' => self::MAIN_MENU_ADMIN_CONTENT, 'keywords' => ['ادارة المحتوى', 'admincontent'], 'staffOnly' => true],
        'admincourses' => ['label' => self::MAIN_MENU_ADMIN_COURSES, 'keywords' => ['ادارة المساقات', 'admincourses'], 'staffOnly' => true],
    ];

    // جداول ثوابت "حاسبة المقاومات والمكثفات" (كود ألوان المقاومات
    // القياسي) — key بالإنجليزي يُستخدم بـcallback_data، والتسمية
    // العربية + إيموجي اللون بـRC_COLOR_LABELS للعرض فقط.
    private const RC_DIGIT_COLORS = [
        'black' => 0, 'brown' => 1, 'red' => 2, 'orange' => 3, 'yellow' => 4,
        'green' => 5, 'blue' => 6, 'violet' => 7, 'gray' => 8, 'white' => 9,
    ];

    private const RC_MULTIPLIER_EXP = [
        'black' => 0, 'brown' => 1, 'red' => 2, 'orange' => 3, 'yellow' => 4,
        'green' => 5, 'blue' => 6, 'violet' => 7, 'gray' => 8, 'white' => 9,
        'gold' => -1, 'silver' => -2,
    ];

    private const RC_TOLERANCE_PCT = [
        'brown' => 1, 'red' => 2, 'green' => 0.5, 'blue' => 0.25,
        'violet' => 0.1, 'gray' => 0.05, 'gold' => 5, 'silver' => 10,
    ];

    private const RC_COLOR_LABELS = [
        'black' => '⚫ أسود', 'brown' => '🟤 بني', 'red' => '🔴 أحمر', 'orange' => '🟠 برتقالي',
        'yellow' => '🟡 أصفر', 'green' => '🟢 أخضر', 'blue' => '🔵 أزرق', 'violet' => '🟣 بنفسجي',
        'gray' => '⬛ رمادي', 'white' => '⬜ أبيض', 'gold' => '🥇 ذهبي', 'silver' => '🥈 فضي',
    ];

    // أزرار إضافية تظهر فقط لحسابات الإدارة (User::isStaff()) — راجع
    // buildMainMenuKeyboard().
    private const MAIN_MENU_ADMIN_ANNOUNCE = '📢 نشر إعلان';
    private const MAIN_MENU_ADMIN_TOOLS = '🧰 إدارة الأدوات';
    private const MAIN_MENU_ADMIN_CONTENT = '📚 إدارة المحتوى';
    private const MAIN_MENU_ADMIN_COURSES = '🎓 إدارة المساقات';

    /*
     * قائمة أوامر "/" الظاهرة بتيليجرام (زر جنب أيقونة السمايلات
     * بصندوق الكتابة — راجع TelegramBotApi::setMyCommands()) — تُسجَّل
     * تلقائيًا بكل /start (syncBotCommands() تحت)، بلا حاجة لأي أمر
     * artisan يدوي (الاستضافة بلا SSH أصلًا). أسماء الأوامر لازم تكون
     * لاتينية صغيرة بحكم قيد تيليجرام نفسه (^[a-z0-9_]{1,32}$)، لكن
     * الوصف عربي وحر تمامًا — وهو أيضًا نفس النص يلي يطابقه $normalized
     * بالأسفل، فكل أمر هون شغّال فعليًا لا مجرد واجهة (راجع كل alias
     * "لاتيني" بمطابقات in_array تحت — أُضيفت بنفس هالجولة).
     *
     * الترتيب هون مقصود لا عشوائي: يبدأ بالأكاديمي الشخصي (خطة/معدل/جدول)،
     * يمر بالتصفح والاستكشاف (مساقات/بحث)، فالذكاء الاصطناعي والمشاركة،
     * وينتهي بالتواصل والمساعدة — يعطي إحساس "قوائم منظمة" رغم إنه
     * تيليجرام نفسه ما بيدعم عناوين أقسام حقيقية بقائمة الأوامر.
     */
    private const DEFAULT_BOT_COMMANDS = [
        ['command' => 'start', 'description' => '🚀 ابدأ من هون أو اربط حسابك بالبوت'],
        ['command' => 'plan', 'description' => '📊 لقطة سريعة لمشوارك نحو التخرّج'],
        ['command' => 'gpa', 'description' => '🧮 معدّلك التراكمي بالتفصيل، وحدّثه من هون'],
        ['command' => 'schedule', 'description' => '📅 جدول محاضراتك + تذكير قبل كل وحدة'],
        ['command' => 'courses', 'description' => '📚 نزهة داخل الخطة الدراسية سنة سنة'],
        ['command' => 'mycourses', 'description' => '📖 مساقاتك المسجَّلة فعليًا هالفصل'],
        ['command' => 'favorites', 'description' => '⭐ كل ملف عجبك وحفظته، بمكان وحد'],
        ['command' => 'tools', 'description' => '🧪 معمل الذكاء الاصطناعي: أسئلة وأكواد واختبارات'],
        ['command' => 'search', 'description' => '🔍 دور بكلمة وحدة عن أي شي بالموقع'],
        ['command' => 'contribute', 'description' => '📤 عندك ملف يفيد زملاءك؟ شاركه بضغطة'],
        ['command' => 'contact', 'description' => '📨 وصلتك ملاحظة أو مشكلة؟ راسلنا مباشرة'],
        ['command' => 'help', 'description' => '❓ دليل استخدام البوت كامل، خطوة خطوة'],
    ];

    // تُضاف لقائمة الأعلى فقط بمحادثة حساب إدارة (User::isStaff()) —
    // عبر scope خاص بمحادثته وحدها (BotCommandScopeChat)، لا تظهر لأي طالب.
    private const STAFF_BOT_COMMANDS = [
        ['command' => 'announce', 'description' => '📢 انشر إعلانًا يوصل كل الطلاب فورًا'],
        ['command' => 'admintools', 'description' => '🧰 تحكّم بالأدوات الهندسية بالموقع'],
        ['command' => 'admincontent', 'description' => '📚 إدارة محتوى المساقات والملفات'],
        ['command' => 'admincourses', 'description' => '🎓 إدارة بيانات المساقات والخطة'],
    ];

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
        TelegramGpaCalculator $gpaCalculator,
        MyCourseStatusService $courseStatusService
    ) {
        $expectedSecret = (string) config('services.telegram.webhook_secret', '');

        if (
            $expectedSecret === ''
            || $request->header('X-Telegram-Bot-Api-Secret-Token') !== $expectedSecret
        ) {
            return response()->json(['ok' => false], 403);
        }

        /*
         * حارس تكرار — السبب الفعلي وراء شكوى الطاقم "بيرسل نفس رسالة
         * المراجعة وناتجها المبتور أكتر من ٦ مرات، وحتى بعد /start
         * جديدة": تيليجرام بيعتبر الـwebhook "فشل" لو ما استقبل ردًا
         * سريعًا، وبيعيد بعت **نفس التحديث** (نفس update_id) أكتر من
         * مرة — ومسار "ورشة الأكواد" (خصوصًا فحص/تحسين على ملف كبير)
         * بياخد لحد ١٥٠+ ثانية (نداءا Gemini)، أطول بكثير من مهلة رد
         * تيليجرام المعتادة. كل إعادة إرسال كانت تُعاد معالجتها من
         * الصفر بالكامل (رسالة "جارٍ المراجعة" + نداء Gemini جديد)،
         * فتظهر للطالب كأنها ستّ عمليات مختلفة رغم إنه بعت أمرًا وحدًا.
         * تجاهل أي update_id سبق معالجته خلال آخر ١٥ دقيقة يقطع هذا
         * التكرار نهائيًا بغض النظر عن سبب إعادة الإرسال (بطء، خطأ 5xx...).
         */
        $updateId = $request->input('update_id');

        if ($updateId !== null) {
            $dedupeKey = 'tg-webhook-update:' . $updateId;

            if (Cache::has($dedupeKey)) {
                return response()->json(['ok' => true]);
            }

            Cache::put($dedupeKey, true, now()->addMinutes(15));
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
            } elseif (str_starts_with($callbackData, 'quizloop:')) {
                $this->handleQuizLoopCallback($bot, $aiAssistant, $callbackQuery);
            } elseif (str_starts_with($callbackData, 'calc:')) {
                $this->handleCalcCallback($bot, $callbackQuery);
            } elseif (str_starts_with($callbackData, 'qa:')) {
                $this->handleQaCallback($bot, $callbackQuery);
            } elseif (str_starts_with($callbackData, 'ms:')) {
                $this->handleMultisimCallback($bot, $callbackQuery);
            } elseif (str_starts_with($callbackData, 'uml:')) {
                $this->handleUmlCallback($bot, $aiAssistant, $callbackQuery);
            } elseif (str_starts_with($callbackData, 'event:')) {
                $this->handleEventCallback($bot, $callbackQuery);
            } elseif (str_starts_with($callbackData, 'navjump:')) {
                $this->handleNavJumpCallback($bot, $planCalculator, $aiAssistant, $gpaCalculator, $callbackQuery);
            } elseif (str_starts_with($callbackData, 'plan:')) {
                $this->handlePlanCallback($bot, $planCalculator, $courseStatusService, $callbackQuery);
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
            $frontendUrl = rtrim((string) config('app.frontend_url'), '/');

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
                    $this->syncBotCommands($bot, $chatId, $existingLink->user);

                    $bot->sendMessageWithMainMenu(
                        $chatId,
                        'أهلًا فيك من جديد 👋 حسابك مربوط أصلًا — استخدم الأزرار تحت 👇 للمتابعة.',
                        $this->buildMainMenuKeyboard($existingLink->user)
                    );

                    return response()->json(['ok' => true]);
                }

                /*
                 * ⚠ رابط مباشر لمكان الربط بالموقع بدل وصف نصي فقط —
                 * بطلب صريح من المستخدم: أي طالب (مسجّل أو لأ) يضغط
                 * /start بلا حساب مربوط لازم يوصله رابط ينقله فورًا
                 * لنفس مكان أيقونة "اربط حسابي بتيليجرام" (بطاقة #tgLinkCard
                 * بصفحة إعدادات الحساب — نُقل إليها اختصار بارز من
                 * "صفحتي الشخصية" كمان). لو الطالب أصلًا مش مسجّل بالموقع،
                 * حارس الدخول بالصفحة نفسها (ptc-fast-auth-guard) بيحوّله
                 * لصفحة تسجيل الدخول تلقائيًا، ومنها لإنشاء حساب جديد.
                 */
                $bot->sendMessage(
                    $chatId,
                    'أهلًا 👋 لازم تربط حسابك أول شي.'."\n\n".
                    'اضغط هذا الرابط وسجّل دخولك (أو أنشئ حسابًا جديدًا لو ما عندك واحد بعد)، وهناك بتلاقي زر "اربط حسابي بتيليجرام":'."\n".
                    $frontendUrl.'/account.html#tgLinkCard'
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

            if ($link->user) {
                $this->syncBotCommands($bot, $chatId, $link->user);
            }

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
            // راجع تعليق مطابق بفرع "/start" فوق — نفس رابط مباشر لمكان الربط بالضبط.
            $bot->sendMessage(
                $chatId,
                'لسا ما ربطت حسابك 🙂'."\n\n".
                'اضغط هذا الرابط وسجّل دخولك (أو أنشئ حسابًا جديدًا لو ما عندك واحد بعد)، وهناك بتلاقي زر "اربط حسابي بتيليجرام":'."\n".
                rtrim((string) config('app.frontend_url'), '/').'/account.html#tgLinkCard'
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
                self::MAIN_MENU_CALC, self::MAIN_MENU_MINIAPP, self::MAIN_MENU_PEER_HELP,
                self::MAIN_MENU_MULTISIM, self::MAIN_MENU_UML,
                self::MAIN_MENU_CAT_ACADEMIC, self::MAIN_MENU_CAT_COURSES, self::MAIN_MENU_CAT_TOOLS,
                self::MAIN_MENU_CAT_COMMUNITY, self::MAIN_MENU_CAT_ADMIN, self::MAIN_MENU_BACK,
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
            } elseif ($pendingAction === 'quizloop') {
                $this->handleQuizLoopTextInput($bot, $aiAssistant, $link, $chatId, $text);
            } elseif (str_starts_with($pendingAction, 'calc_')) {
                $this->handleCalcTextInput($bot, $link, $chatId, $text);
            } elseif (str_starts_with($pendingAction, 'qa_')) {
                $this->handleQaTextInput($bot, $link, $chatId, $text);
            } elseif (str_starts_with($pendingAction, 'ms_')) {
                $this->handleMultisimTextInput($bot, $aiAssistant, $link, $chatId, $text);
            } elseif ($pendingAction === 'uml_describe') {
                $this->handleUmlTextInput($bot, $aiAssistant, $link, $chatId, $text);
            } elseif (str_starts_with($pendingAction, 'event_')) {
                $this->handleEventTextInput($bot, $link, $chatId, $text);
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

        /*
         * أزرار الفئات + "🔙 القائمة الرئيسية" (جلسة سابعة، جزء 3) —
         * راجع تعليق MAIN_MENU_CAT_ACADEMIC/SUBMENU_* فوق. كل فئة
         * بترجع قائمة فرعية حقيقية (ReplyKeyboardMarkup) عبر نفس
         * sendMessageWithMainMenu المستخدمة أصلًا للقائمة الرئيسية —
         * الأزرار جواها نفس self::MAIN_MENU_* الأصلية بالضبط، فمطابقتها
         * بالأسفل (PLAN/GPA/...) بتشتغل عادي بلا أي تعديل عليها.
         */
        if (in_array($normalized, [self::MAIN_MENU_CAT_ACADEMIC], true)) {
            $bot->sendMessageWithMainMenu($chatId, '📊 <b>الأكاديمي</b> — اختر:', self::SUBMENU_ACADEMIC);

            return response()->json(['ok' => true]);
        }

        if (in_array($normalized, [self::MAIN_MENU_CAT_COURSES], true)) {
            $bot->sendMessageWithMainMenu($chatId, '📚 <b>المساقات والمحتوى</b> — اختر:', self::SUBMENU_COURSES);

            return response()->json(['ok' => true]);
        }

        if (in_array($normalized, [self::MAIN_MENU_CAT_TOOLS], true)) {
            $bot->sendMessageWithMainMenu($chatId, '🧠 <b>أدوات وذكاء اصطناعي</b> — اختر:', self::SUBMENU_AI_TOOLS);

            return response()->json(['ok' => true]);
        }

        if (in_array($normalized, [self::MAIN_MENU_CAT_COMMUNITY], true)) {
            $bot->sendMessageWithMainMenu($chatId, '🙋 <b>مجتمع ودعم</b> — اختر:', self::SUBMENU_COMMUNITY);

            return response()->json(['ok' => true]);
        }

        if (in_array($normalized, [self::MAIN_MENU_CAT_ADMIN], true)) {
            if (! $link->user->isStaff()) {
                // ما المفروض يوصل هون أصلًا (الزر ما بيظهر إلا لحسابات الطاقم) — تجاهل صامت لأي محاولة يدوية.
                return response()->json(['ok' => true]);
            }

            $bot->sendMessageWithMainMenu($chatId, '🛠️ <b>إدارة</b> — اختر:', self::SUBMENU_ADMIN);

            return response()->json(['ok' => true]);
        }

        if (in_array($normalized, [self::MAIN_MENU_BACK, 'رجوع', 'القائمة الرئيسية', 'back'], true)) {
            $bot->sendMessageWithMainMenu($chatId, '🏠 القائمة الرئيسية:', $this->buildMainMenuKeyboard($link->user));

            return response()->json(['ok' => true]);
        }

        if (in_array($normalized, [self::MAIN_MENU_PLAN, 'خطتي', 'plan'], true)) {
            $this->replyWithPlanSummary($bot, $chatId, $link->user, $planCalculator);

            return response()->json(['ok' => true]);
        }

        if (in_array($normalized, [self::MAIN_MENU_GPA, 'معدلي', 'المعدل', 'gpa'], true)) {
            $this->replyWithGpaSummary($bot, $chatId, $link->user, $gpaCalculator);

            return response()->json(['ok' => true]);
        }

        if (in_array($normalized, [self::MAIN_MENU_HELP, 'مساعدة', 'help', 'أوامر'], true)) {
            $this->sendHelpText($bot, $chatId, $link->user);

            return response()->json(['ok' => true]);
        }

        if (in_array($normalized, [self::MAIN_MENU_TOOLS, 'القائمة', 'menu', 'قائمة', 'tools'], true)) {
            $this->sendMenu($bot, $chatId, $link->currentMode());

            return response()->json(['ok' => true]);
        }

        if (in_array($normalized, [self::MAIN_MENU_CALC, 'حاسبة', 'حاسبة الهندسة', 'calc'], true)) {
            $link->update(['pending_action' => null]);
            $this->sendCalcMainMenu($bot, $chatId);

            return response()->json(['ok' => true]);
        }

        if (in_array($normalized, [self::MAIN_MENU_MINIAPP, 'التطبيق المصغر', 'miniapp', 'app'], true)) {
            $this->sendMiniAppCard($bot, $chatId);

            return response()->json(['ok' => true]);
        }

        if (in_array($normalized, [self::MAIN_MENU_PEER_HELP, 'مساعدة الطلاب', 'اسأل', 'qa'], true)) {
            $link->update(['pending_action' => null]);
            $this->sendQaMainMenu($bot, $chatId);

            return response()->json(['ok' => true]);
        }

        if (in_array($normalized, [self::MAIN_MENU_MULTISIM, 'دليل Multisim', 'Multisim', 'multisim'], true)) {
            $link->update(['pending_action' => null]);
            $this->sendMultisimMenu($bot, $chatId);

            return response()->json(['ok' => true]);
        }

        if (in_array($normalized, [self::MAIN_MENU_UML, 'مولد UML', 'UML', 'uml'], true)) {
            $this->sendUmlIntro($bot, $chatId, $link);

            return response()->json(['ok' => true]);
        }

        // "📅 رادار الفرص والفعاليات" معطّلة مؤقتًا بقرار المستخدم — راجع
        // تعليق MAIN_MENU_MULTISIM/MAIN_MENU_UML فوق لطريقة إعادة تفعيلها.

        if (in_array($normalized, [self::MAIN_MENU_SCHEDULE, 'جدولي', 'جدول', 'الجدول', 'schedule'], true)) {
            $this->replyWithScheduleSummary($bot, $chatId, $link);

            return response()->json(['ok' => true]);
        }

        if (in_array($normalized, [self::MAIN_MENU_COURSES, 'المساقات', 'مساقات', 'courses'], true)) {
            $this->sendCourseHubYearPicker($bot, $chatId);

            return response()->json(['ok' => true]);
        }

        /*
         * "📖 مساقاتي الحالية" — مساقات الطالب المسجَّلة فعليًا
         * (my_courses.status = registered)، نفس الجدول الذي تقرأ/تكتب
         * منه الصفحة الشخصية بالموقع (MyCourseController) — إضافة/حذف
         * من هون تنعكس مباشرة هناك وبالعكس.
         */
        if (in_array($normalized, [self::MAIN_MENU_MY_COURSES, 'مساقاتي', 'mycourses'], true)) {
            $this->sendMyCoursesList($bot, $chatId, $link->user);

            return response()->json(['ok' => true]);
        }

        /*
         * "⭐ مفضلاتي" — زر رئيسي مستقل (كان قبل هيك زر ثانوي مدفون
         * تحت "المساقات" فقط، فلم يلاحظه الطاقم بالتجربة الأولى).
         */
        if (in_array($normalized, [self::MAIN_MENU_FAVORITES, 'مفضلاتي', 'المفضلة', 'favorites'], true)) {
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
        if (in_array($normalized, [self::MAIN_MENU_CONTACT, 'تواصل معنا', 'تواصل', 'contact'], true)) {
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
        if (in_array($normalized, [self::MAIN_MENU_CONTRIBUTE, 'شارك ملف', 'مشاركة ملف', 'contribute'], true)) {
            $this->startContributeFlow($bot, $link, $chatId);

            return response()->json(['ok' => true]);
        }

        if (in_array($normalized, [self::MAIN_MENU_SEARCH, 'بحث', 'search'], true)) {
            $link->update(['pending_action' => ['action' => 'search', 'step' => 'query', 'lecture_id' => null, 'data' => []]]);
            $bot->sendMessage($chatId, '🔍 اكتب كلمة تدور عليها — مادة، ملف، أداة، أو حتى اسم أي زر/ميزة بالبوت (حرفين على الأقل):');

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
        if (in_array($normalized, [self::MAIN_MENU_ADMIN_ANNOUNCE, 'announce'], true)) {
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
        if (in_array($normalized, [self::MAIN_MENU_ADMIN_TOOLS, 'admintools'], true)) {
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
        if (in_array($normalized, [self::MAIN_MENU_ADMIN_CONTENT, 'admincontent'], true)) {
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
        if (in_array($normalized, [self::MAIN_MENU_ADMIN_COURSES, 'admincourses'], true)) {
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
     * نص "❓ مساعدة" — مستخرج بدالة مستقلة (جلسة سابعة، جزء 4) حتى
     * يقدر "نتيجة بحث → 🧭 مساعدة" (navjump:help) يستدعيه بلا تكرار
     * نفس النص الطويل بمكانين.
     */
    private function sendHelpText(TelegramBotApi $bot, int|string $chatId, \App\Models\User $user): void
    {
        $bot->sendMessageWithMainMenu(
            $chatId,
            "🧭 <b>دليلك باستخدام البوت</b> (نسخة تجريبية، رح تكبر تدريجيًا)\n\n".
            "الأزرار الدائمة تحت مربع الكتابة صارت مقسومة لفئات حتى ما تحتاج سكرول طويل — اضغط فئة لتشوف أزرارها، واستخدم \"🔙 القائمة الرئيسية\" للرجوع منها بأي وقت:\n\n".
            "📊 <b>الأكاديمي</b> — خطتي (تقدّمك نحو التخرّج)، معدلي (معدّلك التراكمي بالتفصيل + تسجيل/تعديل/حذف علامة مادة ومحاكي \"ماذا لو؟\")، جدولي (جدول محاضراتك + تذكير تلقائي قبل كل محاضرة).\n".
            "📚 <b>المساقات والمحتوى</b> — المساقات (تصفّح الخطة سنة/فصل، أو 🌳 شجرة المساقات الكاملة، وتفاصيل أي مادة)، مساقاتي الحالية، مفضلاتي (كل ملف حفظته).\n".
            "🧠 <b>أدوات وذكاء اصطناعي</b> — القائمة الذكية (مساعد أسئلة، ورشة أكواد، مولّد أسئلة، تلخيص ملفات)، حاسبة الهندسة السريعة، دليل Multisim، مولّد UML.\n".
            "🙋 <b>مجتمع ودعم</b> — مساعدة الطلاب (اسأل زملاءك أو ساعدهم)، التطبيق المصغّر (الموقع كامل جوّا تيليجرام)، تواصل معنا، شارك ملف/مصدر.\n".
            "🔍 <b>بحث</b> — زر مباشر بالقائمة الرئيسية (مش محتاج تفتح فئة أول): دور بكلمة وحدة عن مادة، ملف، أداة، أو حتى اسم أي زر/ميزة بالبوت نفسه (مثلًا اكتب \"معدل\" أو \"UML\") وبنوصّلك له بضغطة وحدة.\n".
            "📷 ابعتلي صورة صفحة أو ملف PDF — رح ألخّصلك محتواها (بأي وضع).\n".
            "💬 اكتب أي سؤال أو كود أو موضوع عادي — رح يردّ حسب الأداة المختارة حاليًا بـ\"🧠 أدوات وذكاء اصطناعي\".\n".
            "🔎 بأي محادثة تيليجرام (حتى مجموعات الدراسة)، اكتب @".config('services.telegram.bot_username', 'اسم_البوت')." متبوعًا باسم مادة/أداة لتشاركها بضغطة وحدة، بدون فتح البوت.\n".
            "❓ مساعدة — هاي القائمة.",
            $this->buildMainMenuKeyboard($user)
        );
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

        /*
         * "📝 مولّد أسئلة" لم يعد يرمي دفعة ٥ أسئلة ثابتة — صار مدارًا
         * مستمرًا (سؤال بعد سؤال بلا حد مسبق) يوقفه الطالب بنفسه، بربط
         * مباشر بمواد الخطة الفعلية بدل قائمة مواضيع عامة جاهزة. راجع
         * startQuizPick()/sendQuizQuestionCard() تحت.
         */
        if ($mode === 'quiz') {
            $this->startQuizPick($bot, $link, $chatId);

            return;
        }

        $confirmations = [
            'chat' => "💬 <b>غرفة الأسئلة الأكاديمية</b>\n\n".
                "اسأل بأي مجال بتخصصك — من دارة منطقية لغاية بنية بيانات — وبوصلك جواب واضح ومباشر.\n\n".
                '✍️ اكتب سؤالك الآن، أو ابعت صورة/PDF ورح يتلخّص لك مباشرة بغض النظر عن المحطة الحالية.',
            'summarize' => "📄 <b>ملخّص بضغطة</b>\n\n".
                'ابعتلي صورة صفحة أو ملف PDF، وبرجّعلك خلاصة نقاطها الأساسية جاهزة للمذاكرة — هاي شغّالة دايمًا بغض النظر عن المحطة المختارة.',
        ];

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
     * ============================================================
     * "📝 مولّد أسئلة" — المدار المستمر: سؤال واحد بكل مرة، بلا عدد
     * محدَّد مسبقًا، يستمر لحد ما الطالب نفسه يضغط "إنهاء الاختبار".
     * الموضوع يُشتق من مادة فعلية بالخطة (بحث بالاسم/الرمز) أو أي نص
     * حر يكتبه الطالب — لا قائمة مواضيع عامة جاهزة. حالة الجولة
     * (الموضوع، الأسئلة السابقة لتفادي التكرار، النتيجة) محفوظة كاملة
     * بـpending_action['data'] طول الجولة، وتُمسح فقط عند "إنهاء".
     * ============================================================
     */
    private function startQuizPick(TelegramBotApi $bot, TelegramLink $link, int|string $chatId): void
    {
        $link->update(['pending_action' => ['action' => 'quizloop', 'step' => 'pick', 'lecture_id' => null, 'data' => []]]);

        $bot->sendMessage(
            $chatId,
            "🧭 <b>مدار الأسئلة</b>\n\n".
            "بيولّدلك سؤال اختيار من متعدد بكل مرة، ويكمل معك سؤال بعد سؤال بلا أسئلة مكرَّرة، لحد ما تقرر توقّفه بنفسك.\n\n".
            "📘 اكتب اسم مادة أو رمزها من خطتك (زي \"شبكات\" أو \"CMP0210\") وبنربطلك الأسئلة فيها،\n".
            'أو ✍️ اكتب أي موضوع دراسي حر مباشرة وبنبلش فيه فورًا.'
        );
    }

    private function sendQuizQuestionCard(TelegramBotApi $bot, int|string $chatId, string $topic, array $question, int $questionNumber, int $correct, int $total): void
    {
        $safeTopic = TelegramBotApi::escapeHtml($topic);
        $safeQuestion = TelegramBotApi::escapeHtml($question['question']);

        $lines = [
            "🧭 <b>مدار الأسئلة</b> — {$safeTopic}",
            "سؤال رقم {$questionNumber}" . ($total > 0 ? " · رصيدك الحالي: {$correct} من {$total}" : ''),
            '',
            "❓ {$safeQuestion}",
            '',
        ];

        foreach ($question['options'] as $letter => $optionText) {
            $lines[] = "{$letter}) " . TelegramBotApi::escapeHtml($optionText);
        }

        $keyboard = [
            [
                ['text' => 'اخترت أ', 'callback_data' => 'quizloop:answer:أ'],
                ['text' => 'اخترت ب', 'callback_data' => 'quizloop:answer:ب'],
            ],
            [
                ['text' => 'اخترت ج', 'callback_data' => 'quizloop:answer:ج'],
                ['text' => 'اخترت د', 'callback_data' => 'quizloop:answer:د'],
            ],
            [['text' => '🏁 إنهاء الاختبار', 'callback_data' => 'quizloop:end']],
        ];

        $bot->sendMessage($chatId, implode("\n", $lines), $keyboard);
    }

    /*
     * يبحث عن مادة مطابقة بالاسم/الرمز؛ ولو ما لقى شي بيعتبر النص
     * نفسه موضوعًا حرًا ويبلّش فيه مباشرة — بلا خطوة تأكيد إضافية،
     * حتى يبقى البدء بضغطة/كتابة وحدة بس.
     */
    private function resolveQuizTopicAndStart(TelegramBotApi $bot, TelegramAiAssistant $aiAssistant, TelegramLink $link, int|string $chatId, string $text): void
    {
        $normalized = trim($text);

        if ($normalized === '') {
            $bot->sendMessage($chatId, 'اكتب اسم مادة أو موضوعًا دراسيًا 🙂');

            return;
        }

        if (mb_strlen($normalized) >= 2) {
            $course = Course::query()
                ->where('is_active', true)
                ->where(function ($query) use ($normalized) {
                    $query->where('name_ar', 'like', "%{$normalized}%")
                        ->orWhere('name_en', 'like', "%{$normalized}%")
                        ->orWhere('code', 'like', "%{$normalized}%");
                })
                ->orderBy('name_ar')
                ->first();

            if ($course) {
                $this->startQuizLoop($bot, $aiAssistant, $link, $chatId, (string) ($course->name_ar ?: $course->name_en ?: $course->code));

                return;
            }
        }

        $this->startQuizLoop($bot, $aiAssistant, $link, $chatId, $normalized);
    }

    /*
     * ⚠ عمدًا بلا فحص remainingToday() هون — "مدار الأسئلة" مفتوح بلا حد
     * يومي بطلب صريح من المستخدم (راجع تعليق generateQuizQuestion
     * بـTelegramAiAssistant للتفاصيل الكاملة).
     */
    private function startQuizLoop(TelegramBotApi $bot, TelegramAiAssistant $aiAssistant, TelegramLink $link, int|string $chatId, string $topic): void
    {
        try {
            $question = $aiAssistant->generateQuizQuestion($link->user, $topic, []);
        } catch (\Throwable $error) {
            $friendly = $error instanceof \RuntimeException ? $error->getMessage() : 'تعذّر توليد سؤال الآن، جرّب مرة أخرى.';

            if (! $error instanceof \RuntimeException) {
                report($error);
            }

            $link->update(['pending_action' => null]);
            $bot->sendMessage($chatId, "⚠️ {$friendly}");

            return;
        }

        $link->update(['pending_action' => [
            'action' => 'quizloop',
            'step' => 'active',
            'lecture_id' => null,
            'data' => [
                'topic' => $topic,
                'asked' => [$question['question']],
                'current' => $question,
                'correct' => 0,
                'total' => 0,
            ],
        ]]);

        $this->sendQuizQuestionCard($bot, $chatId, $topic, $question, 1, 0, 0);
    }

    private function handleQuizLoopTextInput(TelegramBotApi $bot, TelegramAiAssistant $aiAssistant, TelegramLink $link, int|string $chatId, string $text): void
    {
        $normalized = trim($text);

        if (in_array($normalized, ['إلغاء', 'الغاء', 'cancel'], true)) {
            $link->update(['pending_action' => null]);
            $bot->sendMessage($chatId, 'تم إلغاء الاختبار.');

            return;
        }

        $pending = $link->pending_action;
        $step = $pending['step'] ?? null;

        if ($step === 'pick') {
            $this->resolveQuizTopicAndStart($bot, $aiAssistant, $link, $chatId, $normalized);

            return;
        }

        // step === 'active': التفاعل هون بالأزرار فقط — راجع handleQuizLoopCallback.
        $bot->sendMessage($chatId, 'جاوب بالأزرار يلي فوق 👆 أو اضغط "🏁 إنهاء الاختبار"، أو اكتب "إلغاء".');
    }

    private function handleQuizLoopCallback(TelegramBotApi $bot, TelegramAiAssistant $aiAssistant, array $callbackQuery): void
    {
        $callbackId = (string) ($callbackQuery['id'] ?? '');
        $chatId = $callbackQuery['message']['chat']['id'] ?? null;
        $data = (string) ($callbackQuery['data'] ?? '');
        $rest = substr($data, strlen('quizloop:'));

        /*
         * الخلل الفعلي وراء "بيضغط ما بيصير اشي": أزرار الإجابة مُرسَلة
         * بصيغة "quizloop:answer:أ" (راجع sendQuizQuestionCard)، لكن
         * $rest بعد تجريد "quizloop:" فقط كانت تضل "answer:أ" — وهاي ما
         * بتطابق أبدًا ['أ','ب','ج','د'] تحت، فيسقط بصمت بفرع "لا شيء
         * مطابق" (answerCallbackQuery بس بلا أي رسالة). زر "إنهاء
         * الاختبار" (quizloop:end، بلا مقطع "answer:") ما كان فيه هاد
         * الخلل — لهيك بينحل هو وحده وبيفشل التفاعل بالإجابات فقط.
         */
        if (str_starts_with($rest, 'answer:')) {
            $rest = substr($rest, strlen('answer:'));
        }

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

        if (($pending['action'] ?? null) !== 'quizloop' || ($pending['step'] ?? null) !== 'active') {
            $bot->answerCallbackQuery($callbackId);

            return;
        }

        $data2 = $pending['data'] ?? [];
        $current = $data2['current'] ?? null;

        if ($rest === 'end') {
            $bot->answerCallbackQuery($callbackId);
            $link->update(['pending_action' => null]);

            $correct = (int) ($data2['correct'] ?? 0);
            $total = (int) ($data2['total'] ?? 0);

            $summary = $total > 0
                ? "🏁 <b>خلصت الجولة!</b>\n\nأجبت صح على {$correct} من {$total} سؤال."
                : "🏁 <b>خلصت الجولة</b> بلا ما تجاوب على أي سؤال بعد.";

            $bot->sendMessage($chatId, $summary, [
                [['text' => '🔁 مدار جديد', 'callback_data' => 'toolsnav:quiznew']],
                [['text' => '🏠 القائمة الرئيسية', 'callback_data' => 'toolsnav:home']],
            ]);

            return;
        }

        if (! in_array($rest, ['أ', 'ب', 'ج', 'د'], true) || ! is_array($current)) {
            $bot->answerCallbackQuery($callbackId);

            return;
        }

        $bot->answerCallbackQuery($callbackId);

        $isCorrect = $rest === $current['correct'];
        $correct = (int) ($data2['correct'] ?? 0) + ($isCorrect ? 1 : 0);
        $total = (int) ($data2['total'] ?? 0) + 1;

        $correctText = TelegramBotApi::escapeHtml($current['correct'] . ') ' . $current['options'][$current['correct']]);
        $feedback = $isCorrect
            ? "✅ إجابة صحيحة! ({$correct}/{$total})"
            : "❌ مش هي — الصحيحة كانت {$correctText}. ({$correct}/{$total})";

        $bot->sendMessage($chatId, $feedback);

        $topic = (string) ($data2['topic'] ?? '');

        try {
            $nextQuestion = $aiAssistant->generateQuizQuestion($link->user, $topic, $data2['asked'] ?? []);
        } catch (\Throwable $error) {
            $friendly = $error instanceof \RuntimeException ? $error->getMessage() : 'تعذّر توليد السؤال التالي، جرّب "🔁 مدار جديد" بعد شوي.';

            if (! $error instanceof \RuntimeException) {
                report($error);
            }

            $link->update(['pending_action' => null]);
            $bot->sendMessage($chatId, "⚠️ {$friendly}\n\nنتيجتك النهائية: {$correct} من {$total}.");

            return;
        }

        $asked = $data2['asked'] ?? [];
        $asked[] = $nextQuestion['question'];

        $link->update(['pending_action' => [
            'action' => 'quizloop',
            'step' => 'active',
            'lecture_id' => null,
            'data' => [
                'topic' => $topic,
                'asked' => $asked,
                'current' => $nextQuestion,
                'correct' => $correct,
                'total' => $total,
            ],
        ]]);

        $this->sendQuizQuestionCard($bot, $chatId, $topic, $nextQuestion, $total + 1, $correct, $total);
    }

    /*
     * ⚠ أثر ما قبل "مدار الأسئلة" — دفعة الخمسة أسئلة الثابتة القديمة
     * (self::QUIZ_SUBJECTS / generateQuiz()) ما عاد أي زر يستدعيها، لكن
     * بقيت هون بلا حذف تفاديًا لأي مسار قديم متبقٍّ يعتمد عليها.
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

        // ⚠ عمدًا بلا فحص remainingToday() هون — "مولّد أسئلة" مفتوح بلا حد يومي (راجع تعليق generateQuiz).
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

        /*
         * فحص/تحسين ينفّذان نداءي Gemini على الأقل (ملاحظات + تعديلات)،
         * والثاني قد يتكرر تلقائيًا حتى 3 مرات لو الرد انقطع بحد الطول
         * (continueOnTruncation بـcallGemini) — يعني حتى 4 نداءات شبكة
         * بمجموعها بأسوأ الحالات (~45 + 4×60 ثانية). سقف أعلى هون
         * احترازي فقط — لا يجبر الطلب يطول، بس يمنع قتله باكرًا؛ آمِن
         * الآن أكتر من قبل بما إنه اتصال الويبهوك نفسه يُقفَل فورًا
         * (fastcgi_finish_request تحت) فوقت المعالجة الإضافي ما بيأثر
         * على تسليم الرد لتيليجرام.
         */
        @set_time_limit(300);

        $user = $link->user;

        /*
         * ⚠ الحد اليومي المشترك صار مقتصرًا على "ورشة الأكواد" فقط هون
         * (تلخيص الملفات له فحصه المستقل بـreplyWithFileSummary) — لهيك
         * الفحص تحت داخل فرع DEBUG_ACTION_KEYS حصرًا، لا قبل الـtry
         * عمومًا كما كان سابقًا. "مساعد أسئلة عام" و"مولّد أسئلة" (مود
         * quiz القديم) بلا حد يومي إطلاقًا (راجع تعليق askText/generateQuiz
         * بـTelegramAiAssistant).
         */
        try {
            if (in_array($mode, self::DEBUG_ACTION_KEYS, true)) {
                if ($aiAssistant->remainingToday($user) <= 0) {
                    $bot->sendMessage(
                        $chatId,
                        'وصلت الحد الأقصى للأسئلة اليوم (' . \App\Http\Controllers\Api\V1\AiAssistantController::DAILY_LIMIT . '). سيتجدّد تلقائيًا الساعة ١٢ منتصف الليل.'
                    );

                    return;
                }

                // راجع تعليق fastcgi_finish_request المطابق بـreplyWithCodeFileDebug.
                if (function_exists('fastcgi_finish_request')) {
                    if (! headers_sent()) {
                        http_response_code(200);
                        header('Content-Type: application/json');
                    }

                    echo json_encode(['ok' => true]);
                    fastcgi_finish_request();
                }

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
        // راجع تعليق سقف الوقت المطابق بـrouteFreeTextToAssistant أعلاه.
        @set_time_limit(300);

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

        /*
         * إغلاق اتصال الـwebhook مع تيليجرام هون فورًا — لا بعد ما نخلّص
         * (وهاد هو السبب الجذري الثاني وراء "بيرسل ٦ مرات": حتى مع
         * حارس تكرار update_id فوق بـ__invoke، سيرفر الاستضافة أو
         * تيليجرام نفسه ممكن يعتبر الاتصال "معلّق" ويقطعه/يعيد الإرسال
         * لو ضلّينا ممسكين فيه ١٥٠+ ثانية لحد ما نداءا Gemini يخلّصوا).
         * fastcgi_finish_request() بيسكّر الرد لتيليجرام حالًا (بيوصله
         * "200 ok" بثواني)، والسكربت نفسه بيكمل شغل بالخلفية عادي —
         * ورسائل $bot->sendMessage/sendDocument بعدين نداءات شبكة
         * منفصلة تمامًا عن هيك اتصال، فبتوصل الطالب طبيعي.
         */
        if (function_exists('fastcgi_finish_request')) {
            if (! headers_sent()) {
                http_response_code(200);
                header('Content-Type: application/json');
            }

            echo json_encode(['ok' => true]);
            fastcgi_finish_request();
        }

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
     * "تقرير المراجعة الذكية" — بدل الرجوع لملف كامل بلا أي إشارة لوين
     * تغيّر شي بالضبط (النسخة القديمة)، صار Gemini يرجّع قائمة
     * "تعديلات" (مقطع قبل/بعد + سبب — راجع TelegramAiAssistant::debugCode/
     * optimizeCode/applyCodeEdits) والبوت يبني منها تقريرًا مرقّمًا
     * يوريه الطالب بالضبط شو تغيّر ووين ولِيش، قبل ما يستلم الملف
     * النهائي كاملًا. هذا كمان يفيد الطالب تعليميًا لا بس تصليحيًا:
     * كل تعديل مشروح لحاله زي مراجعة كود حقيقية بين مبرمجين.
     *
     * status لكل تعديل (راجع applyCodeEdits بالتفصيل): applied (تطابق
     * مؤكَّد) | applied_fuzzy (تطابق بعد تجاهل فروقات مسافات) |
     * ambiguous (طُبِّق على أول تطابق من عدة، يستاهل مراجعة الطالب) |
     * failed (تعذّر تحديد مكانه تلقائيًا — يُعرض للطالب ليطبّقه يدويًا،
     * ولا يُطبَّق بالملف حتى ما نخاطر بمكان غلط).
     *
     * @param array{notes: string, fixed_code: string, edits: array<int, array{reason: string, old: string, new: string, status: string}>, no_changes: bool} $result
     */
    private function sendDebugResult(
        TelegramBotApi $bot,
        int|string $chatId,
        array $result,
        string $filename,
        string $titleLine = '🛠️ <b>ملاحظات الفحص</b>',
        string $fileCaption = '📄 الكود بعد المراجعة',
        string $appliedIcon = '✅'
    ): void {
        $notes = trim($result['notes']);
        $safeNotes = $notes !== '' ? TelegramBotApi::escapeHtml($notes) : 'ما في ملاحظات إضافية.';
        $bot->sendMessage($chatId, "{$titleLine}\n\n{$safeNotes}");

        if ($result['no_changes'] ?? false) {
            $bot->sendMessage($chatId, '✨ الكود سليم كما هو — ما احتاج أي تعديل فعلي.');

            return;
        }

        $edits = $result['edits'] ?? [];

        if ($edits !== []) {
            $this->sendCodeEditsReport($bot, $chatId, $edits, $appliedIcon);
        }

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
     * يبني تقرير التعديلات المرقّم ويرسله مجزَّءًا لرسائل ≤3500 حرف
     * (بلا تقطيع أي تعديل لنصفين بين رسالتين) — عدد التعديلات غير
     * محدود سلفًا (ملف فيه عشرات الأخطاء ممكن يعطي عشرات التعديلات).
     */
    private function sendCodeEditsReport(TelegramBotApi $bot, int|string $chatId, array $edits, string $appliedIcon): void
    {
        $statusIcon = [
            'applied' => $appliedIcon,
            'applied_fuzzy' => $appliedIcon,
            'ambiguous' => '⚠️',
            'failed' => '❌',
        ];

        $statusNote = [
            'applied' => '',
            'applied_fuzzy' => ' <i>(تطابق تقريبي بالمسافات — تأكد منه بالملف)</i>',
            'ambiguous' => ' <i>(طُبِّق على أول موضع مشابه — راجعه بالملف)</i>',
            'failed' => ' <i>— تعذّر تحديد مكانه تلقائيًا، طبّقه يدويًا من هون</i>',
        ];

        $count = count($edits);
        $blocks = ["🧬 <b>تقرير المراجعة الذكية</b> — {$count} " . ($count === 1 ? 'تعديل' : 'تعديلات')];

        foreach ($edits as $index => $edit) {
            $num = $index + 1;
            $status = $edit['status'];
            $icon = $statusIcon[$status] ?? '•';
            $note = $statusNote[$status] ?? '';
            $reasonText = trim($edit['reason']) !== '' ? $edit['reason'] : 'تحسين بلا وصف';
            $reason = TelegramBotApi::escapeHtml($reasonText);
            $before = TelegramBotApi::escapeHtml($this->truncateForPreview($edit['old']));
            $after = TelegramBotApi::escapeHtml($this->truncateForPreview($edit['new']));

            $blocks[] = "🔧 <b>تعديل #{$num}</b> {$icon} {$reason}{$note}\n" .
                "🔻 قبل:\n<code>{$before}</code>\n" .
                "🔺 بعد:\n<code>{$after}</code>";
        }

        /*
         * ⚠ sendChunkedMessage() الموجودة أصلًا بالملف (لقسم محتوى
         * المادة بالضبط) تعمل بنفس الفكرة تمامًا (تجميع "أسطر" برسائل
         * ≤٣٥٠٠ حرف بلا تقطيع سطر لنصفين) فأعدنا استخدامها هون بدل
         * تكرارها — كل عنصر بـ$blocks هون "سطر" منطقي (تعديل كامل
         * بأسطره الداخلية)، فبيتعامل معه صح.
         */
        $this->sendChunkedMessage($bot, $chatId, $blocks);
    }

    /*
     * معاينة مختصرة لمقطع كود بتقرير التعديلات — القيمة الكاملة أصلًا
     * مطبَّقة بالملف النهائي، فهون بس للقراءة السريعة لا كمرجع دقيق.
     */
    private function truncateForPreview(string $text, int $limit = 300): string
    {
        $trimmed = trim($text);

        if (mb_strlen($trimmed) <= $limit) {
            return $trimmed;
        }

        return mb_substr($trimmed, 0, $limit) . ' …';
    }

    /*
     * نقطة دخول موحّدة لثلاث محطات "ورشة الأكواد" — تستقبل الكود مرة
     * وحدة وتوزّعه حسب المحطة المختارة حاليًا (mode) على الدالة
     * المناسبة بـTelegramAiAssistant، وتُخرج الرد بالشكل المناسب لكل
     * محطة (نص فقط لشارح المنطق، نص+تقرير تعديلات+ملف للفحص والتحسين).
     * يُستدعى من مسار النص الحر (routeFreeTextToAssistant) ومسار رفع
     * الملف (replyWithCodeFileDebug) كليهما.
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
                $result,
                'optimized_' . $baseFilename,
                '⚡ <b>ملاحظات تحسين الأداء</b>',
                '📄 النسخة بعد التحسين',
                '⚡'
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

        /*
         * ميزة "التحكّم الكامل بالخطة من الشات" (خطوة ١٠١) — رسالة
         * ثانية منفصلة بأزرار Inline تفتح مباشرة على قائمة السنوات
         * (المستوى الأول)، بدل زر وسيط "افتح الخطة" لا داعي له. أي
         * تعديل حالة من هون يكتب بنفس جدول my_courses يلي يقرأه/يكتبه
         * الموقع بالضبط (عبر MyCourseStatusService) — فأي فتح/تحديث
         * لاحق بالموقع يعكس التعديل فورًا، وهذا أقصى "مزامنة لحظية"
         * ممكنة بلا بنية Push فعلية (WebSocket/SSE) غير متوفرة على
         * هذه الاستضافة المشتركة.
         */
        [$text, $keyboard] = $this->renderPlanYearsView($user, $planCalculator);

        $bot->sendMessage($chatId, "👇 اضغط على سنة لتعديل حالات موادها مباشرة من هون:\n\n".$text, $keyboard);
    }

    /*
     * ======================================================================
     * التحكّم الكامل بالخطة الدراسية من داخل الشات (خطوة ١٠١).
     *
     * أربعة مستويات تنقّل بأزرار Inline (سنوات ← فصول ← مواد ← تعديل
     * حالة مادة)، بنفس فلسفة "gpa:"/"sched:" الموجودة أصلًا بهذا الملف:
     * كل ضغطة زر تعيد التحقق من الحساب المربوط والمعرّفات الرقمية فقط
     * من callback_data (لا حالة محادثة يُعتمد عليها للتنقّل نفسه)، وكل
     * كتابة فعلية تمرّ حصرًا عبر MyCourseStatusService — نفس جدول
     * my_courses ونفس قواعد العمل (خانة اختيارية مفتوحة، مساق الفصل
     * الحالي يتحوّل لـ"منسحب" لا يُحذف...) يلي يستخدمها الموقع، فلا يوجد
     * احتمال انحراف بين الواجهتين (راجع تعليق أعلى تلك الخدمة).
     *
     * نطاق مقصود لهذه النسخة الأولى: المواد الإجبارية فقط (course_type
     * = required) — لا الخانات الاختيارية (placeholder/elective). سبب
     * الاستبعاد: اختيار مادة اختيارية جديدة لخانة معيّنة يعتمد على ترتيب
     * استهلاك متسلسل عبر كل الخانات المفتوحة (راجع plan-view.js::
     * electiveCtx.cursor)، وتكراره هون بمعزل عن رسم الموقع الفعلي يحمل
     * خطر عرض/حفظ نتيجة مختلفة عن الموقع. الطالب يدير اختياراته من
     * الموقع كما هو، والبوت هنا يغطي الغالبية العظمى من التعديلات
     * (كل السنوات الأربع من المواد الإجبارية) بأمان تام.
     *
     * callback_data (بادئة "plan:"، أقل بكثير من حد الـ٦٤ بايت بكل حال):
     *   plan:years          → قائمة السنوات (المستوى ١)
     *   plan:y:{year}        → فصول سنة (المستوى ٢)
     *   plan:t:{year}:{sem}  → مواد فصل، sem محلي ١ أو ٢ (المستوى ٣)
     *   plan:c:{courseId}    → تعديل حالة مادة واحدة (المستوى ٤)
     *   plan:s:{courseId}:{code} → ضبط الحالة فعليًا، code = c/r/d/n
     *   plan:by:{year}       → "منجز الكل" لسنة كاملة (فصليها معًا)
     *   plan:bt:{year}:{sem} → "منجز الكل" لفصل واحد
     *   plan:e               → قائمة المساقات الاختيارية (خطوة ١٠٢)
     *   plan:ea              → المساقات الاختيارية المتاحة للاختيار الآن
     *
     * ملاحظة (خطوة ١٠٢): المساقات الاختيارية غير مرتبطة بسنة/فصل محدد
     * بالخطة أصلًا (year/semester على صفّها بجدول courses مجرد تصنيف
     * داخلي قديم، ليس موقعًا حقيقيًا — راجع خطوة ١٣ بمشروع التوثيق)،
     * فهي بقسم "plan:e" منفصل تمامًا عن شجرة سنة→فصل، تمامًا كما هو
     * موثَّق بخطة خطوة ٩٠. مادة اختيارية مُختارة أصلًا (لها صف
     * my_courses) تُعدَّل حالتها عبر نفس plan:c/plan:s المستخدمة
     * للمواد الإجبارية (فقط تحقّق النوع تغيّر ليقبل 'elective' أيضًا)؛
     * زر "اختيار مادة جديدة" لا يظهر إلا إن وُجدت خانة مفتوحة فعليًا
     * (MyCourseStatusService::hasOpenElectiveSlot — نفس شرط attach()
     * الموجود أصلًا)، والإضافة الفعلية تمرّ عبر نفس ensureStatus() التي
     * تستدعي attach() فتتحقق من الشرط مجددًا من طرف الخادم بغضّ النظر
     * عمّا أظهرته الواجهة، فلا يمكن الالتفاف عليه بـcallback_data مزوَّر.
     * ======================================================================
     */
    private const PLAN_STATUS_EMOJI = ['completed' => '✅', 'registered' => '⏳', 'dropped' => '🔴', 'none' => '⚪'];
    private const PLAN_STATUS_LABEL = ['completed' => 'منجز', 'registered' => 'جارٍ', 'dropped' => 'منسحب', 'none' => 'متبقٍ'];
    private const PLAN_STATUS_CODE = ['completed' => 'c', 'registered' => 'r', 'dropped' => 'd', 'none' => 'n'];
    private const PLAN_CODE_STATUS = ['c' => 'completed', 'r' => 'registered', 'd' => 'dropped', 'n' => 'none'];

    private function handlePlanCallback(
        TelegramBotApi $bot,
        PlanCalculator $planCalculator,
        MyCourseStatusService $courseStatusService,
        array $callbackQuery
    ): void {
        $callbackId = (string) ($callbackQuery['id'] ?? '');
        $chatId = $callbackQuery['message']['chat']['id'] ?? null;
        $messageId = (int) ($callbackQuery['message']['message_id'] ?? 0);
        $data = (string) ($callbackQuery['data'] ?? '');
        $action = substr($data, strlen('plan:'));
        $parts = explode(':', $action);
        $key = $parts[0] ?? '';

        if (! $chatId || ! $messageId) {
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

        $user = $link->user;

        try {
            switch ($key) {
                case 'years':
                    $bot->answerCallbackQuery($callbackId);
                    [$text, $keyboard] = $this->renderPlanYearsView($user, $planCalculator);
                    $bot->editMessageText($chatId, $messageId, $text, $keyboard);

                    return;

                case 'y':
                    $year = (int) ($parts[1] ?? 0);
                    $bot->answerCallbackQuery($callbackId);
                    [$text, $keyboard] = $this->renderPlanTermsView($user, $planCalculator, $year);
                    $bot->editMessageText($chatId, $messageId, $text, $keyboard);

                    return;

                case 't':
                    $year = (int) ($parts[1] ?? 0);
                    $localSemester = (int) ($parts[2] ?? 0);
                    $bot->answerCallbackQuery($callbackId);
                    [$text, $keyboard] = $this->renderPlanCoursesView($user, $planCalculator, $year, $localSemester);
                    $bot->editMessageText($chatId, $messageId, $text, $keyboard);

                    return;

                case 'c':
                    $course = Course::find((int) ($parts[1] ?? 0));

                    if (! $course || ! in_array($course->course_type, ['required', 'elective'], true)) {
                        $bot->answerCallbackQuery($callbackId, 'المادة غير موجودة أو غير مدعومة من البوت حاليًا.');

                        return;
                    }

                    $bot->answerCallbackQuery($callbackId);
                    [$text, $keyboard] = $this->renderPlanCourseEditView($user, $planCalculator, $course);
                    $bot->editMessageText($chatId, $messageId, $text, $keyboard);

                    return;

                case 'e':
                    $bot->answerCallbackQuery($callbackId);
                    [$text, $keyboard] = $this->renderPlanElectivesView($user, $planCalculator, $courseStatusService);
                    $bot->editMessageText($chatId, $messageId, $text, $keyboard);

                    return;

                case 'ea':
                    $bot->answerCallbackQuery($callbackId);
                    [$text, $keyboard] = $this->renderPlanElectiveAvailableView($user, $planCalculator);
                    $bot->editMessageText($chatId, $messageId, $text, $keyboard);

                    return;

                case 's':
                    $course = Course::find((int) ($parts[1] ?? 0));
                    $code = (string) ($parts[2] ?? '');
                    $status = self::PLAN_CODE_STATUS[$code] ?? null;

                    if (! $course || ! in_array($course->course_type, ['required', 'elective'], true) || ! $status) {
                        $bot->answerCallbackQuery($callbackId, 'طلب غير صالح.');

                        return;
                    }

                    if ($status === 'none') {
                        $courseStatusService->remove($user, $course);
                    } else {
                        $courseStatusService->ensureStatus($user, $course, $status);
                    }

                    $bot->answerCallbackQuery($callbackId, '✅ تم الحفظ.');
                    [$text, $keyboard] = $this->renderPlanCourseEditView($user, $planCalculator, $course);
                    $bot->editMessageText($chatId, $messageId, $text, $keyboard);

                    return;

                case 'by':
                    $year = (int) ($parts[1] ?? 0);
                    $changed = $this->bulkCompletePlanScope($courseStatusService, $planCalculator, $user, $year, null);
                    $bot->answerCallbackQuery($callbackId, $changed > 0 ? "✅ تم تحديث {$changed} مادة." : 'كل المواد منجزة أصلًا.');
                    [$text, $keyboard] = $this->renderPlanTermsView($user, $planCalculator, $year);
                    $bot->editMessageText($chatId, $messageId, $text, $keyboard);

                    return;

                case 'bt':
                    $year = (int) ($parts[1] ?? 0);
                    $localSemester = (int) ($parts[2] ?? 0);
                    $changed = $this->bulkCompletePlanScope($courseStatusService, $planCalculator, $user, $year, $localSemester);
                    $bot->answerCallbackQuery($callbackId, $changed > 0 ? "✅ تم تحديث {$changed} مادة." : 'كل المواد منجزة أصلًا.');
                    [$text, $keyboard] = $this->renderPlanCoursesView($user, $planCalculator, $year, $localSemester);
                    $bot->editMessageText($chatId, $messageId, $text, $keyboard);

                    return;

                default:
                    $bot->answerCallbackQuery($callbackId);

                    return;
            }
        } catch (CourseStatusException $e) {
            $bot->answerCallbackQuery($callbackId, $e->getMessage());
        }
    }

    /**
     * "منجز الكل" — لسنة كاملة (localSemester=null) أو فصل واحد بعينها.
     * يتجاهل أي مادة منجزة أصلًا (بلا نداء حفظ لا داعي له)، تمامًا مثل
     * applyBulkDone() بالموقع (plan-view.js). يرجّع عدد المواد المتأثرة
     * فعليًا ليُعرض للطالب برسالة التأكيد (Toast).
     */
    private function bulkCompletePlanScope(
        MyCourseStatusService $courseStatusService,
        PlanCalculator $planCalculator,
        \App\Models\User $user,
        int $year,
        ?int $localSemester
    ): int {
        $courses = $this->planScopeCourses($year, $localSemester);
        $statuses = $planCalculator->statusMap($user);
        $changed = 0;

        foreach ($courses as $course) {
            $current = $statuses[$course->key]['status'] ?? 'none';

            if ($current === 'completed') {
                continue;
            }

            $courseStatusService->ensureStatus($user, $course, 'completed');
            $changed++;
        }

        return $changed;
    }

    /**
     * مواد سنة كاملة (localSemester=null) أو فصل واحد بعينها — إجبارية
     * فقط (course_type=required)، بنفس نطاق PlanCalculator::byYear().
     *
     * @return \Illuminate\Support\Collection<int, Course>
     */
    private function planScopeCourses(int $year, ?int $localSemester)
    {
        $query = Course::query()
            ->where('is_active', true)
            ->where('course_type', 'required')
            ->where('year', $year);

        if ($localSemester !== null) {
            $query->where('semester', (($year - 1) * 2) + $localSemester);
        } else {
            $query->whereIn('semester', [(($year - 1) * 2) + 1, (($year - 1) * 2) + 2]);
        }

        return $query->orderBy('sort_order')->orderBy('id')->get();
    }

    /**
     * المستوى ١ — قائمة السنوات، بملخّص ساعات كل سنة (نفس أرقام
     * PlanCalculator::summarize()['by_year'] المعروضة بصفحة الخطة
     * بالموقع بالضبط، بلا أي حساب مواز).
     *
     * @return array{0: string, 1: array}
     */
    private function renderPlanYearsView(\App\Models\User $user, PlanCalculator $planCalculator): array
    {
        $summary = $planCalculator->summarize($user);

        $text = "📋 <b>الخطة الدراسية</b>\n\n".
            "✅ {$summary['completed_hours']} من {$summary['total_credit_hours']} ساعة ({$summary['percent']}٪)\n\n".
            'اختر سنة لعرض فصولها:';

        $rows = [];

        foreach ($summary['by_year'] as $row) {
            $label = $this->gpaYearLabel((int) $row['year']);
            $buttonText = "{$label} — {$row['completed_hours']}/{$row['plan_hours']} ({$row['percent']}٪)";

            $rows[] = [['text' => $buttonText, 'callback_data' => "plan:y:{$row['year']}"]];
        }

        if (! $rows) {
            $rows[] = [['text' => 'لا توجد سنوات بعد', 'callback_data' => 'plan:years']];
        }

        $rows[] = [['text' => '📗 المساقات الاختيارية', 'callback_data' => 'plan:e']];

        return [$text, $rows];
    }

    /**
     * قسم المساقات الاختيارية — منفصل عن شجرة سنة→فصل عمدًا لأنها غير
     * مرتبطة بموقع ثابت بالخطة (راجع تعليق الثوابت أعلاه). يعرض ما
     * اختاره الطالب فعلًا (بحالته الحالية، قابل للتعديل)، وزر اختيار
     * مادة جديدة فقط إن وُجدت خانة مفتوحة فعلًا الآن.
     *
     * @return array{0: string, 1: array}
     */
    private function renderPlanElectivesView(\App\Models\User $user, PlanCalculator $planCalculator, MyCourseStatusService $courseStatusService): array
    {
        $statuses = $planCalculator->statusMap($user);

        $electives = Course::query()
            ->where('is_active', true)
            ->where('course_type', 'elective')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $chosen = $electives->filter(fn (Course $c) => isset($statuses[$c->key]));

        $text = "📗 <b>المساقات الاختيارية</b>\n\n".
            "هذه المواد غير مرتبطة بسنة أو فصل محدد بالخطة — تُختار عند توفّر خانة مفتوحة.\n\n".
            "✅ منجز · ⏳ جارٍ · 🔴 منسحب\n\n";

        $rows = [];

        foreach ($chosen as $course) {
            $status = $statuses[$course->key]['status'] ?? 'none';
            $emoji = self::PLAN_STATUS_EMOJI[$status] ?? '⚪';
            $name = mb_strlen($course->name_ar) > 38 ? mb_substr($course->name_ar, 0, 37).'…' : $course->name_ar;

            $rows[] = [['text' => "{$emoji} {$name}", 'callback_data' => "plan:c:{$course->id}"]];
        }

        if (! $chosen->count()) {
            $text .= 'لم تختر أي مادة اختيارية بعد.';
        }

        if ($courseStatusService->hasOpenElectiveSlot($user)) {
            $rows[] = [['text' => '➕ اختيار مادة اختيارية جديدة', 'callback_data' => 'plan:ea']];
        } else {
            $text .= "\nℹ️ ما في خانة اختيارية مفتوحة إلك حاليًا بفصلك الدراسي المُعلَن.";
        }

        $rows[] = [['text' => '🏠 كل السنوات', 'callback_data' => 'plan:years']];

        return [$text, $rows];
    }

    /**
     * المساقات الاختيارية التي لم يختَرها الطالب بعد — تُعرض فقط عند
     * الضغط على "اختيار مادة اختيارية جديدة" (زر لا يظهر أصلًا بلا
     * خانة مفتوحة، وحتى لو وصل الطلب بأي شكل آخر فـensureStatus()
     * تستدعي attach() التي تتحقق من الشرط مجددًا من طرف الخادم).
     *
     * @return array{0: string, 1: array}
     */
    private function renderPlanElectiveAvailableView(\App\Models\User $user, PlanCalculator $planCalculator): array
    {
        $statuses = $planCalculator->statusMap($user);

        $available = Course::query()
            ->where('is_active', true)
            ->where('course_type', 'elective')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->reject(fn (Course $c) => isset($statuses[$c->key]));

        $rows = [];

        foreach ($available as $course) {
            $name = mb_strlen($course->name_ar) > 38 ? mb_substr($course->name_ar, 0, 37).'…' : $course->name_ar;
            $rows[] = [['text' => $name, 'callback_data' => "plan:c:{$course->id}"]];
        }

        $text = $available->count()
            ? "➕ <b>اختيار مادة اختيارية جديدة</b>\n\nاضغط على مادة لاختيارها لخانتك المفتوحة حاليًا:"
            : 'لا توجد مساقات اختيارية متاحة للاختيار حاليًا.';

        $rows[] = [['text' => '⬅️ المساقات الاختيارية', 'callback_data' => 'plan:e']];

        return [$text, $rows];
    }

    /**
     * المستوى ٢ — فصلا سنة معيّنة + زر "منجز الكل" للسنة كاملة، بنص
     * ديناميكي (PlanBulkLabel) يعكس أي حالة جزئية فورًا.
     *
     * @return array{0: string, 1: array}
     */
    private function renderPlanTermsView(\App\Models\User $user, PlanCalculator $planCalculator, int $year): array
    {
        $statuses = $planCalculator->statusMap($user);
        $yearCourses = $this->planScopeCourses($year, null);

        $text = '📅 <b>'.$this->gpaYearLabel($year)."</b>\n\nاختر فصلًا لعرض مواده:";

        $rows = [];

        foreach ([1, 2] as $localSemester) {
            $globalSemester = (($year - 1) * 2) + $localSemester;
            $termCourses = $yearCourses->where('semester', $globalSemester);

            $planHours = $termCourses->sum('credit_hours');
            $doneHours = $termCourses
                ->filter(fn (Course $c) => ($statuses[$c->key]['status'] ?? 'none') === 'completed')
                ->sum('credit_hours');

            $label = $this->gpaSemesterLabel($globalSemester);
            $percent = $planHours > 0 ? (int) round($doneHours / $planHours * 100) : 0;

            $rows[] = [[
                'text' => "{$label} — {$doneHours}/{$planHours} ({$percent}٪)",
                'callback_data' => "plan:t:{$year}:{$localSemester}",
            ]];
        }

        $yearStatuses = $yearCourses->map(fn (Course $c) => $statuses[$c->key]['status'] ?? 'none')->all();
        $bulk = PlanBulkLabel::forStatuses($yearStatuses);

        $rows[] = [[
            'text' => ($bulk['state'] === 'done' ? '✅ ' : '🔘 ').$bulk['label'].' (السنة)',
            'callback_data' => "plan:by:{$year}",
        ]];

        $rows[] = [
            ['text' => '⬅️ كل السنوات', 'callback_data' => 'plan:years'],
        ];

        return [$text, $rows];
    }

    /**
     * المستوى ٣ — مواد فصل واحد بأيقونة حالتها الحالية + زر "منجز
     * الكل" لهذا الفصل تحديدًا.
     *
     * @return array{0: string, 1: array}
     */
    private function renderPlanCoursesView(\App\Models\User $user, PlanCalculator $planCalculator, int $year, int $localSemester): array
    {
        $statuses = $planCalculator->statusMap($user);
        $courses = $this->planScopeCourses($year, $localSemester);
        $globalSemester = (($year - 1) * 2) + $localSemester;

        $text = '📚 <b>'.$this->gpaYearLabel($year).' — '.$this->gpaSemesterLabel($globalSemester)."</b>\n\n".
            "✅ منجز · ⏳ جارٍ · 🔴 منسحب · ⚪ متبقٍ\n\n".
            'اضغط على مادة لتغيير حالتها:';

        $rows = [];

        foreach ($courses as $course) {
            $status = $statuses[$course->key]['status'] ?? 'none';
            $emoji = self::PLAN_STATUS_EMOJI[$status] ?? '⚪';
            $name = mb_strlen($course->name_ar) > 38 ? mb_substr($course->name_ar, 0, 37).'…' : $course->name_ar;

            $rows[] = [['text' => "{$emoji} {$name}", 'callback_data' => "plan:c:{$course->id}"]];
        }

        if (! $courses->count()) {
            $rows[] = [['text' => 'لا توجد مواد إجبارية بهذا الفصل', 'callback_data' => "plan:t:{$year}:{$localSemester}"]];
        }

        $termStatuses = $courses->map(fn (Course $c) => $statuses[$c->key]['status'] ?? 'none')->all();
        $bulk = PlanBulkLabel::forStatuses($termStatuses);

        $rows[] = [[
            'text' => ($bulk['state'] === 'done' ? '✅ ' : '🔘 ').$bulk['label'].' (الفصل)',
            'callback_data' => "plan:bt:{$year}:{$localSemester}",
        ]];

        $rows[] = [
            ['text' => '⬅️ فصول السنة', 'callback_data' => "plan:y:{$year}"],
            ['text' => '🏠 كل السنوات', 'callback_data' => 'plan:years'],
        ];

        return [$text, $rows];
    }

    /**
     * المستوى ٤ — تعديل حالة مادة واحدة. أزرار الحالات الأربع دائمًا
     * ظاهرة (لا نخفي الحالة الحالية)، مع علامة ✓ توضّح المختارة حاليًا
     * بما إن أزرار تيليجرام لا تدعم أي تمييز بصري آخر.
     *
     * @return array{0: string, 1: array}
     */
    private function renderPlanCourseEditView(\App\Models\User $user, PlanCalculator $planCalculator, Course $course): array
    {
        $statuses = $planCalculator->statusMap($user);
        $current = $statuses[$course->key]['status'] ?? 'none';
        $currentLabel = self::PLAN_STATUS_LABEL[$current] ?? 'متبقٍ';

        if ($course->course_type === 'elective') {
            // المساقات الاختيارية غير مرتبطة بموقع سنة/فصل حقيقي (راجع
            // تعليق الثوابت أعلاه) فرجوعها لقسمها الخاص لا لشجرة الفصول.
            $backCallback = 'plan:e';
            $backLabel = '⬅️ المساقات الاختيارية';
        } else {
            $localSemester = (($course->semester - 1) % 2) + 1;
            $backCallback = "plan:t:{$course->year}:{$localSemester}";
            $backLabel = '⬅️ مواد الفصل';
        }

        $text = '📖 <b>'.TelegramBotApi::escapeHtml($course->name_ar)."</b>\n".
            TelegramBotApi::escapeHtml($course->code)."\n\n".
            "الحالة الحالية: {$currentLabel}\n\n".
            'اختر الحالة الجديدة:';

        $rows = [
            [
                ['text' => '✅ منجز'.($current === 'completed' ? ' ✓' : ''), 'callback_data' => "plan:s:{$course->id}:c"],
                ['text' => '⏳ جارٍ'.($current === 'registered' ? ' ✓' : ''), 'callback_data' => "plan:s:{$course->id}:r"],
            ],
            [
                ['text' => '🔴 منسحب'.($current === 'dropped' ? ' ✓' : ''), 'callback_data' => "plan:s:{$course->id}:d"],
                ['text' => '⚪ متبقٍ'.($current === 'none' ? ' ✓' : ''), 'callback_data' => "plan:s:{$course->id}:n"],
            ],
            [
                ['text' => $backLabel, 'callback_data' => $backCallback],
            ],
        ];

        return [$text, $rows];
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
     * TelegramAiAssistant.
     *
     * ⚠ كانت هون علة حقيقية (شكوى: "بعتلي جارٍ التحليل... وبعدها ولا
     * اشي" على PDF بحجم ٤.٦ ميغا): @set_time_limit(60) كانت أقصر بكثير
     * من أسوأ سيناريو واقعي لسلسلة النداءات الفعلية — رفع الملف لـGemini
     * (GeminiFileService::uploadFile، حده ١٢٠ث) + انتظار جهوزيته
     * (getFile، حتى ٢٥ث إضافية) + نداء التلخيص نفسه (حتى ٩٠ث بعد رفعه —
     * راجع TelegramAiAssistant::generate()) يعني حتى ~٢٣٥ ثانية بأسوأ
     * حالة، أطول بكثير من ٦٠ ثانية. النتيجة: PHP كان يقتل الطلب بصمت
     * بلا أي استثناء ولا رسالة خطأ (لا try/catch يمسك "انتهاء الوقت"
     * لأنه إنهاء قسري من المفسّر نفسه)، فالطالب يضل ينتظر للأبد. نفس
     * حل "ورشة الأكواد" بالضبط هون: سقف وقت أعلى فعليًا + قطع اتصال
     * الويبهوك فورًا (fastcgi_finish_request) حتى ما يتأثر التسليم
     * بطول وقت المعالجة، ولا بمهلة أي وسيط استضافة/تيليجرام إضافية.
     */
    private function replyWithFileSummary(
        TelegramBotApi $bot,
        TelegramAiAssistant $aiAssistant,
        int|string $chatId,
        \App\Models\User $user,
        ?array $photos,
        ?array $document
    ): void {
        /*
         * رُفع من 300 لـ450 (جلسة سادسة، جزء 4) بعد ما فعّلنا
         * continueOnTruncation بـTelegramAiAssistant::generate() (إصلاح
         * "التلخيص مقطوع منتصف جملة") — أسوأ سيناريو الآن حتى 3 جولات
         * تلخيص متتالية (مسار inline: حتى 120ث لكل جولة = 360ث) بدل
         * جولة واحدة بس، فلازم هامش وقت سكربت أعلى يستوعبها.
         */
        @set_time_limit(450);

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

        // راجع تعليق fastcgi_finish_request المطابق بـreplyWithCodeFileDebug — نفس المنطق بالضبط.
        if (function_exists('fastcgi_finish_request')) {
            if (! headers_sent()) {
                http_response_code(200);
                header('Content-Type: application/json');
            }

            echo json_encode(['ok' => true]);
            fastcgi_finish_request();
        }

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
     * لوحة القائمة الرئيسية — نفس self::MAIN_MENU_KEYBOARD المختصر
     * (٣ صفوف: فئتان + فئتان + مساعدة) لكل الطلاب، وفئة إضافية
     * "🛠️ إدارة" لحسابات الإدارة فقط (User::isStaff()) بدل صفّين
     * منفصلين كما كانت (راجع تعليق SUBMENU_ADMIN/MAIN_MENU_CAT_ADMIN
     * فوق لسبب إعادة التنظيم بفئات).
     */
    private function buildMainMenuKeyboard(\App\Models\User $user): array
    {
        $keyboard = self::MAIN_MENU_KEYBOARD;

        if ($user->isStaff()) {
            $keyboard[] = [['text' => self::MAIN_MENU_CAT_ADMIN]];
        }

        return $keyboard;
    }

    /*
     * تسجيل قائمة أوامر "/" بتيليجرام (راجع تعليق DEFAULT_BOT_COMMANDS
     * فوق) — تُستدعى بكل /start ناجح (حساب مربوط جديد أو رجوع لحساب
     * مربوط أصلًا). القائمة العامة (بلا scope) تنطبق على أي محادثة ما
     * إلها قائمة خاصة، وقائمة الإدارة الإضافية مربوطة بـchat_id هالطالب
     * تحديدًا (BotCommandScopeChat) فما تظهر عند أي طالب عادي إطلاقًا،
     * حتى لو رفع صلاحيته لاحقًا لازم يعمل /start مرة تانية ليشوفها.
     */
    private function syncBotCommands(TelegramBotApi $bot, int|string $chatId, \App\Models\User $user): void
    {
        $bot->setMyCommands(self::DEFAULT_BOT_COMMANDS);

        if ($user->isStaff()) {
            $bot->setMyCommands(
                array_merge(self::DEFAULT_BOT_COMMANDS, self::STAFF_BOT_COMMANDS),
                ['type' => 'chat', 'chat_id' => $chatId]
            );
        }
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
        $this->sendSearchResults($bot, $chatId, $normalized, $link->user->isStaff());
    }

    /*
     * توحيد بسيط للنص العربي قبل مطابقته بـFEATURE_INDEX — يشيل تمييز
     * الهمزات (أ/إ/آ ← ا) ويوحّد حالة الأحرف اللاتينية، حتى "اعدادات"
     * أو "الاعدادات" أو "settings" تتصرف كلها بنفس المرونة بدل مطابقة
     * حرفية صارمة. لا تأثير على بحث قاعدة البيانات (Course/CourseFile/
     * Tool) — هذا فقط لفهرس الأزرار بالذاكرة.
     */
    private function normalizeSearchText(string $text): string
    {
        return str_replace(['أ', 'إ', 'آ', 'ة', 'ى'], ['ا', 'ا', 'ا', 'ه', 'ي'], mb_strtolower(trim($text)));
    }

    private function sendSearchResults(TelegramBotApi $bot, int|string $chatId, string $term, bool $includeStaffFeatures = false): void
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

        /*
         * فهرس أزرار/ميزات البوت (FEATURE_INDEX) — مطابقة بالذاكرة لا
         * بقاعدة البيانات: نطابق النص المطبَّع ضد تسمية الزر نفسها وكل
         * مرادفاتها. أزرار الإدارة (staffOnly) ما تظهر إلا لو
         * $includeStaffFeatures true (المستخدم فعليًا isStaff()).
         */
        $normalizedTerm = $this->normalizeSearchText($term);
        $matchedFeatures = [];

        foreach (self::FEATURE_INDEX as $key => $feature) {
            if (! empty($feature['staffOnly']) && ! $includeStaffFeatures) {
                continue;
            }

            $haystacks = array_merge([$feature['label']], $feature['keywords']);
            $isMatch = false;

            foreach ($haystacks as $haystack) {
                if (mb_stripos($this->normalizeSearchText($haystack), $normalizedTerm) !== false) {
                    $isMatch = true;

                    break;
                }
            }

            if ($isMatch) {
                $matchedFeatures[$key] = $feature['label'];
            }
        }

        if ($courses->isEmpty() && $files->isEmpty() && $tools->isEmpty() && $matchedFeatures === []) {
            $bot->sendMessage($chatId, '🔍 ما لقيت أي نتيجة لـ"'.TelegramBotApi::escapeHtml($term).'". جرّب كلمة تانية.');

            return;
        }

        $frontendUrl = rtrim((string) config('app.frontend_url'), '/');
        $lines = ['🔍 <b>نتائج البحث عن "'.TelegramBotApi::escapeHtml($term).'"</b>', ''];
        $keyboard = [];

        if ($matchedFeatures !== []) {
            $lines[] = '🧭 <b>أزرار وميزات البوت:</b>';

            foreach ($matchedFeatures as $key => $label) {
                $lines[] = '• '.TelegramBotApi::escapeHtml($label);
                $keyboard[] = [['text' => $label, 'callback_data' => 'navjump:'.$key]];
            }

            $lines[] = '';
        }

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

    /*
     * =====================================================================
     * 🧮 حاسبة الهندسة السريعة (Quick Eng Tools) — جلسة سادسة، جزء 6
     * =====================================================================
     * ميزة جديدة كاملة: 7 حاسبات هندسية تفاعلية موزّعة على 3 تصنيفات:
     *   🔌 العتاد والدوائر: مقاومات/مكثفات (rc)، مؤقت 555 (555)، طاقة (power)
     *   💻 الأنظمة الرقمية والشبكات: أنظمة عددية/منطق (base)، شبكات (subnet)
     *   ⚙️ الخوارزميات والأداء: تعقيد زمني (bigo)، أداء معالج (cpu)
     *
     * تتّبع نفس نمط GPA بالضبط: pending_action.action بسابقة "calc_"
     * (أو أزرار بسابقة callback_data="calc:") + دايمًا "❌ إلغاء" (زر
     * وكمان كتابة "إلغاء" حرفيًا) لإيقاف أي عملية بمنتصفها. منفصلة كليًا
     * عن "🧰 الأدوات الهندسية" (كتالوج أدوات المساقات) رغم تشابه الاسم.
     */
    private function sendCalcMainMenu(TelegramBotApi $bot, int|string $chatId): void
    {
        $bot->sendMessage(
            $chatId,
            "🧮 <b>حاسبة الهندسة السريعة</b>\n\nمجموعة حاسبات جاهزة تساعدك بمذاكرة/تطبيق مساقات الهندسة — اختر التصنيف:",
            [
                [['text' => '🔌 العتاد والدوائر (Hardware)', 'callback_data' => 'calc:cat:hw']],
                [['text' => '💻 الأنظمة الرقمية والشبكات (Digital)', 'callback_data' => 'calc:cat:dig']],
                [['text' => '⚙️ الخوارزميات والأداء (Algorithms)', 'callback_data' => 'calc:cat:algo']],
            ]
        );
    }

    private function sendCalcCategoryMenu(TelegramBotApi $bot, int|string $chatId, string $cat): void
    {
        $titles = [
            'hw' => '🔌 <b>العتاد والدوائر</b>',
            'dig' => '💻 <b>الأنظمة الرقمية والشبكات</b>',
            'algo' => '⚙️ <b>الخوارزميات والأداء</b>',
        ];

        $tools = match ($cat) {
            'hw' => [
                ['key' => 'rc', 'label' => '🎛️ المقاومات والمكثفات'],
                ['key' => '555', 'label' => '⚡ مؤقت 555 ودارات RC'],
                ['key' => 'power', 'label' => '🔋 استهلاك الطاقة'],
            ],
            'dig' => [
                ['key' => 'base', 'label' => '🔄 الأنظمة العددية والمنطق'],
                ['key' => 'subnet', 'label' => '🌐 الشبكات وعناوين IP'],
            ],
            'algo' => [
                ['key' => 'bigo', 'label' => '📊 التعقيد الزمني (Big-O)'],
                ['key' => 'cpu', 'label' => '⏱️ سرعة المعالج (CPU)'],
            ],
            default => [],
        };

        if ($tools === []) {
            $this->sendCalcMainMenu($bot, $chatId);

            return;
        }

        $rows = [];
        foreach ($tools as $tool) {
            $rows[] = [['text' => $tool['label'], 'callback_data' => 'calc:tool:'.$tool['key']]];
        }
        $rows[] = [['text' => '⬅️ رجوع', 'callback_data' => 'calc:menu']];

        $bot->sendMessage($chatId, ($titles[$cat] ?? '🧮 حاسبة الهندسة').":\n\nاختر الأداة:", $rows);
    }

    private function handleCalcCallback(TelegramBotApi $bot, array $callbackQuery): void
    {
        $callbackId = (string) ($callbackQuery['id'] ?? '');
        $chatId = $callbackQuery['message']['chat']['id'] ?? null;
        $data = (string) ($callbackQuery['data'] ?? '');
        $action = substr($data, strlen('calc:'));
        $parts = explode(':', $action);
        $key = $parts[0] ?? '';

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

        $bot->answerCallbackQuery($callbackId);

        switch ($key) {
            case 'menu':
                $link->update(['pending_action' => null]);
                $this->sendCalcMainMenu($bot, $chatId);

                return;

            case 'cat':
                $link->update(['pending_action' => null]);
                $this->sendCalcCategoryMenu($bot, $chatId, (string) ($parts[1] ?? ''));

                return;

            case 'cancel':
                $link->update(['pending_action' => null]);
                $bot->sendMessage($chatId, 'تم إلغاء العملية ✅');

                return;

            case 'tool':
                $this->startCalcTool($bot, $link, $chatId, (string) ($parts[1] ?? ''));

                return;

            case 'rc':
                $this->handleCalcRcCallback($bot, $link, $chatId, $parts);

                return;

            case '555':
                $this->handleCalc555Callback($bot, $link, $chatId, $parts);

                return;

            case 'base':
                $this->handleCalcBaseCallback($bot, $link, $chatId, $parts);

                return;

            case 'bigo':
                $this->handleCalcBigoCallback($bot, $chatId, $parts);

                return;

            default:
                return;
        }
    }

    private function startCalcTool(TelegramBotApi $bot, TelegramLink $link, int|string $chatId, string $tool): void
    {
        switch ($tool) {
            case 'rc':
                $link->update(['pending_action' => null]);
                $bot->sendMessage($chatId, "🎛️ <b>حاسبة المقاومات والمكثفات</b>\n\nاختر العملية:", [
                    [['text' => '🎨 كود الألوان ← القيمة', 'callback_data' => 'calc:rc:mode:c2v']],
                    [['text' => '🔢 القيمة ← كود الألوان', 'callback_data' => 'calc:rc:mode:v2c']],
                    [['text' => '➕ توالي/توازي (مقاومات أو مكثفات)', 'callback_data' => 'calc:rc:mode:combo']],
                    [['text' => '⬅️ رجوع', 'callback_data' => 'calc:cat:hw']],
                ]);

                return;

            case '555':
                $link->update(['pending_action' => null]);
                $bot->sendMessage($chatId, "⚡ <b>مؤقت 555 ودارات RC</b>\n\nاختر وضع الدارة:", [
                    [['text' => '🔁 وضع التذبذب المستمر (Astable)', 'callback_data' => 'calc:555:mode:astable']],
                    [['text' => '⏸️ نبضة واحدة (Monostable)', 'callback_data' => 'calc:555:mode:mono']],
                    [['text' => '⬅️ رجوع', 'callback_data' => 'calc:cat:hw']],
                ]);

                return;

            case 'power':
                $link->update(['pending_action' => ['action' => 'calc_power', 'step' => 'current', 'data' => []]]);
                $bot->sendMessage(
                    $chatId,
                    "🔋 <b>حاسبة استهلاك الطاقة</b>\n\nأدخل التيار المستهلك بوحدة mA (مللي أمبير)، مثلاً: 250\n\nاكتب \"إلغاء\" بأي وقت لإيقاف العملية."
                );

                return;

            case 'base':
                $link->update(['pending_action' => null]);
                $bot->sendMessage($chatId, "🔄 <b>حاسبة الأنظمة العددية والمنطق</b>\n\nاختر العملية:", [
                    [['text' => '🔁 تحويل بين الأنظمة العددية', 'callback_data' => 'calc:base:mode:conv']],
                    [['text' => '🔣 عمليات منطقية (AND/OR/XOR...)', 'callback_data' => 'calc:base:mode:logic']],
                    [['text' => '⬅️ رجوع', 'callback_data' => 'calc:cat:dig']],
                ]);

                return;

            case 'subnet':
                $link->update(['pending_action' => ['action' => 'calc_subnet', 'step' => 'input', 'data' => []]]);
                $bot->sendMessage(
                    $chatId,
                    "🌐 <b>حاسبة الشبكات وعناوين IP</b>\n\nأدخل عنوان الـIP مع الـCIDR بهذا الشكل بالضبط:\n<code>192.168.1.10/24</code>\n\nاكتب \"إلغاء\" لإيقاف العملية."
                );

                return;

            case 'bigo':
                $this->sendCalcBigoMenu($bot, $chatId);

                return;

            case 'cpu':
                $link->update(['pending_action' => ['action' => 'calc_cpu', 'step' => 'ic', 'data' => []]]);
                $bot->sendMessage(
                    $chatId,
                    "⏱️ <b>حاسبة سرعة المعالج (CPU Performance)</b>\n\nأدخل عدد التعليمات (Instruction Count) — تقدر تستخدم اختصار مثل 5M بدل 5000000:\n\nاكتب \"إلغاء\" بأي وقت لإيقاف العملية."
                );

                return;

            default:
                $this->sendCalcMainMenu($bot, $chatId);

                return;
        }
    }

    // ---------------------------------------------------------------
    // 🎛️ المقاومات والمكثفات (rc)
    // ---------------------------------------------------------------

    private function calcRcColorKeyboard(string $phase): array
    {
        $keys = match ($phase) {
            'digit' => array_keys(self::RC_DIGIT_COLORS),
            'mult' => array_keys(self::RC_MULTIPLIER_EXP),
            'tol' => array_keys(self::RC_TOLERANCE_PCT),
            default => [],
        };

        $rows = [];
        foreach (array_chunk($keys, 3) as $chunk) {
            $rows[] = array_map(
                fn ($k) => ['text' => self::RC_COLOR_LABELS[$k], 'callback_data' => 'calc:rc:pick:'.$k],
                $chunk
            );
        }
        $rows[] = [['text' => '❌ إلغاء', 'callback_data' => 'calc:cancel']];

        return $rows;
    }

    private function handleCalcRcCallback(TelegramBotApi $bot, TelegramLink $link, int|string $chatId, array $parts): void
    {
        $sub = $parts[1] ?? '';

        if ($sub === 'mode') {
            $mode = $parts[2] ?? '';

            if ($mode === 'c2v') {
                $link->update(['pending_action' => ['action' => 'calc_rc_c2v', 'step' => 'bands', 'data' => []]]);
                $bot->sendMessage($chatId, "🎨 <b>كود الألوان ← القيمة</b>\n\nكم عدد الأشرطة (bands)؟", [
                    [
                        ['text' => '4 أشرطة', 'callback_data' => 'calc:rc:bands:4'],
                        ['text' => '5 أشرطة', 'callback_data' => 'calc:rc:bands:5'],
                    ],
                    [['text' => '❌ إلغاء', 'callback_data' => 'calc:cancel']],
                ]);
            } elseif ($mode === 'v2c') {
                $link->update(['pending_action' => ['action' => 'calc_rc_v2c', 'step' => 'enter_value', 'data' => []]]);
                $bot->sendMessage(
                    $chatId,
                    "🔢 <b>القيمة ← كود الألوان</b>\n\nأدخل قيمة المقاومة بوحدة الأوم (Ω) — تقدر تستخدم اختصارات مثل 4.7k أو 220 أو 1M:\n\nاكتب \"إلغاء\" لإيقاف العملية."
                );
            } elseif ($mode === 'combo') {
                $link->update(['pending_action' => ['action' => 'calc_rc_combo', 'step' => 'pick_type', 'data' => []]]);
                $bot->sendMessage($chatId, "➕ <b>حساب التوالي/التوازي</b>\n\nأي عنصر بدك تحسب؟", [
                    [
                        ['text' => '🔧 مقاومات (R)', 'callback_data' => 'calc:rc:ctype:r'],
                        ['text' => '🔋 مكثفات (C)', 'callback_data' => 'calc:rc:ctype:c'],
                    ],
                    [['text' => '❌ إلغاء', 'callback_data' => 'calc:cancel']],
                ]);
            }

            return;
        }

        if ($sub === 'bands') {
            $pending = $link->pending_action;
            if (! is_array($pending) || ($pending['action'] ?? null) !== 'calc_rc_c2v') {
                return;
            }

            $bands = (int) ($parts[2] ?? 4);
            $bands = in_array($bands, [4, 5], true) ? $bands : 4;
            $link->update(['pending_action' => ['action' => 'calc_rc_c2v', 'step' => 'pick', 'data' => ['bands' => $bands, 'picks' => []]]]);
            $bot->sendMessage($chatId, 'اختر لون الشريط رقم 1 (رقم أول):', $this->calcRcColorKeyboard('digit'));

            return;
        }

        if ($sub === 'ctype') {
            $pending = $link->pending_action;
            if (! is_array($pending) || ($pending['action'] ?? null) !== 'calc_rc_combo') {
                return;
            }

            $ctype = ($parts[2] ?? '') === 'c' ? 'c' : 'r';
            $data = (array) ($pending['data'] ?? []);
            $data['type'] = $ctype;
            $link->update(['pending_action' => ['action' => 'calc_rc_combo', 'step' => 'pick_mode', 'data' => $data]]);

            $unitLabel = $ctype === 'c' ? 'المكثفات' : 'المقاومات';
            $bot->sendMessage($chatId, "توالي ولا توازي لـ{$unitLabel}؟", [
                [
                    ['text' => '🔗 توالي (Series)', 'callback_data' => 'calc:rc:cmode:series'],
                    ['text' => '🔀 توازي (Parallel)', 'callback_data' => 'calc:rc:cmode:parallel'],
                ],
                [['text' => '❌ إلغاء', 'callback_data' => 'calc:cancel']],
            ]);

            return;
        }

        if ($sub === 'cmode') {
            $pending = $link->pending_action;
            if (! is_array($pending) || ($pending['action'] ?? null) !== 'calc_rc_combo') {
                return;
            }

            $mode = ($parts[2] ?? '') === 'parallel' ? 'parallel' : 'series';
            $data = (array) ($pending['data'] ?? []);
            $data['mode'] = $mode;
            $link->update(['pending_action' => ['action' => 'calc_rc_combo', 'step' => 'enter_values', 'data' => $data]]);

            $unitHint = ($data['type'] ?? 'r') === 'c'
                ? 'بالفاراد — تقدر تستخدم اختصارات مثل 100n أو 10u أو 1p'
                : 'بالأوم — تقدر تستخدم اختصارات مثل 4.7k أو 1M';
            $bot->sendMessage(
                $chatId,
                "أدخل القيم مفصولة بفاصلة (,) {$unitHint}.\nمثال: 100,220,470\n\nاكتب \"إلغاء\" لإيقاف العملية."
            );

            return;
        }

        if ($sub === 'pick') {
            $pending = $link->pending_action;
            if (! is_array($pending) || ($pending['action'] ?? null) !== 'calc_rc_c2v') {
                return;
            }

            $color = (string) ($parts[2] ?? '');
            $data = (array) ($pending['data'] ?? []);
            $bands = (int) ($data['bands'] ?? 4);
            $picks = (array) ($data['picks'] ?? []);
            $picks[] = $color;
            $digitsCount = $bands - 2;

            if (count($picks) < $digitsCount) {
                $data['picks'] = $picks;
                $link->update(['pending_action' => ['action' => 'calc_rc_c2v', 'step' => 'pick', 'data' => $data]]);
                $bot->sendMessage($chatId, 'اختر لون الشريط رقم '.(count($picks) + 1).':', $this->calcRcColorKeyboard('digit'));

                return;
            }

            if (count($picks) === $digitsCount) {
                $data['picks'] = $picks;
                $link->update(['pending_action' => ['action' => 'calc_rc_c2v', 'step' => 'pick', 'data' => $data]]);
                $bot->sendMessage($chatId, 'اختر لون شريط المضاعِف (Multiplier):', $this->calcRcColorKeyboard('mult'));

                return;
            }

            if (count($picks) === $digitsCount + 1) {
                $data['picks'] = $picks;
                $link->update(['pending_action' => ['action' => 'calc_rc_c2v', 'step' => 'pick', 'data' => $data]]);
                $bot->sendMessage($chatId, 'اختر لون شريط التفاوت (Tolerance):', $this->calcRcColorKeyboard('tol'));

                return;
            }

            $link->update(['pending_action' => null]);
            $this->replyRcColorToValueResult($bot, $chatId, $picks, $digitsCount);

            return;
        }
    }

    private function replyRcColorToValueResult(TelegramBotApi $bot, int|string $chatId, array $picks, int $digitsCount): void
    {
        $digitColors = array_slice($picks, 0, $digitsCount);
        $multColor = $picks[$digitsCount] ?? 'black';
        $tolColor = $picks[$digitsCount + 1] ?? null;

        $numberStr = '';
        foreach ($digitColors as $c) {
            $numberStr .= (string) (self::RC_DIGIT_COLORS[$c] ?? 0);
        }
        $base = (float) $numberStr;
        $exp = self::RC_MULTIPLIER_EXP[$multColor] ?? 0;
        $ohms = $base * (10 ** $exp);
        $tolerance = $tolColor !== null ? (self::RC_TOLERANCE_PCT[$tolColor] ?? null) : null;

        $colorsLine = implode(' - ', array_map(fn ($c) => self::RC_COLOR_LABELS[$c] ?? $c, $picks));

        $text = "✅ <b>نتيجة كود الألوان</b>\n\n".
            "الألوان: {$colorsLine}\n".
            'القيمة: <b>'.$this->calcFormatOhms($ohms)."</b>\n".
            ($tolerance !== null ? "التفاوت: ±{$tolerance}%\n" : '').
            "\nابدأ حسبة جديدة:";

        $bot->sendMessage($chatId, $text, [
            [['text' => '🎛️ حاسبة المقاومات', 'callback_data' => 'calc:tool:rc']],
            [['text' => '🏠 القائمة الرئيسية', 'callback_data' => 'calc:menu']],
        ]);
    }

    private function handleCalcRcV2cText(TelegramBotApi $bot, TelegramLink $link, int|string $chatId, string $text): void
    {
        $value = $this->calcParseEngNumber($text);

        if ($value === null || $value <= 0) {
            $bot->sendMessage($chatId, 'قيمة غير صالحة 🙂 اكتب رقم موجب، مثلاً: 4700 أو 4.7k');

            return;
        }

        $exp = (int) floor(log10($value));
        $mantissa = $value / (10 ** $exp);
        $scaled = (int) round($mantissa * 10);

        if ($scaled >= 100) {
            $scaled = 10;
            $exp++;
        }
        if ($scaled < 10) {
            $scaled = 10;
        }

        $d1 = intdiv($scaled, 10);
        $d2 = $scaled % 10;
        $multExp = $exp - 1;
        $approxOhms = $scaled * (10 ** $multExp);

        $digit1Color = $this->calcColorForDigit($d1);
        $digit2Color = $this->calcColorForDigit($d2);
        $multColor = $this->calcColorForMultiplier($multExp);

        $link->update(['pending_action' => null]);

        $bot->sendMessage(
            $chatId,
            "🔢 <b>نتيجة القيمة ← كود الألوان</b>\n\n".
            'القيمة المدخلة: '.$this->calcFormatOhms($value)."\n".
            'أقرب قيمة قياسية (4 أشرطة): '.$this->calcFormatOhms($approxOhms)."\n\n".
            "كود الألوان:\n".
            '1) '.self::RC_COLOR_LABELS[$digit1Color]." (الرقم الأول)\n".
            '2) '.self::RC_COLOR_LABELS[$digit2Color]." (الرقم الثاني)\n".
            '3) '.self::RC_COLOR_LABELS[$multColor]." (المضاعِف)\n\n".
            'ملاحظة: شريط التفاوت (الرابع) يعتمد على دقة المقاومة الفعلية المطلوبة (عادة 🥇 ذهبي ±5% أو 🟤 بني ±1%).',
            [
                [['text' => '🎛️ حاسبة المقاومات', 'callback_data' => 'calc:tool:rc']],
                [['text' => '🏠 القائمة الرئيسية', 'callback_data' => 'calc:menu']],
            ]
        );
    }

    private function handleCalcRcComboText(TelegramBotApi $bot, TelegramLink $link, int|string $chatId, array $pending, string $text): void
    {
        $data = (array) ($pending['data'] ?? []);
        $type = ($data['type'] ?? 'r') === 'c' ? 'c' : 'r';
        $mode = ($data['mode'] ?? 'series') === 'parallel' ? 'parallel' : 'series';

        $tokens = array_filter(array_map('trim', explode(',', $text)), fn ($t) => $t !== '');
        $values = [];
        foreach ($tokens as $token) {
            $v = $this->calcParseEngNumber($token);
            if ($v === null || $v <= 0) {
                $bot->sendMessage($chatId, "قيمة غير صالحة: \"{$token}\" 🙂 اكتب القيم كلها أرقام موجبة مفصولة بفاصلة، مثلاً: 100,220,470");

                return;
            }
            $values[] = $v;
        }

        if (count($values) < 2) {
            $bot->sendMessage($chatId, 'أدخل قيمتين على الأقل مفصولتين بفاصلة، مثلاً: 100,220');

            return;
        }

        if ($type === 'r') {
            $eq = $mode === 'series'
                ? array_sum($values)
                : 1 / array_sum(array_map(fn ($v) => 1 / $v, $values));
            $eqFormatted = $this->calcFormatOhms($eq);
            $valuesFormatted = implode(', ', array_map(fn ($v) => $this->calcFormatOhms($v), $values));
            $unitLabel = 'المقاومة المكافئة';
        } else {
            $eq = $mode === 'series'
                ? 1 / array_sum(array_map(fn ($v) => 1 / $v, $values))
                : array_sum($values);
            $eqFormatted = $this->calcFormatFarads($eq);
            $valuesFormatted = implode(', ', array_map(fn ($v) => $this->calcFormatFarads($v), $values));
            $unitLabel = 'السعة المكافئة';
        }

        $modeLabel = $mode === 'series' ? 'توالي (Series)' : 'توازي (Parallel)';

        $link->update(['pending_action' => null]);

        $bot->sendMessage(
            $chatId,
            "✅ <b>نتيجة {$modeLabel}</b>\n\n".
            "القيم: {$valuesFormatted}\n".
            "{$unitLabel}: <b>{$eqFormatted}</b>",
            [
                [['text' => '🎛️ حاسبة المقاومات', 'callback_data' => 'calc:tool:rc']],
                [['text' => '🏠 القائمة الرئيسية', 'callback_data' => 'calc:menu']],
            ]
        );
    }

    private function calcColorForDigit(int $d): string
    {
        $color = array_search($d, self::RC_DIGIT_COLORS, true);

        return $color !== false ? $color : 'black';
    }

    private function calcColorForMultiplier(int $exp): string
    {
        $color = array_search($exp, self::RC_MULTIPLIER_EXP, true);

        if ($color !== false) {
            return $color;
        }

        return $exp > 9 ? 'white' : 'silver';
    }

    // ---------------------------------------------------------------
    // ⚡ مؤقت 555 ودارات RC (555)
    // ---------------------------------------------------------------

    private function handleCalc555Callback(TelegramBotApi $bot, TelegramLink $link, int|string $chatId, array $parts): void
    {
        $sub = $parts[1] ?? '';

        if ($sub !== 'mode') {
            return;
        }

        $mode = $parts[2] ?? '';

        if ($mode === 'astable') {
            $link->update(['pending_action' => ['action' => 'calc_555_astable', 'step' => 'r1', 'data' => []]]);
            $bot->sendMessage(
                $chatId,
                "🔁 <b>وضع التذبذب المستمر (Astable)</b>\n\nأدخل قيمة R1 بوحدة الأوم (Ω) — تقدر تستخدم اختصارات مثل 10k أو 2.2M:\n\nاكتب \"إلغاء\" لإيقاف العملية."
            );
        } elseif ($mode === 'mono') {
            $link->update(['pending_action' => ['action' => 'calc_555_mono', 'step' => 'r', 'data' => []]]);
            $bot->sendMessage(
                $chatId,
                "⏸️ <b>وضع النبضة الواحدة (Monostable)</b>\n\nأدخل قيمة R بوحدة الأوم (Ω) — تقدر تستخدم اختصارات مثل 10k أو 2.2M:\n\nاكتب \"إلغاء\" لإيقاف العملية."
            );
        }
    }

    private function handleCalc555Text(TelegramBotApi $bot, TelegramLink $link, int|string $chatId, array $pending, string $text): void
    {
        $action = (string) ($pending['action'] ?? '');
        $step = (string) ($pending['step'] ?? '');
        $data = (array) ($pending['data'] ?? []);

        $value = $this->calcParseEngNumber($text);
        if ($value === null || $value <= 0) {
            $bot->sendMessage($chatId, 'قيمة غير صالحة 🙂 اكتب رقم موجب، ممكن تستخدم اختصارات مثل 10k أو 100n.');

            return;
        }

        if ($action === 'calc_555_astable') {
            if ($step === 'r1') {
                $data['r1'] = $value;
                $link->update(['pending_action' => ['action' => $action, 'step' => 'r2', 'data' => $data]]);
                $bot->sendMessage($chatId, 'أدخل قيمة R2 بوحدة الأوم (Ω):');

                return;
            }

            if ($step === 'r2') {
                $data['r2'] = $value;
                $link->update(['pending_action' => ['action' => $action, 'step' => 'c', 'data' => $data]]);
                $bot->sendMessage($chatId, "أدخل قيمة C بوحدة الفاراد (F) — تقدر تستخدم اختصارات مثل 100n أو 10u:");

                return;
            }

            if ($step === 'c') {
                $r1 = (float) ($data['r1'] ?? 0);
                $r2 = (float) ($data['r2'] ?? 0);
                $c = $value;

                $thigh = 0.693 * ($r1 + $r2) * $c;
                $tlow = 0.693 * $r2 * $c;
                $t = $thigh + $tlow;
                $f = $t > 0 ? 1 / $t : 0;
                $duty = $t > 0 ? ($thigh / $t) * 100 : 0;

                $link->update(['pending_action' => null]);

                $bot->sendMessage(
                    $chatId,
                    "✅ <b>نتيجة دارة 555 (Astable)</b>\n\n".
                    'R1: '.$this->calcFormatOhms($r1).' | R2: '.$this->calcFormatOhms($r2).' | C: '.$this->calcFormatFarads($c)."\n\n".
                    'زمن الإشارة العالية (High): '.$this->calcFormatSeconds($thigh)."\n".
                    'زمن الإشارة المنخفضة (Low): '.$this->calcFormatSeconds($tlow)."\n".
                    'الدور الكامل (Period): '.$this->calcFormatSeconds($t)."\n".
                    'التردد: <b>'.$this->calcFormatHz($f).'</b>'."\n".
                    'نسبة العمل (Duty Cycle): <b>'.round($duty, 1).'%</b>',
                    [
                        [['text' => '⚡ حاسبة 555', 'callback_data' => 'calc:tool:555']],
                        [['text' => '🏠 القائمة الرئيسية', 'callback_data' => 'calc:menu']],
                    ]
                );

                return;
            }
        }

        if ($action === 'calc_555_mono') {
            if ($step === 'r') {
                $data['r'] = $value;
                $link->update(['pending_action' => ['action' => $action, 'step' => 'c', 'data' => $data]]);
                $bot->sendMessage($chatId, "أدخل قيمة C بوحدة الفاراد (F) — تقدر تستخدم اختصارات مثل 100n أو 10u:");

                return;
            }

            if ($step === 'c') {
                $r = (float) ($data['r'] ?? 0);
                $c = $value;
                $t = 1.1 * $r * $c;

                $link->update(['pending_action' => null]);

                $bot->sendMessage(
                    $chatId,
                    "✅ <b>نتيجة دارة 555 (Monostable)</b>\n\n".
                    'R: '.$this->calcFormatOhms($r).' | C: '.$this->calcFormatFarads($c)."\n\n".
                    'عرض النبضة (Pulse Width): <b>'.$this->calcFormatSeconds($t).'</b>',
                    [
                        [['text' => '⚡ حاسبة 555', 'callback_data' => 'calc:tool:555']],
                        [['text' => '🏠 القائمة الرئيسية', 'callback_data' => 'calc:menu']],
                    ]
                );

                return;
            }
        }
    }

    // ---------------------------------------------------------------
    // 🔋 استهلاك الطاقة (power)
    // ---------------------------------------------------------------

    private function handleCalcPowerText(TelegramBotApi $bot, TelegramLink $link, int|string $chatId, array $pending, string $text): void
    {
        $step = (string) ($pending['step'] ?? '');
        $data = (array) ($pending['data'] ?? []);

        $value = $this->calcParsePlainFloat($text);
        if ($value === null || $value <= 0) {
            $bot->sendMessage($chatId, 'رقم غير صالح 🙂 اكتب رقم موجب، مثلاً: 250');

            return;
        }

        if ($step === 'current') {
            $data['current_ma'] = $value;
            $link->update(['pending_action' => ['action' => 'calc_power', 'step' => 'voltage', 'data' => $data]]);
            $bot->sendMessage($chatId, 'أدخل جهد التشغيل بوحدة الفولت (V)، مثلاً: 5');

            return;
        }

        if ($step === 'voltage') {
            $data['voltage_v'] = $value;
            $link->update(['pending_action' => ['action' => 'calc_power', 'step' => 'capacity', 'data' => $data]]);
            $bot->sendMessage($chatId, 'أدخل سعة البطارية بوحدة mAh (مللي أمبير-ساعة)، مثلاً: 2000');

            return;
        }

        if ($step === 'capacity') {
            $current = (float) ($data['current_ma'] ?? 0);
            $voltage = (float) ($data['voltage_v'] ?? 0);
            $capacity = $value;

            $powerW = ($voltage * $current) / 1000;
            $lifeHours = $current > 0 ? $capacity / $current : 0;
            $lifeDays = $lifeHours / 24;

            $link->update(['pending_action' => null]);

            $bot->sendMessage(
                $chatId,
                "✅ <b>نتيجة استهلاك الطاقة</b>\n\n".
                "التيار: {$current} mA | الجهد: {$voltage} V | سعة البطارية: {$capacity} mAh\n\n".
                'الاستهلاك: <b>'.round($powerW, 3)." واط (W)</b>\n".
                'العمر التقديري للبطارية: <b>'.round($lifeHours, 1).' ساعة</b> (≈ '.round($lifeDays, 2).' يوم)',
                [
                    [['text' => '🔋 حاسبة الطاقة', 'callback_data' => 'calc:tool:power']],
                    [['text' => '🏠 القائمة الرئيسية', 'callback_data' => 'calc:menu']],
                ]
            );

            return;
        }
    }

    // ---------------------------------------------------------------
    // 🔄 الأنظمة العددية والمنطق (base)
    // ---------------------------------------------------------------

    private function handleCalcBaseCallback(TelegramBotApi $bot, TelegramLink $link, int|string $chatId, array $parts): void
    {
        $sub = $parts[1] ?? '';

        if ($sub === 'mode') {
            $mode = $parts[2] ?? '';

            if ($mode === 'conv') {
                $bot->sendMessage($chatId, "🔁 <b>تحويل بين الأنظمة العددية</b>\n\nاختر النظام العددي يلي بدك تدخل الرقم فيه:", [
                    [
                        ['text' => '٢ ثنائي (Binary)', 'callback_data' => 'calc:base:convbase:bin'],
                        ['text' => '٨ ثماني (Octal)', 'callback_data' => 'calc:base:convbase:oct'],
                    ],
                    [
                        ['text' => '١٠ عشري (Decimal)', 'callback_data' => 'calc:base:convbase:dec'],
                        ['text' => '١٦ ست عشري (Hex)', 'callback_data' => 'calc:base:convbase:hex'],
                    ],
                    [['text' => '⬅️ رجوع', 'callback_data' => 'calc:tool:base']],
                ]);
            } elseif ($mode === 'logic') {
                $bot->sendMessage($chatId, "🔣 <b>العمليات المنطقية</b>\n\nاختر العملية:", [
                    [
                        ['text' => 'AND', 'callback_data' => 'calc:base:op:and'],
                        ['text' => 'OR', 'callback_data' => 'calc:base:op:or'],
                        ['text' => 'XOR', 'callback_data' => 'calc:base:op:xor'],
                    ],
                    [
                        ['text' => 'NAND', 'callback_data' => 'calc:base:op:nand'],
                        ['text' => 'NOT', 'callback_data' => 'calc:base:op:not'],
                    ],
                    [['text' => '⬅️ رجوع', 'callback_data' => 'calc:tool:base']],
                ]);
            }

            return;
        }

        if ($sub === 'convbase') {
            $base = in_array($parts[2] ?? '', ['bin', 'oct', 'dec', 'hex'], true) ? $parts[2] : 'dec';
            $link->update(['pending_action' => ['action' => 'calc_base_conv', 'step' => 'enter_value', 'data' => ['base' => $base]]]);

            $hints = [
                'bin' => 'أرقام 0 و1 فقط، مثلاً: 10110',
                'oct' => 'أرقام من 0 إلى 7، مثلاً: 572',
                'dec' => 'رقم عشري عادي (يقبل السالب)، مثلاً: -42',
                'hex' => 'أرقام 0-9 وحروف A-F، مثلاً: 1A3F',
            ];
            $bot->sendMessage($chatId, 'أدخل الرقم بالنظام المطلوب — '.($hints[$base] ?? '')."\n\nاكتب \"إلغاء\" لإيقاف العملية.");

            return;
        }

        if ($sub === 'op') {
            $op = in_array($parts[2] ?? '', ['and', 'or', 'xor', 'nand', 'not'], true) ? $parts[2] : 'and';
            $link->update(['pending_action' => ['action' => 'calc_base_logic', 'step' => 'operand1', 'data' => ['op' => $op]]]);
            $bot->sendMessage($chatId, "أدخل الرقم الثنائي الأول (0 و1 فقط)، مثلاً: 1011\n\nاكتب \"إلغاء\" لإيقاف العملية.");

            return;
        }
    }

    private function handleCalcBaseConvText(TelegramBotApi $bot, TelegramLink $link, int|string $chatId, array $pending, string $text): void
    {
        $base = (string) ($pending['data']['base'] ?? 'dec');
        $normalized = trim($text);

        $patterns = [
            'bin' => '/^[01]+$/',
            'oct' => '/^[0-7]+$/',
            'dec' => '/^-?\d+$/',
            'hex' => '/^[0-9a-fA-F]+$/',
        ];

        if (! preg_match($patterns[$base] ?? '/^$/', $normalized)) {
            $bot->sendMessage($chatId, 'صيغة غير صالحة لهذا النظام العددي 🙂 جرّب مرة ثانية، أو اكتب "إلغاء".');

            return;
        }

        $dec = match ($base) {
            'bin' => bindec($normalized),
            'oct' => octdec($normalized),
            'hex' => hexdec($normalized),
            default => (int) $normalized,
        };
        $dec = (int) $dec;

        $unsigned = $dec < 0 ? ($dec & 0xFFFFFFFF) : $dec;
        $binText = decbin($unsigned);
        $octText = decoct($unsigned);
        $hexText = strtoupper(dechex($unsigned));

        $twosComplement = '';
        if ($dec >= -128 && $dec <= 127) {
            $twosComplement = str_pad(decbin($dec & 0xFF), 8, '0', STR_PAD_LEFT).' (متمم اثنين، 8-bit)';
        } elseif ($dec >= -32768 && $dec <= 32767) {
            $twosComplement = str_pad(decbin($dec & 0xFFFF), 16, '0', STR_PAD_LEFT).' (متمم اثنين، 16-bit)';
        }

        $link->update(['pending_action' => null]);

        $bot->sendMessage(
            $chatId,
            "✅ <b>نتيجة التحويل</b>\n\n".
            "ثنائي (Binary): <code>{$binText}</code>\n".
            "ثماني (Octal): <code>{$octText}</code>\n".
            "عشري (Decimal): <code>{$dec}</code>\n".
            "ست عشري (Hex): <code>{$hexText}</code>".
            ($twosComplement !== '' ? "\n\nمتمم الاثنين: <code>{$twosComplement}</code>" : ''),
            [
                [['text' => '🔄 حاسبة الأنظمة العددية', 'callback_data' => 'calc:tool:base']],
                [['text' => '🏠 القائمة الرئيسية', 'callback_data' => 'calc:menu']],
            ]
        );
    }

    private function handleCalcBaseLogicText(TelegramBotApi $bot, TelegramLink $link, int|string $chatId, array $pending, string $text): void
    {
        $op = (string) ($pending['data']['op'] ?? 'and');
        $step = (string) ($pending['step'] ?? 'operand1');
        $data = (array) ($pending['data'] ?? []);
        $normalized = trim($text);

        if (! preg_match('/^[01]+$/', $normalized)) {
            $bot->sendMessage($chatId, 'أدخل رقم ثنائي صالح (0 و1 فقط) 🙂 أو اكتب "إلغاء".');

            return;
        }

        if ($op === 'not') {
            $result = strtr($normalized, ['0' => '1', '1' => '0']);
            $link->update(['pending_action' => null]);
            $bot->sendMessage(
                $chatId,
                "✅ <b>نتيجة NOT</b>\n\n".
                "المدخل: <code>{$normalized}</code>\n".
                "النتيجة: <code>{$result}</code> (عشري: ".bindec($result).')',
                [
                    [['text' => '🔣 حاسبة المنطق', 'callback_data' => 'calc:tool:base']],
                    [['text' => '🏠 القائمة الرئيسية', 'callback_data' => 'calc:menu']],
                ]
            );

            return;
        }

        if ($step === 'operand1') {
            $data['a'] = $normalized;
            $link->update(['pending_action' => ['action' => 'calc_base_logic', 'step' => 'operand2', 'data' => $data]]);
            $bot->sendMessage($chatId, 'أدخل الرقم الثنائي الثاني (0 و1 فقط):');

            return;
        }

        // step === 'operand2'
        $a = (string) ($data['a'] ?? '0');
        $b = $normalized;
        $len = max(strlen($a), strlen($b));
        $a = str_pad($a, $len, '0', STR_PAD_LEFT);
        $b = str_pad($b, $len, '0', STR_PAD_LEFT);
        $ai = bindec($a);
        $bi = bindec($b);
        $mask = (1 << $len) - 1;

        $result = match ($op) {
            'and' => $ai & $bi,
            'or' => $ai | $bi,
            'xor' => $ai ^ $bi,
            'nand' => (~($ai & $bi)) & $mask,
            default => $ai & $bi,
        };

        $resultBin = str_pad(decbin($result), $len, '0', STR_PAD_LEFT);
        $opLabel = strtoupper($op);

        $link->update(['pending_action' => null]);

        $bot->sendMessage(
            $chatId,
            "✅ <b>نتيجة {$opLabel}</b>\n\n".
            "A: <code>{$a}</code>\n".
            "B: <code>{$b}</code>\n".
            "النتيجة: <code>{$resultBin}</code> (عشري: {$result})",
            [
                [['text' => '🔣 حاسبة المنطق', 'callback_data' => 'calc:tool:base']],
                [['text' => '🏠 القائمة الرئيسية', 'callback_data' => 'calc:menu']],
            ]
        );
    }

    // ---------------------------------------------------------------
    // 🌐 الشبكات وعناوين IP (subnet)
    // ---------------------------------------------------------------

    private function handleCalcSubnetText(TelegramBotApi $bot, TelegramLink $link, int|string $chatId, string $text): void
    {
        $normalized = trim($text);

        if (! preg_match('/^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})\/(\d{1,2})$/', $normalized, $m)) {
            $bot->sendMessage($chatId, "صيغة غير صالحة 🙂 اكتب العنوان بالشكل: 192.168.1.10/24");

            return;
        }

        $octets = [(int) $m[1], (int) $m[2], (int) $m[3], (int) $m[4]];
        $cidr = (int) $m[5];

        foreach ($octets as $octet) {
            if ($octet < 0 || $octet > 255) {
                $bot->sendMessage($chatId, 'كل جزء من الـIP لازم يكون بين 0 و255 🙂 جرّب مرة ثانية.');

                return;
            }
        }

        if ($cidr < 0 || $cidr > 32) {
            $bot->sendMessage($chatId, 'الـCIDR لازم يكون رقم بين 0 و32 🙂 جرّب مرة ثانية.');

            return;
        }

        $ip = implode('.', $octets);
        $ipLong = ip2long($ip);

        if ($ipLong === false) {
            $bot->sendMessage($chatId, 'عنوان IP غير صالح 🙂 جرّب مرة ثانية.');

            return;
        }

        $ipLong &= 0xFFFFFFFF;
        $mask = $cidr === 0 ? 0 : ((0xFFFFFFFF << (32 - $cidr)) & 0xFFFFFFFF);
        $network = $ipLong & $mask;
        $broadcast = $network | (~$mask & 0xFFFFFFFF);
        $hostBits = 32 - $cidr;

        if ($hostBits === 0) {
            $usableLine = 'مضيف وحيد (Host Route /32) — لا يوجد نطاق قابل للاستخدام.';
            $hostCount = 1;
        } elseif ($hostBits === 1) {
            $usableLine = long2ip($network).' و '.long2ip($broadcast).' (شبكة نقطة-لنقطة /31، RFC 3021)';
            $hostCount = 2;
        } else {
            $usableFirst = $network + 1;
            $usableLast = $broadcast - 1;
            $usableLine = long2ip($usableFirst).' — '.long2ip($usableLast);
            $hostCount = (2 ** $hostBits) - 2;
        }

        $link->update(['pending_action' => null]);

        $bot->sendMessage(
            $chatId,
            "✅ <b>نتيجة حاسبة الشبكات</b>\n\n".
            "العنوان: {$ip}/{$cidr}\n".
            'قناع الشبكة (Subnet Mask): <code>'.long2ip($mask)."</code>\n".
            'معرّف الشبكة (Network ID): <code>'.long2ip($network)."</code>\n".
            'عنوان البث (Broadcast): <code>'.long2ip($broadcast)."</code>\n".
            "نطاق العناوين القابلة للاستخدام: <code>{$usableLine}</code>\n".
            'عدد المضيفين الممكن: <b>'.number_format($hostCount).'</b>',
            [
                [['text' => '🌐 حاسبة الشبكات', 'callback_data' => 'calc:tool:subnet']],
                [['text' => '🏠 القائمة الرئيسية', 'callback_data' => 'calc:menu']],
            ]
        );
    }

    // ---------------------------------------------------------------
    // 📊 التعقيد الزمني Big-O (bigo)
    // ---------------------------------------------------------------

    private function sendCalcBigoMenu(TelegramBotApi $bot, int|string $chatId): void
    {
        $bot->sendMessage($chatId, "📊 <b>حاسبة التعقيد الزمني (Big-O)</b>\n\nاختر شكل الحلقات/الخوارزمية بالكود:", [
            [['text' => 'ثابت — بدون حلقات O(1)', 'callback_data' => 'calc:bigo:pick:o1']],
            [['text' => 'حلقة تنصيف (بحث ثنائي) O(log n)', 'callback_data' => 'calc:bigo:pick:ologn']],
            [['text' => 'حلقة واحدة O(n)', 'callback_data' => 'calc:bigo:pick:on']],
            [['text' => 'حلقة + تنصيف (فرز سريع/دمج) O(n log n)', 'callback_data' => 'calc:bigo:pick:onlogn']],
            [['text' => 'حلقتان متداخلتان O(n²)', 'callback_data' => 'calc:bigo:pick:on2']],
            [['text' => 'تفرّع تكراري مضاعف O(2^n)', 'callback_data' => 'calc:bigo:pick:o2n']],
            [['text' => '⬅️ رجوع', 'callback_data' => 'calc:cat:algo']],
        ]);
    }

    private function handleCalcBigoCallback(TelegramBotApi $bot, int|string $chatId, array $parts): void
    {
        $sub = $parts[1] ?? '';
        if ($sub !== 'pick') {
            return;
        }

        $key = $parts[2] ?? '';

        $configs = [
            'o1' => [
                'label' => 'O(1) — زمن ثابت (لا يعتمد على حجم المدخلات)',
                'ns' => [10, 100, 1000],
                'fn' => fn ($n) => 1,
            ],
            'ologn' => [
                'label' => 'O(log n) — حلقة تُنصّف حجم المشكلة بكل تكرار (مثل البحث الثنائي)',
                'ns' => [10, 100, 1000],
                'fn' => fn ($n) => max(1, (int) ceil(log($n, 2))),
            ],
            'on' => [
                'label' => 'O(n) — حلقة واحدة تمر على كل عنصر مرة',
                'ns' => [10, 100, 1000],
                'fn' => fn ($n) => $n,
            ],
            'onlogn' => [
                'label' => 'O(n log n) — حلقة مع تنصيف بداخلها (مثل الفرز السريع/فرز الدمج)',
                'ns' => [10, 100, 1000],
                'fn' => fn ($n) => (int) ceil($n * max(1, log($n, 2))),
            ],
            'on2' => [
                'label' => 'O(n²) — حلقتان متداخلتان، كل عنصر يُقارَن بكل عنصر',
                'ns' => [10, 100, 1000],
                'fn' => fn ($n) => $n * $n,
            ],
            'o2n' => [
                'label' => 'O(2^n) — كل عنصر إضافي يضاعف عدد الحالات (تفرّع تكراري)',
                'ns' => [5, 10, 20],
                'fn' => fn ($n) => 2 ** $n,
            ],
        ];

        if (! isset($configs[$key])) {
            return;
        }

        $cfg = $configs[$key];
        $lines = [];
        foreach ($cfg['ns'] as $n) {
            $ops = ($cfg['fn'])($n);
            $lines[] = "• عند n = {$n} → تقريبًا ".number_format((float) $ops, 0).' عملية';
        }

        $bot->sendMessage(
            $chatId,
            '📊 <b>'.$cfg['label'].'</b>'."\n\n".
            implode("\n", $lines).
            "\n\nهذا تقدير تقريبي لعدد العمليات، مش قياس فعلي — يفيدك لمقارنة كفاءة الخوارزميات مع تكبير حجم المدخلات.",
            [
                [['text' => '📊 رجوع لقائمة Big-O', 'callback_data' => 'calc:tool:bigo']],
                [['text' => '🏠 القائمة الرئيسية', 'callback_data' => 'calc:menu']],
            ]
        );
    }

    // ---------------------------------------------------------------
    // ⏱️ سرعة المعالج CPU Performance (cpu)
    // ---------------------------------------------------------------

    private function handleCalcCpuText(TelegramBotApi $bot, TelegramLink $link, int|string $chatId, array $pending, string $text): void
    {
        $step = (string) ($pending['step'] ?? '');
        $data = (array) ($pending['data'] ?? []);

        if ($step === 'ic') {
            $ic = $this->calcParseEngNumber($text);
            if ($ic === null || $ic <= 0) {
                $bot->sendMessage($chatId, 'رقم غير صالح 🙂 اكتب رقم موجب، تقدر تستخدم اختصار مثل 5M.');

                return;
            }
            $data['ic'] = round($ic);
            $link->update(['pending_action' => ['action' => 'calc_cpu', 'step' => 'cpi', 'data' => $data]]);
            $bot->sendMessage($chatId, 'أدخل معدّل الدورات لكل تعليمة (CPI)، مثلاً: 1.5');

            return;
        }

        if ($step === 'cpi') {
            $cpi = $this->calcParsePlainFloat($text);
            if ($cpi === null || $cpi <= 0) {
                $bot->sendMessage($chatId, 'رقم غير صالح 🙂 اكتب رقم موجب، مثلاً: 1.5');

                return;
            }
            $data['cpi'] = $cpi;
            $link->update(['pending_action' => ['action' => 'calc_cpu', 'step' => 'freq', 'data' => $data]]);
            $bot->sendMessage($chatId, 'أدخل تردد المعالج بوحدة الميجاهرتز (MHz)، مثلاً: 2000 (يعني 2 GHz)');

            return;
        }

        if ($step === 'freq') {
            $freqMhz = $this->calcParsePlainFloat($text);
            if ($freqMhz === null || $freqMhz <= 0) {
                $bot->sendMessage($chatId, 'رقم غير صالح 🙂 اكتب رقم موجب، مثلاً: 2000');

                return;
            }

            $ic = (float) ($data['ic'] ?? 0);
            $cpi = (float) ($data['cpi'] ?? 0);
            $freqHz = $freqMhz * 1e6;
            $cycles = $ic * $cpi;
            $timeSeconds = $freqHz > 0 ? $cycles / $freqHz : 0;
            $mips = $cpi > 0 ? ($freqHz / $cpi) / 1e6 : 0;

            $link->update(['pending_action' => null]);

            $bot->sendMessage(
                $chatId,
                "✅ <b>نتيجة أداء المعالج</b>\n\n".
                'عدد التعليمات: '.number_format($ic)." | CPI: {$cpi} | التردد: {$freqMhz} MHz\n\n".
                'إجمالي الدورات: <b>'.number_format($cycles)."</b>\n".
                'زمن التنفيذ: <b>'.$this->calcFormatSeconds($timeSeconds).'</b> (Execution Time = IC × CPI × Clock Cycle Time)'."\n".
                'الأداء: <b>'.round($mips, 2).' MIPS</b> (مليون تعليمة/ثانية)',
                [
                    [['text' => '⏱️ حاسبة المعالج', 'callback_data' => 'calc:tool:cpu']],
                    [['text' => '🏠 القائمة الرئيسية', 'callback_data' => 'calc:menu']],
                ]
            );

            return;
        }
    }

    // ---------------------------------------------------------------
    // موجّه النص الحر المشترك لكل حاسبات "calc_*" + أدوات تنسيق/تحليل عامة
    // ---------------------------------------------------------------

    private function handleCalcTextInput(TelegramBotApi $bot, TelegramLink $link, int|string $chatId, string $text): void
    {
        $normalized = trim($text);

        if (in_array($normalized, ['إلغاء', 'الغاء', 'cancel'], true)) {
            $link->update(['pending_action' => null]);
            $bot->sendMessage($chatId, 'تم إلغاء العملية ✅');

            return;
        }

        $pending = $link->pending_action;
        $action = (string) ($pending['action'] ?? '');

        if ($action === 'calc_rc_v2c') {
            $this->handleCalcRcV2cText($bot, $link, $chatId, $normalized);

            return;
        }

        if ($action === 'calc_rc_combo') {
            $this->handleCalcRcComboText($bot, $link, $chatId, $pending, $normalized);

            return;
        }

        if (in_array($action, ['calc_555_astable', 'calc_555_mono'], true)) {
            $this->handleCalc555Text($bot, $link, $chatId, $pending, $normalized);

            return;
        }

        if ($action === 'calc_power') {
            $this->handleCalcPowerText($bot, $link, $chatId, $pending, $normalized);

            return;
        }

        if ($action === 'calc_base_conv') {
            $this->handleCalcBaseConvText($bot, $link, $chatId, $pending, $normalized);

            return;
        }

        if ($action === 'calc_base_logic') {
            $this->handleCalcBaseLogicText($bot, $link, $chatId, $pending, $normalized);

            return;
        }

        if ($action === 'calc_subnet') {
            $this->handleCalcSubnetText($bot, $link, $chatId, $normalized);

            return;
        }

        if ($action === 'calc_cpu') {
            $this->handleCalcCpuText($bot, $link, $chatId, $pending, $normalized);

            return;
        }

        $bot->sendMessage($chatId, 'استخدم الأزرار يلي فوق 🙂 أو اكتب "إلغاء" لإيقاف العملية.');
    }

    /*
     * يقبل أرقام هندسية بصيغة SI مختصرة: k/K=×1e3، M=×1e6، m=×1e-3،
     * u/U (أو µ/μ)=×1e-6، n/N=×1e-9، p/P=×1e-12، g/G=×1e9 — مستخدم بكل
     * قيم المقاومات/المكثفات/دارات 555/عدد تعليمات المعالج. يفرّق بين
     * "m" (ميللي) و"M" (ميغا) حسب حالة الأحرف (قاعدة هندسية معتادة).
     */
    private function calcParseEngNumber(string $text): ?float
    {
        $t = trim($text);
        $t = str_replace(['µ', 'μ', ' '], ['u', 'u', ''], $t);
        $t = str_replace(',', '.', $t);

        if (! preg_match('/^(-?\d+(?:\.\d+)?)([kKmMuUnNpPgG])?$/', $t, $m)) {
            return null;
        }

        $number = (float) $m[1];
        $suffix = $m[2] ?? '';

        $multipliers = [
            'k' => 1e3, 'K' => 1e3,
            'M' => 1e6, 'm' => 1e-3,
            'u' => 1e-6, 'U' => 1e-6,
            'n' => 1e-9, 'N' => 1e-9,
            'p' => 1e-12, 'P' => 1e-12,
            'g' => 1e9, 'G' => 1e9,
        ];

        return $number * ($multipliers[$suffix] ?? 1.0);
    }

    private function calcParsePlainFloat(string $text, bool $allowNegative = false): ?float
    {
        $t = str_replace(',', '.', trim($text));
        $pattern = $allowNegative ? '/^-?\d+(\.\d+)?$/' : '/^\d+(\.\d+)?$/';

        if (! preg_match($pattern, $t)) {
            return null;
        }

        return (float) $t;
    }

    private function calcFormatOhms(float $ohms): string
    {
        $abs = abs($ohms);
        if ($abs >= 1e6) {
            return round($ohms / 1e6, 3).' MΩ';
        }
        if ($abs >= 1e3) {
            return round($ohms / 1e3, 3).' kΩ';
        }

        return round($ohms, 3).' Ω';
    }

    private function calcFormatFarads(float $f): string
    {
        $abs = abs($f);
        if ($abs >= 1) {
            return round($f, 6).' F';
        }
        if ($abs >= 1e-3) {
            return round($f * 1e3, 4).' mF';
        }
        if ($abs >= 1e-6) {
            return round($f * 1e6, 4).' µF';
        }
        if ($abs >= 1e-9) {
            return round($f * 1e9, 4).' nF';
        }

        return round($f * 1e12, 4).' pF';
    }

    private function calcFormatSeconds(float $s): string
    {
        $abs = abs($s);
        if ($abs >= 1) {
            return round($s, 4).' s';
        }
        if ($abs >= 1e-3) {
            return round($s * 1e3, 4).' ms';
        }
        if ($abs >= 1e-6) {
            return round($s * 1e6, 4).' µs';
        }

        return round($s * 1e9, 4).' ns';
    }

    private function calcFormatHz(float $hz): string
    {
        $abs = abs($hz);
        if ($abs >= 1e6) {
            return round($hz / 1e6, 4).' MHz';
        }
        if ($abs >= 1e3) {
            return round($hz / 1e3, 4).' kHz';
        }

        return round($hz, 4).' Hz';
    }

    /*
     * =====================================================================
     * 🌐 التطبيق المصغّر (Mini App) — جلسة سابعة، جزء 1 (مُصلَح جزء 2)
     * =====================================================================
     * قرار مدروس بدل بناء موقع مصغّر منفصل: نفتح الموقع الحالي الشغّال
     * فعليًا داخل تيليجرام نفسه — صفر ازدواجية، أسرع، وأضمن.
     *
     * ⚠ إصلاح: أول نسخة استخدمت زر 'url' عادي — وهذا يفتح متصفح خارجي
     * (كروم على أندرويد/ديسكتوب) بدل نافذة تيليجرام المدمجة، لأنه 'url'
     * مجرد رابط عادي بنظر تيليجرام. الحل الصحيح هو نوع زر مختلف كليًا
     * اسمه Web App ('web_app' => ['url' => ...]) — هذا فعليًا يفتح
     * الصفحة بنافذة WebView داخل تطبيق تيليجرام نفسه (بلا خروج، بثيم
     * تيليجرام، وزر رجوع مدمج). لا يحتاج أي إعداد BotFather إضافي
     * (/setdomain) طالما الزر جوّا inline keyboard برسالة محادثة خاصة
     * (مو Menu Button الثابت ولا Attachment Menu) — فقط الرابط لازم
     * HTTPS (متوفر أصلًا).
     */
    private function sendMiniAppCard(TelegramBotApi $bot, int|string $chatId): void
    {
        $url = rtrim((string) config('app.frontend_url'), '/').'/';

        $bot->sendMessage(
            $chatId,
            "🌐 <b>التطبيق المصغّر</b>\n\n".
            'افتح موقع دليل الطالب كامل المزايا (تصفح المساقات، الملفات، لوحة حسابك...) بنافذة مدمجة من غير ما تطلع من تيليجرام:',
            [
                [['text' => '🚀 فتح الموقع', 'web_app' => ['url' => $url]]],
            ]
        );
    }

    /*
     * =====================================================================
     * 🙋 مساعدة الطلاب (Peer Help / أسئلة وأجوبة لكل مادة) — جلسة سابعة، جزء 1
     * =====================================================================
     * سؤال يطرحه طالب بمادة هو مسجَّل فيها فعليًا (my_courses.status=
     * registered) يُبَث فورًا لبقية طلاب نفس المادة المرتبطين بالبوت،
     * ويُخزَّن دائمًا بأرشيف قابل للتصفح لكل مادة (يفيد دفعات لاحقة
     * تاخد نفس المادة). هويّة السائل/المجيب الحقيقية تُخزَّن بقاعدة
     * البيانات دائمًا لكن تُعرض للطلاب الآخرين بشكل عام ("طالب") فقط.
     */
    private function sendQaMainMenu(TelegramBotApi $bot, int|string $chatId): void
    {
        $bot->sendMessage(
            $chatId,
            "🙋 <b>مساعدة الطلاب</b>\n\nاسأل زملاءك بمادة أنت مسجَّل فيها، ساعدهم بالإجابة على أسئلتهم، أو تصفّح أسئلة/أجوبة سابقة لأي مادة (مفيدة جدًا وقت المذاكرة).",
            [
                [['text' => '❓ اطرح سؤال جديد', 'callback_data' => 'qa:ask']],
                [['text' => '🗂 ساعد بالإجابة على أسئلة', 'callback_data' => 'qa:open']],
                [['text' => '📚 تصفّح أسئلة مادة', 'callback_data' => 'qa:browse']],
            ]
        );
    }

    private function qaRegisteredCourses(int $userId)
    {
        return \App\Models\User::query()->findOrFail($userId)
            ->myCourses()
            ->wherePivot('status', 'registered')
            ->orderBy('name_ar')
            ->get();
    }

    private function qaCourseButtonLabel(Course $course): string
    {
        return mb_substr(trim(($course->code ? $course->code.' — ' : '').(string) ($course->name_ar ?: $course->name_en)), 0, 40);
    }

    private function handleQaCallback(TelegramBotApi $bot, array $callbackQuery): void
    {
        $callbackId = (string) ($callbackQuery['id'] ?? '');
        $chatId = $callbackQuery['message']['chat']['id'] ?? null;
        $data = (string) ($callbackQuery['data'] ?? '');
        $action = substr($data, strlen('qa:'));
        $parts = explode(':', $action);
        $key = $parts[0] ?? '';

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

        $bot->answerCallbackQuery($callbackId);

        switch ($key) {
            case 'menu':
                $link->update(['pending_action' => null]);
                $this->sendQaMainMenu($bot, $chatId);

                return;

            case 'cancel':
                $link->update(['pending_action' => null]);
                $bot->sendMessage($chatId, 'تم إلغاء العملية ✅');

                return;

            case 'ask':
                $link->update(['pending_action' => null]);
                $courses = $this->qaRegisteredCourses($link->user_id);

                if ($courses->isEmpty()) {
                    $bot->sendMessage(
                        $chatId,
                        'لسا ما عندك أي مادة مسجَّلة بـ"📖 مساقاتي الحالية" — سجّل موادك الحالية أولًا حتى تقدر تسأل وتوصل زملاءك بنفس المادة.'
                    );

                    return;
                }

                $rows = [];
                foreach ($courses as $course) {
                    $rows[] = [['text' => $this->qaCourseButtonLabel($course), 'callback_data' => 'qa:askcourse:'.$course->id]];
                }
                $rows[] = [['text' => '❌ إلغاء', 'callback_data' => 'qa:cancel']];

                $bot->sendMessage($chatId, '❓ اختر المادة يلي بدك تسأل فيها:', $rows);

                return;

            case 'askcourse':
                $courseId = (int) ($parts[1] ?? 0);
                $isRegistered = $this->qaRegisteredCourses($link->user_id)->contains('id', $courseId);

                if (! $isRegistered) {
                    $bot->sendMessage($chatId, 'هذه المادة مش من مساقاتك الحالية 🙂');

                    return;
                }

                $link->update(['pending_action' => ['action' => 'qa_ask', 'step' => 'enter_question', 'data' => ['course_id' => $courseId]]]);
                $bot->sendMessage($chatId, "✍️ اكتب سؤالك (بوضوح قدر الإمكان حتى يقدر زملاؤك يساعدوك):\n\nاكتب \"إلغاء\" لإيقاف العملية.");

                return;

            case 'browse':
                $link->update(['pending_action' => null]);
                $courses = $this->qaRegisteredCourses($link->user_id);

                $rows = [];
                foreach ($courses as $course) {
                    $rows[] = [['text' => $this->qaCourseButtonLabel($course), 'callback_data' => 'qa:list:'.$course->id.':0']];
                }
                $rows[] = [['text' => '🔍 بحث عن مادة تانية', 'callback_data' => 'qa:searchcourse']];
                $rows[] = [['text' => '❌ إلغاء', 'callback_data' => 'qa:cancel']];

                $bot->sendMessage($chatId, '📚 اختر مادة لتصفّح أسئلتها، أو دور عن مادة تانية:', $rows);

                return;

            case 'searchcourse':
                $link->update(['pending_action' => ['action' => 'qa_searchcourse', 'step' => 'query', 'data' => []]]);
                $bot->sendMessage($chatId, "🔍 اكتب اسم المادة أو رمزها:\n\nاكتب \"إلغاء\" لإيقاف العملية.");

                return;

            case 'list':
                $courseId = (int) ($parts[1] ?? 0);
                $page = max(0, (int) ($parts[2] ?? 0));
                $this->sendQaQuestionList($bot, $chatId, $courseId, $page);

                return;

            case 'view':
                $questionId = (int) ($parts[1] ?? 0);
                $this->sendQaQuestionView($bot, $chatId, $questionId);

                return;

            case 'answer':
                $questionId = (int) ($parts[1] ?? 0);
                $question = \App\Models\StudentQuestion::query()->find($questionId);

                if (! $question) {
                    $bot->sendMessage($chatId, 'هذا السؤال ما عاد موجود.');

                    return;
                }

                $link->update(['pending_action' => ['action' => 'qa_answer', 'step' => 'enter_answer', 'data' => ['question_id' => $questionId]]]);
                $bot->sendMessage($chatId, "✍️ اكتب إجابتك:\n\nاكتب \"إلغاء\" لإيقاف العملية.");

                return;

            case 'open':
                $link->update(['pending_action' => null]);
                $this->sendQaOpenQuestions($bot, $chatId, $link->user_id, 0);

                return;

            case 'openpage':
                $page = max(0, (int) ($parts[1] ?? 0));
                $this->sendQaOpenQuestions($bot, $chatId, $link->user_id, $page);

                return;

            case 'vote':
                $answerId = (int) ($parts[1] ?? 0);
                $direction = ($parts[2] ?? '') === 'down' ? 'down' : 'up';
                $messageId = (int) ($callbackQuery['message']['message_id'] ?? 0);
                $this->handleQaVote($bot, $link, $chatId, $messageId, $answerId, $direction);

                return;

            default:
                return;
        }
    }

    /*
     * "🗂 ساعد بالإجابة على أسئلة" — طلب المستخدم صراحة إتاحة مسار
     * مباشر للطالب يبحث فيه عن أسئلة يعرف جوابها بدل ما يضطر يدوّر
     * مادة بمادة. يجمع الأسئلة "بدون أي إجابة بعد" من كل مساقاته
     * الحالية المسجَّلة، الأحدث أولًا — أكثر شي يحتاج مساعدة فورية.
     */
    private function sendQaOpenQuestions(TelegramBotApi $bot, int|string $chatId, int $userId, int $page): void
    {
        $courseIds = $this->qaRegisteredCourses($userId)->pluck('id')->all();

        if ($courseIds === []) {
            $bot->sendMessage(
                $chatId,
                'لسا ما عندك أي مادة مسجَّلة بـ"📖 مساقاتي الحالية" — سجّل موادك الحالية أولًا حتى تقدر تشوف أسئلة تحتاج مساعدة.'
            );

            return;
        }

        $perPage = 5;
        $baseQuery = \App\Models\StudentQuestion::query()
            ->whereIn('course_id', $courseIds)
            ->where('answers_count', 0);

        $total = (clone $baseQuery)->count();

        if ($total === 0) {
            $bot->sendMessage(
                $chatId,
                '🎉 ما في أي سؤال بدون إجابة حاليًا بمساقاتك — كل شي متجاوب عليه!',
                [
                    [['text' => '🙋 مساعدة الطلاب', 'callback_data' => 'qa:menu']],
                ]
            );

            return;
        }

        $questions = (clone $baseQuery)
            ->with('course')
            ->orderByDesc('created_at')
            ->skip($page * $perPage)
            ->take($perPage)
            ->get();

        $rows = [];
        foreach ($questions as $question) {
            $courseCode = $question->course->code ?? '';
            $preview = mb_substr(trim((string) $question->question), 0, 35);
            $rows[] = [['text' => "❓ [{$courseCode}] {$preview}", 'callback_data' => 'qa:view:'.$question->id]];
        }

        $navRow = [];
        if ($page > 0) {
            $navRow[] = ['text' => '⬅️ السابق', 'callback_data' => 'qa:openpage:'.($page - 1)];
        }
        if (($page + 1) * $perPage < $total) {
            $navRow[] = ['text' => 'التالي ➡️', 'callback_data' => 'qa:openpage:'.($page + 1)];
        }
        if ($navRow !== []) {
            $rows[] = $navRow;
        }

        $rows[] = [['text' => '🙋 مساعدة الطلاب', 'callback_data' => 'qa:menu']];

        $bot->sendMessage($chatId, "🗂 <b>أسئلة تحتاج مساعدة</b> ({$total})\n\nمن مساقاتك الحالية، بدون أي إجابة بعد:", $rows);
    }

    private function sendQaQuestionList(TelegramBotApi $bot, int|string $chatId, int $courseId, int $page): void
    {
        $course = Course::query()->find($courseId);

        if (! $course) {
            $bot->sendMessage($chatId, 'هذه المادة مش موجودة.');

            return;
        }

        $perPage = 5;
        $questions = \App\Models\StudentQuestion::query()
            ->where('course_id', $courseId)
            ->orderByDesc('created_at')
            ->skip($page * $perPage)
            ->take($perPage)
            ->get();

        $total = \App\Models\StudentQuestion::query()->where('course_id', $courseId)->count();

        if ($total === 0) {
            $bot->sendMessage(
                $chatId,
                '📭 ما في أسئلة لهذه المادة بعد. كن أول من يسأل!',
                [
                    [['text' => '🙋 مساعدة الطلاب', 'callback_data' => 'qa:menu']],
                ]
            );

            return;
        }

        $rows = [];
        foreach ($questions as $question) {
            $preview = mb_substr(trim((string) $question->question), 0, 45);
            $rows[] = [['text' => "❓ {$preview}", 'callback_data' => 'qa:view:'.$question->id]];
        }

        $navRow = [];
        if ($page > 0) {
            $navRow[] = ['text' => '⬅️ السابق', 'callback_data' => 'qa:list:'.$courseId.':'.($page - 1)];
        }
        if (($page + 1) * $perPage < $total) {
            $navRow[] = ['text' => 'التالي ➡️', 'callback_data' => 'qa:list:'.$courseId.':'.($page + 1)];
        }
        if ($navRow !== []) {
            $rows[] = $navRow;
        }

        $rows[] = [['text' => '🙋 مساعدة الطلاب', 'callback_data' => 'qa:menu']];

        $courseLabel = trim(($course->code ? $course->code.' — ' : '').(string) ($course->name_ar ?: $course->name_en));
        $bot->sendMessage($chatId, "📚 <b>أسئلة مادة {$courseLabel}</b> ({$total})\n\nاختر سؤال لعرضه:", $rows);
    }

    private function sendQaQuestionView(TelegramBotApi $bot, int|string $chatId, int $questionId): void
    {
        $question = \App\Models\StudentQuestion::query()->with(['course', 'answers' => fn ($q) => $q->orderByDesc('created_at')->limit(8)])->find($questionId);

        if (! $question) {
            $bot->sendMessage($chatId, 'هذا السؤال ما عاد موجود.');

            return;
        }

        $courseLabel = $question->course
            ? trim(($question->course->code ? $question->course->code.' — ' : '').(string) ($question->course->name_ar ?: $question->course->name_en))
            : 'مادة محذوفة';

        $text = "❓ <b>سؤال طالب — {$courseLabel}</b>\n\n".
            TelegramBotApi::escapeHtml((string) $question->question)."\n\n".
            ($question->answers->isEmpty()
                ? '💬 لا يوجد إجابات بعد — كن أول من يجاوب!'
                : '💬 عدد الإجابات: <b>'.$question->answers_count.'</b> (بالأسفل ⬇️)');

        $bot->sendMessage($chatId, $text, [
            [['text' => '✍️ أضف إجابة', 'callback_data' => 'qa:answer:'.$question->id]],
            [['text' => '📚 كل أسئلة المادة', 'callback_data' => 'qa:list:'.$question->course_id.':0']],
            [['text' => '🙋 مساعدة الطلاب', 'callback_data' => 'qa:menu']],
        ]);

        /*
         * كل إجابة تُرسَل كرسالة مستقلة بأزرار تصويت خاصة فيها ("✅
         * صحيحة" / "❌ غير دقيقة") — طلب صريح من المستخدم حتى تبقى
         * الإجابات المحفوظة موثوقة لمن يقرأها لاحقًا. رسالة منفصلة
         * (لا نص واحد مجمَّع) لأنه تيليجرام ما بيدعم أزرار مختلفة لكل
         * "فقرة" داخل نفس الرسالة.
         */
        foreach ($question->answers as $answer) {
            [$answerText, $answerKeyboard] = $this->qaAnswerMessagePayload($answer);
            $bot->sendMessage($chatId, $answerText, $answerKeyboard);
        }
    }

    private function handleQaTextInput(TelegramBotApi $bot, TelegramLink $link, int|string $chatId, string $text): void
    {
        $normalized = trim($text);

        if (in_array($normalized, ['إلغاء', 'الغاء', 'cancel'], true)) {
            $link->update(['pending_action' => null]);
            $bot->sendMessage($chatId, 'تم إلغاء العملية ✅');

            return;
        }

        $pending = $link->pending_action;
        $action = (string) ($pending['action'] ?? '');
        $data = (array) ($pending['data'] ?? []);

        if ($action === 'qa_ask') {
            if (mb_strlen($normalized) < 8) {
                $bot->sendMessage($chatId, 'اكتب سؤالك بشكل أوضح شوي (٨ أحرف على الأقل) 🙂');

                return;
            }
            if (mb_strlen($normalized) > 1000) {
                $bot->sendMessage($chatId, 'سؤالك طويل كتير 🙂 اختصره لأقل من 1000 حرف.');

                return;
            }

            $courseId = (int) ($data['course_id'] ?? 0);
            $course = Course::query()->find($courseId);

            if (! $course) {
                $link->update(['pending_action' => null]);
                $bot->sendMessage($chatId, 'هذه المادة مش موجودة، جرّب من جديد.');

                return;
            }

            $question = \App\Models\StudentQuestion::query()->create([
                'course_id' => $courseId,
                'user_id' => $link->user_id,
                'question' => $normalized,
            ]);

            $link->update(['pending_action' => null]);

            $bot->sendMessage(
                $chatId,
                "✅ تم نشر سؤالك لزملائك بمادة \"".TelegramBotApi::escapeHtml((string) ($course->name_ar ?: $course->name_en))."\". رح توصلك إشعارات بأي إجابة.",
                [
                    [['text' => '🙋 مساعدة الطلاب', 'callback_data' => 'qa:menu']],
                ]
            );

            $this->broadcastQaQuestion($bot, $course, $question, $link->user_id);

            return;
        }

        if ($action === 'qa_answer') {
            if (mb_strlen($normalized) < 3) {
                $bot->sendMessage($chatId, 'اكتب إجابة أوضح شوي 🙂');

                return;
            }
            if (mb_strlen($normalized) > 1500) {
                $bot->sendMessage($chatId, 'إجابتك طويلة كتير 🙂 اختصرها لأقل من 1500 حرف.');

                return;
            }

            $questionId = (int) ($data['question_id'] ?? 0);
            $question = \App\Models\StudentQuestion::query()->with('course')->find($questionId);

            if (! $question) {
                $link->update(['pending_action' => null]);
                $bot->sendMessage($chatId, 'هذا السؤال ما عاد موجود.');

                return;
            }

            \App\Models\StudentAnswer::query()->create([
                'question_id' => $questionId,
                'user_id' => $link->user_id,
                'answer' => $normalized,
            ]);
            $question->increment('answers_count');

            $link->update(['pending_action' => null]);

            $bot->sendMessage(
                $chatId,
                'شكرًا لمساعدتك زملاءك! ✅ تم نشر إجابتك.',
                [
                    [['text' => '📚 كل أسئلة المادة', 'callback_data' => 'qa:list:'.$question->course_id.':0']],
                ]
            );

            $this->notifyQaAsker($bot, $question, $link->user_id);

            return;
        }

        if ($action === 'qa_searchcourse') {
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

            $link->update(['pending_action' => null]);

            $rows = [];
            foreach ($courses as $course) {
                $rows[] = [['text' => $this->qaCourseButtonLabel($course), 'callback_data' => 'qa:list:'.$course->id.':0']];
            }
            $bot->sendMessage($chatId, '📚 اختر المادة يلي بدك تتصفح أسئلتها:', $rows);

            return;
        }
    }

    private function broadcastQaQuestion(TelegramBotApi $bot, Course $course, $question, int $askerUserId): void
    {
        $courseLabel = trim(($course->code ? $course->code.' — ' : '').(string) ($course->name_ar ?: $course->name_en));

        $recipients = TelegramLink::query()
            ->whereNotNull('telegram_chat_id')
            ->where('user_id', '!=', $askerUserId)
            ->whereHas('user.myCourses', function ($q) use ($course) {
                $q->where('courses.id', $course->id)->wherePivot('status', 'registered');
            })
            ->get();

        if ($recipients->isEmpty()) {
            return;
        }

        $text = "🙋 <b>سؤال جديد بمادة {$courseLabel}</b>\n\n".
            TelegramBotApi::escapeHtml((string) $question->question)."\n\nتقدر تساعد زميلك بالإجابة:";

        $keyboard = [
            [['text' => '✍️ أجب', 'callback_data' => 'qa:answer:'.$question->id]],
        ];

        foreach ($recipients as $recipient) {
            \App\Jobs\SendTelegramMessage::dispatch($recipient->telegram_chat_id, $text, $keyboard);
        }
    }

    private function notifyQaAsker(TelegramBotApi $bot, $question, int $answererUserId): void
    {
        if ((int) $question->user_id === $answererUserId) {
            return;
        }

        $askerLink = TelegramLink::query()
            ->whereNotNull('telegram_chat_id')
            ->where('user_id', $question->user_id)
            ->first();

        if (! $askerLink) {
            return;
        }

        $courseLabel = $question->course
            ? trim(($question->course->code ? $question->course->code.' — ' : '').(string) ($question->course->name_ar ?: $question->course->name_en))
            : '';

        $bot->sendMessage(
            $askerLink->telegram_chat_id,
            "📬 <b>وصلتك إجابة جديدة</b> على سؤالك بمادة {$courseLabel} 🎉",
            [
                [['text' => '👀 عرض السؤال والإجابات', 'callback_data' => 'qa:view:'.$question->id]],
            ]
        );
    }

    /**
     * نص + أزرار رسالة إجابة واحدة (تُستخدم عند أول عرض وأيضًا لبناء
     * نفس الرسالة من جديد بعد تحديث عدّاد التصويت بـeditMessageText).
     *
     * @return array{0: string, 1: array}
     */
    private function qaAnswerMessagePayload(\App\Models\StudentAnswer $answer): array
    {
        $text = '👤 <b>طالب:</b> '.TelegramBotApi::escapeHtml((string) $answer->answer);

        $keyboard = [
            [
                ['text' => "✅ صحيحة ({$answer->helpful_count})", 'callback_data' => 'qa:vote:'.$answer->id.':up'],
                ['text' => "❌ غير دقيقة ({$answer->unhelpful_count})", 'callback_data' => 'qa:vote:'.$answer->id.':down'],
            ],
        ];

        return [$text, $keyboard];
    }

    /*
     * تصويت "✅ صحيحة" / "❌ غير دقيقة" على إجابة — طلب صريح من
     * المستخدم حتى تبقى الإجابات المحفوظة موثوقة لمن يقرأها لاحقًا.
     * جدول `student_answer_votes` بقيد unique(answer_id,user_id) يمنع
     * نفس الطالب من التصويت أكثر من مرة، لكن يسمح له يبدّل رأيه
     * (updateOrCreate بدل create) — والعدّادان على student_answers
     * نفسها يُعاد احتسابهما من صفوف التصويت الفعلية دايمًا (مصدر
     * الحقيقة الوحيد)، لا زيادة/نقصان يدوي عرضة للتيه.
     */
    private function handleQaVote(TelegramBotApi $bot, TelegramLink $link, int|string $chatId, int $messageId, int $answerId, string $direction): void
    {
        $answer = \App\Models\StudentAnswer::query()->find($answerId);

        if (! $answer) {
            return;
        }

        if ((int) $answer->user_id === $link->user_id) {
            // التصويت على الإجابة الشخصية غير مسموح — تجاهل صامت
            // (تم ردّ answerCallbackQuery أصلًا بأعلى handleQaCallback).
            return;
        }

        \App\Models\StudentAnswerVote::query()->updateOrCreate(
            ['answer_id' => $answerId, 'user_id' => $link->user_id],
            ['vote' => $direction]
        );

        $answer->helpful_count = \App\Models\StudentAnswerVote::query()->where('answer_id', $answerId)->where('vote', 'up')->count();
        $answer->unhelpful_count = \App\Models\StudentAnswerVote::query()->where('answer_id', $answerId)->where('vote', 'down')->count();
        $answer->save();

        if ($messageId > 0) {
            [$text, $keyboard] = $this->qaAnswerMessagePayload($answer);
            $bot->editMessageText($chatId, $messageId, $text, $keyboard);
        }
    }

    /*
     * ============================================================
     * ميزة "📖 دليل Multisim" (جلسة سابعة، جزء 2) — دليل استخدام ثابت
     * (لا AI ولا DB) لبرنامج NI Multisim، منفصل تمامًا عن سجل "NI
     * Multisim" الموجود مسبقًا بكتالوج self::MAIN_MENU_TOOLS (Tool
     * model) — هذا شرح تفاعلي بخطوات، وذاك كتالوج/رابط تحميل فقط
     * (قسم "⬇️ تحميل البرنامج" بالأسفل يربط لنفس سجل Tool بدل تكرار
     * الرابط يدويًا، حتى ما يصير مصدرين مختلفين لنفس الرابط).
     * مخطط callback_data: ms:menu | ms:section:{key} | ms:ask.
     * ============================================================
     */
    private const MULTISIM_SECTIONS = [
        'start' => [
            'title' => '🚀 البداية السريعة',
            'body' => "بعد فتح Multisim، أول شي بتشوفه: لوحة رسم فاضية بالنص، وعلى اليمين مكتبة القطع (Component Toolbar)، وفوق شريط أدوات القياس والمحاكاة.\n\n".
                "الخطوات الأساسية لأي دائرة جديدة:\n".
                "1️⃣ File → New → Schematic Capture (أو Ctrl+N) لفتح لوحة رسم جديدة.\n".
                "2️⃣ اسحب القطع من مكتبة اليمين (Place → Component لو ما شفتها) وحطها على اللوحة.\n".
                "3️⃣ وصّل بين أطراف القطع بالماوس (بيصير مؤشر + عند الاقتراب من أي طرف — اضغط واسحب لطرف تاني).\n".
                "4️⃣ لازم أرضي (Ground) بكل دائرة — بدونه المحاكاة بترفض تشتغل. تلاقيه بمجموعة Sources.\n".
                "5️⃣ اضغط زر التشغيل (▶️ أخضر أعلى الشاشة، أو F5) لبدء المحاكاة.",
        ],
        'parts' => [
            'title' => '🧰 المكوّنات الأساسية',
            'body' => "أهم المجموعات يلي رح تحتاجها بمعظم مساقات الكهرباء/الإلكترونيات بالكلية:\n\n".
                "🔋 Sources — مصادر الجهد/التيار (DC، AC، أرضي Ground).\n".
                "🔧 Basic — مقاومات (Resistor)، مكثفات (Capacitor)، ملفات (Inductor)، مفاتيح.\n".
                "⚡ Diodes / Transistors — دايودات وترانزستورات BJT/MOSFET.\n".
                "🔌 Analog — مضخّمات عمليّاتية (Op-Amp) جاهزة.\n".
                "💻 Digital — بوابات منطقية (AND/OR/NOT...)، فليب فلوب، عدّادات.\n".
                "📟 Instruments (أسفل يمين الشاشة) — أهم جزء: أجهزة قياس افتراضية (Multimeter، Oscilloscope، Function Generator) اسحبها وحطها بالدائرة زي أي قطعة عادية.",
        ],
        'sim' => [
            'title' => '▶️ تشغيل المحاكاة والقياس',
            'body' => "بعد ما توصّل الدائرة وتحطّ جهاز قياس (مثلًا Multimeter أو Oscilloscope من Instruments):\n\n".
                "1️⃣ دبل-كليك على جهاز القياس نفسه بلوحة الرسم لفتح شاشته (مش نافذة الخصائص).\n".
                "2️⃣ اضغط ▶️ تشغيل (أو F5) — بتشتغل الدائرة فعليًا وتبدأ القيم تتحدّث لحظيًا.\n".
                "3️⃣ لأي قياس بالزمن (إشارات متغيّرة) استخدم Oscilloscope: وصّل قناة CH1/CH2 لنقطة القياس، واضبط Time/Div وVolts/Div لحتى يظهر الشكل الموجي بوضوح.\n".
                "4️⃣ اضغط ⏸️ (مربع التوقف) لإيقاف المحاكاة قبل ما تعدّل أي قطعة — التعديل أثناء التشغيل ممكن يعطي نتائج غلط.\n".
                "💡 نصيحة: قبل التسليم قارن قيمك المقاسة بالحساب اليدوي (قانون أوم مثلًا) — الفرق الكبير غالبًا يعني وصلة غلط أو أرضي ناقص.",
        ],
        'trouble' => [
            'title' => '🛠️ حل المشاكل الشائعة',
            'body' => "أكتر المشاكل يلي بتواجه الطلاب بالمعمل الافتراضي، وحلها المباشر:\n\n".
                "❌ \"Simulation did not converge\" — غالبًا دائرة بدون أرضي (Ground)، أو قيمة قطعة غير واقعية (مثلًا مقاومة 0Ω مباشرة على مصدر). ضيف Ground وتأكد من القيم.\n".
                "❌ القراءة صفر أو ثابتة — تأكد إنك ضغطت ▶️ تشغيل فعليًا (مش بس فتحت شاشة الجهاز)، وإنه في وصلة فعلية (خط أخضر متصل، لا خط منقّط يعني وصلة ناقصة).\n".
                "❌ القطعة \"محروقة\" (تظهر بلون مختلف) — تجاوزت الحد الأقصى المسموح (تيار/جهد) — تحقق من التوصيل قبل التشغيل.\n".
                "❌ ما بتلاقي قطعة معيّنة — استخدم بحث المكوّنات (Place → Component → اكتب الاسم بخانة Search) بدل التصفح اليدوي.\n".
                "❌ البرنامج بطيء أو عالق — قلّل عدد الأجهزة الافتراضية المفتوحة بنفس الوقت، أو أغلق الدارات القديمة غير المستخدمة.",
        ],
    ];

    private function sendMultisimMenu(TelegramBotApi $bot, int|string $chatId): void
    {
        $rows = [];
        foreach (self::MULTISIM_SECTIONS as $key => $section) {
            $rows[] = [['text' => $section['title'], 'callback_data' => 'ms:section:'.$key]];
        }
        $rows[] = [['text' => '⬇️ تحميل البرنامج', 'callback_data' => 'ms:section:download']];
        $rows[] = [['text' => '🤖 اسأل عن Multisim', 'callback_data' => 'ms:ask']];

        $bot->sendMessage(
            $chatId,
            "📖 <b>دليل Multisim</b>\n\nدليل سريع لاستخدام NI Multisim (محاكي الدوائر المستخدم بمساقات الكهرباء/الإلكترونيات) — اختر قسمًا:",
            $rows
        );
    }

    private function sendMultisimSection(TelegramBotApi $bot, int|string $chatId, string $key): void
    {
        if ($key === 'download') {
            $tool = Tool::query()->where('is_active', true)->where('name', 'like', '%Multisim%')->first();

            $rows = [[['text' => '⬅️ رجوع للدليل', 'callback_data' => 'ms:menu']]];
            $text = "⬇️ <b>تحميل NI Multisim</b>\n\n";

            if ($tool && $tool->official_url) {
                $text .= TelegramBotApi::escapeHtml((string) $tool->description)."\n\nرابط التحميل الرسمي بالأسفل ⬇️";
                array_unshift($rows, [['text' => '🔗 صفحة التحميل الرسمية', 'url' => $tool->official_url]]);
            } else {
                $text .= 'ما لقينا رابط تحميل مسجَّل حاليًا بكتالوج الأدوات — دوّر عن "NI Multisim" بموقع NI الرسمي، أو اسأل الدكتور المسؤول عن المساق.';
            }

            $bot->sendMessage($chatId, $text, $rows);

            return;
        }

        $section = self::MULTISIM_SECTIONS[$key] ?? null;

        if (! $section) {
            $this->sendMultisimMenu($bot, $chatId);

            return;
        }

        $bot->sendMessage(
            $chatId,
            '<b>'.TelegramBotApi::escapeHtml($section['title'])."</b>\n\n".TelegramBotApi::escapeHtml($section['body']),
            [
                [['text' => '⬅️ رجوع للدليل', 'callback_data' => 'ms:menu']],
                [['text' => '🤖 اسأل عن Multisim', 'callback_data' => 'ms:ask']],
            ]
        );
    }

    private function handleMultisimCallback(TelegramBotApi $bot, array $callbackQuery): void
    {
        $callbackId = (string) ($callbackQuery['id'] ?? '');
        $chatId = $callbackQuery['message']['chat']['id'] ?? null;
        $data = (string) ($callbackQuery['data'] ?? '');
        $action = substr($data, strlen('ms:'));
        $parts = explode(':', $action);
        $key = $parts[0] ?? '';

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

        $bot->answerCallbackQuery($callbackId);

        if ($key === 'menu') {
            $this->sendMultisimMenu($bot, $chatId);

            return;
        }

        if ($key === 'section') {
            $this->sendMultisimSection($bot, $chatId, (string) ($parts[1] ?? ''));

            return;
        }

        if ($key === 'ask') {
            $link->update(['pending_action' => ['action' => 'ms_ask', 'step' => 'enter_question', 'data' => []]]);
            $bot->sendMessage($chatId, "🤖 اكتب سؤالك عن Multisim (تعامل مع قطعة معيّنة، رسالة خطأ، طريقة قياس...):\n\nاكتب \"إلغاء\" لإيقاف العملية.");

            return;
        }

        $this->sendMultisimMenu($bot, $chatId);
    }

    /*
     * نص حر بمنتصف "🤖 اسأل عن Multisim" — نلف السؤال بسياق واضح ونمرره
     * لنفس TelegramAiAssistant::askText العام (مؤهَّل أصلًا لمواضيع
     * هندسة الحاسوب المتخصصة)، بدل فتح مسار AI منفصل بالكامل لسؤال واحد.
     */
    private function handleMultisimTextInput(TelegramBotApi $bot, TelegramAiAssistant $aiAssistant, TelegramLink $link, int|string $chatId, string $text): void
    {
        $normalized = trim($text);

        if (in_array($normalized, ['إلغاء', 'الغاء', 'cancel'], true)) {
            $link->update(['pending_action' => null]);
            $bot->sendMessage($chatId, 'تم إلغاء العملية ✅');

            return;
        }

        $link->update(['pending_action' => null]);

        try {
            $answer = $aiAssistant->askText($link->user, 'سؤال طالب عن برنامج NI Multisim (محاكي دوائر): '.$normalized);
            $bot->sendMessage(
                $chatId,
                '🤖 '.TelegramBotApi::escapeHtml($answer),
                [
                    [['text' => '📖 دليل Multisim', 'callback_data' => 'ms:menu']],
                ]
            );
        } catch (\Throwable $error) {
            $friendly = $error instanceof \RuntimeException ? $error->getMessage() : 'صار خطأ غير متوقع، جرّب مرة أخرى.';

            if (! $error instanceof \RuntimeException) {
                report($error);
            }

            $bot->sendMessage($chatId, '⚠️ '.$friendly);
        }
    }

    /*
     * ============================================================
     * ميزة "🧩 مولّد UML" (جلسة سابعة، جزء 2) — الطالب يوصف صف/نظام
     * بسيط بالعربي، ونولّد كود Mermaid classDiagram عبر Gemini
     * (TelegramAiAssistant::generateUmlDiagram)، ثم نرندره صورة PNG
     * عبر mermaid.ink العامة (بلا مكتبة رسم على السيرفر) ونبعتها
     * بـTelegramBotApi::sendPhoto. لو فشل التوليد أو الرندر، نرجع
     * لخطة بديلة نصية: كود Mermaid الخام + تعليمات لصقه بـmermaid.live.
     * مخطط callback_data: uml:new | uml:cancel.
     * ============================================================
     */
    private function sendUmlIntro(TelegramBotApi $bot, int|string $chatId, ?TelegramLink $link = null): void
    {
        if ($link) {
            $link->update(['pending_action' => ['action' => 'uml_describe', 'step' => 'enter_description', 'data' => []]]);
        }

        $bot->sendMessage(
            $chatId,
            "🧩 <b>مولّد UML</b>\n\n".
            "صف لي صف (class) أو أكثر بالعربي أو الإنجليزي — الخصائص، الدوال، والعلاقات بينهم لو في أكتر من صف — وبولّدلك مخطط UML جاهز كصورة.\n\n".
            "مثال: \"صف Car فيه اسم ولون وسرعة، ودالة تسريع وفرملة\"\n\n".
            'اكتب وصفك الآن، أو اضغط "❌ إلغاء":',
            [
                [['text' => '❌ إلغاء', 'callback_data' => 'uml:cancel']],
            ]
        );
    }

    private function handleUmlCallback(TelegramBotApi $bot, TelegramAiAssistant $aiAssistant, array $callbackQuery): void
    {
        $callbackId = (string) ($callbackQuery['id'] ?? '');
        $chatId = $callbackQuery['message']['chat']['id'] ?? null;
        $data = (string) ($callbackQuery['data'] ?? '');
        $key = substr($data, strlen('uml:'));

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

        $bot->answerCallbackQuery($callbackId);

        if ($key === 'cancel') {
            $link->update(['pending_action' => null]);
            $bot->sendMessage($chatId, 'تم إلغاء العملية ✅');

            return;
        }

        // 'new' أو أي قيمة غير معروفة — إعادة عرض شاشة الوصف من جديد.
        $this->sendUmlIntro($bot, $chatId, $link);
    }

    private function handleUmlTextInput(TelegramBotApi $bot, TelegramAiAssistant $aiAssistant, TelegramLink $link, int|string $chatId, string $text): void
    {
        $normalized = trim($text);

        if (in_array($normalized, ['إلغاء', 'الغاء', 'cancel'], true)) {
            $link->update(['pending_action' => null]);
            $bot->sendMessage($chatId, 'تم إلغاء العملية ✅');

            return;
        }

        if (mb_strlen($normalized) < 5) {
            $bot->sendMessage($chatId, 'وصف قصير كتير 🙂 وضّح أكتر (اسم الصف، خصائصه، دواله).');

            return;
        }

        if (mb_strlen($normalized) > 3000) {
            $bot->sendMessage($chatId, 'الوصف طويل كتير 🙂 اختصره لأقل من 3000 حرف.');

            return;
        }

        $link->update(['pending_action' => null]);

        $newButtons = [
            [['text' => '🔁 مخطط جديد', 'callback_data' => 'uml:new']],
        ];

        try {
            $mermaidCode = $aiAssistant->generateUmlDiagram($link->user, $normalized);
        } catch (\Throwable $error) {
            $friendly = $error instanceof \RuntimeException ? $error->getMessage() : 'صار خطأ غير متوقع أثناء توليد المخطط، جرّب مرة أخرى.';

            if (! $error instanceof \RuntimeException) {
                report($error);
            }

            $bot->sendMessage($chatId, '⚠️ '.$friendly, $newButtons);

            return;
        }

        $encoded = rtrim(strtr(base64_encode($mermaidCode), '+/', '-_'), '=');
        $localPath = null;

        try {
            $response = Http::timeout(20)->get("https://mermaid.ink/img/{$encoded}", ['type' => 'png']);

            if ($response->successful() && str_starts_with((string) $response->header('Content-Type'), 'image/')) {
                $localPath = tempnam(sys_get_temp_dir(), 'uml_') . '.png';
                file_put_contents($localPath, $response->body());
            }
        } catch (\Throwable $error) {
            report($error);
        }

        if ($localPath) {
            $bot->sendPhoto($chatId, $localPath, 'uml_diagram.png', '🧩 <b>مخططك جاهز!</b>');
            @unlink($localPath);
            $bot->sendMessage($chatId, 'بدك تولّد مخطط تاني؟', $newButtons);

            return;
        }

        // فشل الرندر (mermaid.ink مش متاح مؤقتًا) — خطة بديلة نصية.
        $bot->sendMessage(
            $chatId,
            "⚠️ ما قدرت أرندر المخطط كصورة حاليًا، بس هاي كود Mermaid جاهز — الصقه بموقع <b>mermaid.live</b> لمشاهدته:\n\n".
            '<pre>'.TelegramBotApi::escapeHtml($mermaidCode).'</pre>',
            $newButtons
        );
    }

    /*
     * ============================================================
     * ميزة "📅 رادار الفرص والفعاليات" (جلسة سابعة، جزء 2) — جدول
     * ومسار مستقلّان تمامًا عن Announcement/self::MAIN_MENU_ADMIN_ANNOUNCE
     * (راجع تعليق App\Models\Event لسبب هذا القرار). أي عضو طاقم
     * (isStaff()) ينشر فرصة/فعالية (عنوان + وصف + رابط اختياري)، وكل
     * الطلاب يقدروا يتصفحوا القائمة (سحب/pull) — بلا بث تلقائي (push)
     * حتى نبقيها بسيطة وموثوقة بأقل مخاطرة وقت الاشتغال بلا إشراف
     * مباشر من المستخدم.
     * مخطط callback_data: event:menu | event:list:{page} |
     * event:view:{id} | event:post | event:postcat:{category} |
     * event:cancel.
     * ============================================================
     */
    private const EVENT_CATEGORY_LABELS = [
        \App\Models\Event::CATEGORY_EVENT => '🎉 فعالية',
        \App\Models\Event::CATEGORY_OPPORTUNITY => '💼 فرصة',
    ];

    private function activeEventsQuery()
    {
        return \App\Models\Event::query()
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhereDate('expires_at', '>=', now()->toDateString());
            })
            ->orderByDesc('created_at');
    }

    private function sendEventsMenu(TelegramBotApi $bot, int|string $chatId, \App\Models\User $user): void
    {
        $count = $this->activeEventsQuery()->count();

        $rows = [
            [['text' => '📋 تصفّح القائمة ('.$count.')', 'callback_data' => 'event:list:0']],
        ];

        if ($user->isStaff()) {
            $rows[] = [['text' => '➕ انشر فرصة/فعالية', 'callback_data' => 'event:post']];
        }

        $bot->sendMessage(
            $chatId,
            "📅 <b>رادار الفرص والفعاليات</b>\n\nمسابقات، ورشات، تدريب، منح، وفعاليات تهم طلاب هندسة أنظمة الحاسوب — بمكان وحد ومحدَّث دايمًا.",
            $rows
        );
    }

    private function sendEventsList(TelegramBotApi $bot, int|string $chatId, int $page): void
    {
        $perPage = 5;
        $events = $this->activeEventsQuery()->skip($page * $perPage)->take($perPage + 1)->get();
        $hasMore = $events->count() > $perPage;
        $events = $events->take($perPage);

        if ($events->isEmpty() && $page === 0) {
            $bot->sendMessage(
                $chatId,
                'لا يوجد فرص أو فعاليات منشورة حاليًا — تابعنا، رح تضاف أول ما تتوفر فرصة جديدة 🙂',
                [[['text' => '⬅️ رجوع', 'callback_data' => 'event:menu']]]
            );

            return;
        }

        $rows = [];
        foreach ($events as $event) {
            $label = (self::EVENT_CATEGORY_LABELS[$event->category] ?? '📌').' '.mb_substr((string) $event->title, 0, 40);
            $rows[] = [['text' => $label, 'callback_data' => 'event:view:'.$event->id]];
        }

        $navRow = [];
        if ($page > 0) {
            $navRow[] = ['text' => '⬅️ السابق', 'callback_data' => 'event:list:'.($page - 1)];
        }
        if ($hasMore) {
            $navRow[] = ['text' => 'التالي ➡️', 'callback_data' => 'event:list:'.($page + 1)];
        }
        if ($navRow !== []) {
            $rows[] = $navRow;
        }
        $rows[] = [['text' => '⬅️ رجوع للرادار', 'callback_data' => 'event:menu']];

        $bot->sendMessage($chatId, '📋 <b>الفرص والفعاليات الحالية</b>', $rows);
    }

    private function sendEventView(TelegramBotApi $bot, int|string $chatId, int $eventId): void
    {
        $event = \App\Models\Event::query()->find($eventId);

        if (! $event || ! $event->is_active) {
            $bot->sendMessage($chatId, 'هذه الفرصة/الفعالية ما عادت متاحة.', [[['text' => '⬅️ رجوع', 'callback_data' => 'event:list:0']]]);

            return;
        }

        $label = self::EVENT_CATEGORY_LABELS[$event->category] ?? '📌 فرصة/فعالية';
        $text = "{$label}\n\n<b>".TelegramBotApi::escapeHtml((string) $event->title)."</b>\n\n".
            TelegramBotApi::escapeHtml((string) $event->description);

        if ($event->expires_at) {
            $text .= "\n\n⏰ آخر موعد: ".$event->expires_at->format('Y-m-d');
        }

        $rows = [];
        if ($event->url) {
            $rows[] = [['text' => '🔗 رابط التفاصيل/التسجيل', 'url' => $event->url]];
        }
        $rows[] = [['text' => '⬅️ رجوع للقائمة', 'callback_data' => 'event:list:0']];

        $bot->sendMessage($chatId, $text, $rows);
    }

    private function handleEventCallback(TelegramBotApi $bot, array $callbackQuery): void
    {
        $callbackId = (string) ($callbackQuery['id'] ?? '');
        $chatId = $callbackQuery['message']['chat']['id'] ?? null;
        $data = (string) ($callbackQuery['data'] ?? '');
        $action = substr($data, strlen('event:'));
        $parts = explode(':', $action);
        $key = $parts[0] ?? '';

        if (! $chatId) {
            $bot->answerCallbackQuery($callbackId);

            return;
        }

        $link = TelegramLink::query()
            ->whereNotNull('telegram_chat_id')
            ->where('telegram_chat_id', $chatId)
            ->first();

        if (! $link || ! $link->user) {
            $bot->answerCallbackQuery($callbackId, 'هذا الحساب مش مربوط.');

            return;
        }

        $bot->answerCallbackQuery($callbackId);

        switch ($key) {
            case 'menu':
                $link->update(['pending_action' => null]);
                $this->sendEventsMenu($bot, $chatId, $link->user);

                return;

            case 'cancel':
                $link->update(['pending_action' => null]);
                $bot->sendMessage($chatId, 'تم إلغاء العملية ✅');

                return;

            case 'list':
                $page = max(0, (int) ($parts[1] ?? 0));
                $this->sendEventsList($bot, $chatId, $page);

                return;

            case 'view':
                $this->sendEventView($bot, $chatId, (int) ($parts[1] ?? 0));

                return;

            case 'post':
                if (! $link->user->isStaff()) {
                    $bot->sendMessage($chatId, 'هذه الميزة لحسابات الطاقم فقط.');

                    return;
                }

                $rows = [];
                foreach (self::EVENT_CATEGORY_LABELS as $category => $label) {
                    $rows[] = [['text' => $label, 'callback_data' => 'event:postcat:'.$category]];
                }
                $rows[] = [['text' => '❌ إلغاء', 'callback_data' => 'event:cancel']];

                $bot->sendMessage($chatId, '📅 اختر النوع:', $rows);

                return;

            case 'postcat':
                if (! $link->user->isStaff()) {
                    $bot->sendMessage($chatId, 'هذه الميزة لحسابات الطاقم فقط.');

                    return;
                }

                $category = in_array($parts[1] ?? '', \App\Models\Event::CATEGORIES, true) ? $parts[1] : \App\Models\Event::CATEGORY_EVENT;
                $link->update(['pending_action' => ['action' => 'event_post', 'step' => 'title', 'data' => ['category' => $category]]]);
                $bot->sendMessage($chatId, "✍️ اكتب عنوان الفرصة/الفعالية (سطر واحد قصير):\n\nاكتب \"إلغاء\" لإيقاف العملية.");

                return;

            default:
                $this->sendEventsMenu($bot, $chatId, $link->user);
        }
    }

    private function handleEventTextInput(TelegramBotApi $bot, TelegramLink $link, int|string $chatId, string $text): void
    {
        $normalized = trim($text);

        if (in_array($normalized, ['إلغاء', 'الغاء', 'cancel'], true)) {
            $link->update(['pending_action' => null]);
            $bot->sendMessage($chatId, 'تم إلغاء العملية ✅');

            return;
        }

        if (! $link->user || ! $link->user->isStaff()) {
            $link->update(['pending_action' => null]);
            $bot->sendMessage($chatId, 'هذه الميزة لحسابات الطاقم فقط.');

            return;
        }

        $pending = $link->pending_action;
        $step = (string) ($pending['step'] ?? '');
        $data = (array) ($pending['data'] ?? []);

        if ($step === 'title') {
            if (mb_strlen($normalized) < 3 || mb_strlen($normalized) > 150) {
                $bot->sendMessage($chatId, 'العنوان لازم يكون بين ٣ و١٥٠ حرف 🙂');

                return;
            }

            $data['title'] = $normalized;
            $link->update(['pending_action' => ['action' => 'event_post', 'step' => 'description', 'data' => $data]]);
            $bot->sendMessage($chatId, "✍️ اكتب وصفًا مختصرًا (الجهة، التاريخ، المكان، أهم التفاصيل):");

            return;
        }

        if ($step === 'description') {
            if (mb_strlen($normalized) < 10 || mb_strlen($normalized) > 2000) {
                $bot->sendMessage($chatId, 'الوصف لازم يكون بين ١٠ و٢٠٠٠ حرف 🙂');

                return;
            }

            $data['description'] = $normalized;
            $link->update(['pending_action' => ['action' => 'event_post', 'step' => 'url', 'data' => $data]]);
            $bot->sendMessage($chatId, "🔗 حابب تضيف رابط تسجيل/تفاصيل؟ ابعته الآن، أو اكتب \"تخطي\":");

            return;
        }

        if ($step === 'url') {
            $url = null;

            if (! in_array(mb_strtolower($normalized), ['تخطي', 'skip', 'لا'], true)) {
                if (! preg_match('#^https?://#i', $normalized) || mb_strlen($normalized) > 255) {
                    $bot->sendMessage($chatId, 'الرابط لازم يبدأ بـhttp:// أو https:// (أو اكتب "تخطي" لتجاوزه):');

                    return;
                }

                $url = $normalized;
            }

            $event = \App\Models\Event::query()->create([
                'category' => $data['category'] ?? \App\Models\Event::CATEGORY_EVENT,
                'title' => $data['title'] ?? '',
                'description' => $data['description'] ?? '',
                'url' => $url,
                'posted_by' => $link->user_id,
                'is_active' => true,
            ]);

            $link->update(['pending_action' => null]);

            $bot->sendMessage(
                $chatId,
                '✅ تم نشر "'.TelegramBotApi::escapeHtml((string) $event->title).'" — رح يظهر لكل الطلاب بـ"📅 رادار الفرص والفعاليات" فورًا.',
                [[['text' => '📅 الرادار', 'callback_data' => 'event:menu']]]
            );

            return;
        }

        $link->update(['pending_action' => null]);
        $bot->sendMessage($chatId, 'صار خطأ بالعملية، جرّب من جديد 🙂');
    }

    /*
     * "🔍 بحث → 🧭 أزرار وميزات البوت" (جلسة سابعة، جزء 4) — نتيجة
     * بحث مطابقة لـFEATURE_INDEX بتوصل هون بزر inline (navjump:{key}).
     * كل حالة هون هي *بالضبط* نفس كود كتلة المطابقة النصية لهذا الزر
     * بـ__invoke() (بما فيها مسح pending_action لو كانت تعمل هيك)،
     * حتى يتصرف "لقيته بالبحث وضغطته" بالضبط متل "كتبت اسم الزر يدويًا".
     * $planCalculator/$aiAssistant/$gpaCalculator ممرَّرة من __invoke()
     * نفسها (نفس الخدمات المُحقَنة بالكونستركتر، لا نسخة جديدة).
     */
    private function handleNavJumpCallback(
        TelegramBotApi $bot,
        PlanCalculator $planCalculator,
        TelegramAiAssistant $aiAssistant,
        TelegramGpaCalculator $gpaCalculator,
        array $callbackQuery
    ): void {
        $callbackId = (string) ($callbackQuery['id'] ?? '');
        $chatId = $callbackQuery['message']['chat']['id'] ?? null;
        $data = (string) ($callbackQuery['data'] ?? '');
        $key = substr($data, strlen('navjump:'));

        if (! $chatId) {
            $bot->answerCallbackQuery($callbackId);

            return;
        }

        $link = TelegramLink::query()
            ->whereNotNull('telegram_chat_id')
            ->where('telegram_chat_id', $chatId)
            ->first();

        if (! $link || ! $link->user) {
            $bot->answerCallbackQuery($callbackId, 'هذا الحساب مش مربوط.');

            return;
        }

        $bot->answerCallbackQuery($callbackId);

        $isAdminKey = str_starts_with($key, 'admin');

        if ($isAdminKey && ! $link->user->isStaff()) {
            $bot->sendMessage($chatId, '⛔ هذا الخيار متاح فقط لحسابات الإدارة.');

            return;
        }

        switch ($key) {
            case 'plan':
                $this->replyWithPlanSummary($bot, $chatId, $link->user, $planCalculator);

                return;

            case 'gpa':
                $this->replyWithGpaSummary($bot, $chatId, $link->user, $gpaCalculator);

                return;

            case 'schedule':
                $this->replyWithScheduleSummary($bot, $chatId, $link);

                return;

            case 'courses':
                $this->sendCourseHubYearPicker($bot, $chatId);

                return;

            case 'mycourses':
                $this->sendMyCoursesList($bot, $chatId, $link->user);

                return;

            case 'favorites':
                $this->sendContentFavoritesList($bot, $chatId, $link->user, 1);

                return;

            case 'tools':
                $this->sendMenu($bot, $chatId, $link->currentMode());

                return;

            case 'calc':
                $link->update(['pending_action' => null]);
                $this->sendCalcMainMenu($bot, $chatId);

                return;

            case 'multisim':
                $link->update(['pending_action' => null]);
                $this->sendMultisimMenu($bot, $chatId);

                return;

            case 'uml':
                $this->sendUmlIntro($bot, $chatId, $link);

                return;

            case 'peerhelp':
                $link->update(['pending_action' => null]);
                $this->sendQaMainMenu($bot, $chatId);

                return;

            case 'miniapp':
                $this->sendMiniAppCard($bot, $chatId);

                return;

            case 'contact':
                $this->startContactFlow($bot, $link, $chatId);

                return;

            case 'contribute':
                $this->startContributeFlow($bot, $link, $chatId);

                return;

            case 'help':
                $this->sendHelpText($bot, $chatId, $link->user);

                return;

            case 'adminannounce':
                $this->startAnnounceFlow($bot, $link, $chatId);

                return;

            case 'admintools':
                $this->sendAdminToolMenu($bot, $chatId, 1);

                return;

            case 'admincontent':
                $this->startAdminContentFlow($bot, $link, $chatId);

                return;

            case 'admincourses':
                $this->sendAdminCoursesMenu($bot, $chatId);

                return;

            default:
                // مفتاح غير معروف (نسخة قديمة من رسالة بحث سابقة مثلًا) — تجاهل صامت.
        }
    }
}
