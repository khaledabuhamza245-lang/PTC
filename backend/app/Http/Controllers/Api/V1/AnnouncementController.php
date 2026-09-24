<?php
 
namespace App\Http\Controllers\Api\V1;
 
use App\Http\Controllers\Controller;
use App\Models\Announcement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
 
class AnnouncementController extends Controller
{
    /** الحالات التي يصل إليها إعلان المساق — انظر التعليل في visibleTo(). */
    private const ACTIVE_ENROLLMENT = ['registered', 'completed'];
 
    /**
     * إعلانات هذا المستخدم — المسار عام والترشيح على الخادم.
     *
     * لماذا $request->user('sanctum') لا $request->user(): المسار خارج
     * `auth:sanctum` عمدًا (الضيف يرى العام)، والحارس الافتراضي في هذا
     * المشروع `web` أي الجلسة — فالنداء بلا حارس يعود فارغًا حتى مع رمز
     * صحيح. تسمية الحارس تقرأ الرمز حين يوجد وتعود فارغة حين لا يوجد،
     * بلا رفض الطلب في الحالتين. وهو نفس ما تفعله CourseFileController
     * و CourseStructureController على مساراتهما العامة.
     *
     * والترشيح هنا لا في المتصفح: إرسال إعلان موجَّه إلى من لا يعنيه ثم
     * إخفاؤه بجافاسكربت يعني أن نصّه وصل جهازه فعلًا — وهو تسريب لا
     * تصميم واجهة.
     */
    public function index(Request $request)
    {
        $user = $request->user('sanctum');
 
        $items = Announcement::query()
            ->with(['course:id,key,code,name_ar'])
            ->where('active', true)
            ->where(fn (Builder $query) => $this->visibleTo($query, $user))
            ->latest()
            ->limit(20)
            ->get();
 
        return response()->json(['data' => $items]);
    }
 
    /**
     * الشرط: العام دائمًا، ويُضاف إليه ما يخصّ صاحب الطلب.
     *
     * الفروع الثلاثة متمانعة بحكم البيانات (audience قيمة واحدة)، فلا
     * خطر تكرار صفّ. والضيف يقف عند الفرع الأول وحده.
     */
    private function visibleTo(Builder $query, $user): void
    {
        $query->where('audience', 'all');
 
        if (! $user) {
            return;
        }
 
        if ($user->year) {
            $query->orWhere(
                fn (Builder $inner) => $inner
                    ->where('audience', 'year')
                    ->where('audience_year', $user->year),
            );
        }
 
        if ($user->year && $user->semester) {
            $query->orWhere(
                fn (Builder $inner) => $inner
                    ->where('audience', 'year_semester')
                    ->where('audience_year', $user->year)
                    ->where('audience_semester', $user->semester),
            );
        }
 
        /*
         * «طلاب المساق» = من سجّله في my_courses، لا من تصفّحه. التصفّح
         * غير مخزَّن أصلًا، فهذا التعريف الوحيد المحدَّد بيانيًّا.
         *
         * والانسحاب يُخرِج صاحبه: الصفّ يبقى في الجدول بحالة dropped
         * (الانسحاب لا يحذفه — الحذف من بطاقة «مساقاتي» وحده يحذفه)،
         * فبلا هذا القيد يستقبل من أعلن «لم أعد في هذا المساق» إعلاناته
         * إلى الأبد.
         *
         * و completed يبقى داخل النطاق عمدًا: «صدرت النتائج» و«رُفع نموذج
         * الامتحان» يخصّان من أنهى المساق كما يخصّان من فيه، والإعلانات
         * قليلة أصلًا فكلفة الزائد أقلّ من كلفة الناقص. تُحذف من الثابت
         * أعلاه إن أراد العميل قصرها على الجاري.
         */
        $courseIds = $user->myCourses()
            ->wherePivotIn('status', self::ACTIVE_ENROLLMENT)
            ->pluck('courses.id');
 
        if ($courseIds->isNotEmpty()) {
            $query->orWhere(
                fn (Builder $inner) => $inner
                    ->where('audience', 'course')
                    ->whereIn('course_id', $courseIds),
            );
        }
    }
}
 
