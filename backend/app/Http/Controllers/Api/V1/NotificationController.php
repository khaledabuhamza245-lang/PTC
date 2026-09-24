<?php
 
namespace App\Http\Controllers\Api\V1;
 
use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\ContentReport;
use App\Models\CourseFile;
use App\Models\CourseTip;
use App\Models\NotificationCheckpoint;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
 
/*
 * جرس إشعارات واحد، يخدم ثلاث أدوار مختلفة تمامًا (أدمن، مشرف، طالب)
 * بمحتوى مختلف لكل دور — لا ثلاثة أنظمة منفصلة.
 *
 * دورة حياة كل نوع مختلفة عمدًا:
 * - بلاغ: يبقى ظاهرًا ومحسوبًا لحد ما يُعالَج صراحة (resolved_at) —
 *   لا علاقة لعمره ولا لقراءته بظهوره، هو مهمة معلّقة لا إشعار عابر.
 * - نصيحة/محتوى: الضغط عليه للانتقال ومشاهدته يُخفيه فورًا (يُدار هذا
 *   من الواجهة عبر localStorage، لا من هنا). لو اكتُفي بـ«تعليم الكل
 *   كمقروء» بلا فتح فعلي، يبقى ظاهرًا (بلا احتساب) لفترة سماح قصيرة
 *   بعدها يختفي تمامًا.
 * - إعلان: نفس فكرة النصيحة، لكن بفترة سماح أطول قليلًا (يخص فصلًا
 *   كاملًا لا لحظة عابرة).
 */
class NotificationController extends Controller
{
    private const WINDOW_DAYS = 30;
    private const CONTENT_WINDOW_DAYS = 7;
    private const ANNOUNCEMENT_WINDOW_DAYS = 14;
 
    /** فترة السماح بعد «تعليم الكل كمقروء» فقط (بلا فتح فعلي) قبل الاختفاء. */
    private const CONTENT_GRACE_HOURS = 24;
    private const ANNOUNCEMENT_GRACE_HOURS = 72;
 
    private function checkpointFor($user): Carbon
    {
        $checkpoint = NotificationCheckpoint::where('user_id', $user->id)->first();
 
        return $checkpoint?->last_seen_at
            ?? now()->subDays(self::WINDOW_DAYS);
    }
 
    /** أرقام مساقات المستخدم «الجارية» حاليًا (غير منجزة ولا منسحب منها). */
    private function currentCourseIds($user): array
    {
        return $user->myCourses()
            ->wherePivot('status', 'registered')
            ->pluck('courses.id')
            ->all();
    }
 
    /*
     * هل يبقى عنصر «قُرئ عبر تعليم الكل» (لا فتحًا فعليًا) ظاهرًا بعد؟
     * العنصر غير المقروء أصلًا (created_at بعد الـcheckpoint) يمرّ دومًا؛
     * القرار هنا يخصّ فقط ما هو أقدم من آخر checkpoint.
     */
    private function withinGrace(Carbon $itemDate, Carbon $checkpoint, int $graceHours): bool
    {
        if ($itemDate->gt($checkpoint)) {
            return true;
        }
 
        return $checkpoint->diffInHours(now()) < $graceHours;
    }
 
    public function summary(Request $request)
    {
        $items = $this->collectItems($request->user());
 
        return response()->json([
            'data' => ['count' => $items->where('unread', true)->count()],
        ]);
    }
 
    private function relevantAnnouncements($user, Carbon $floor)
    {
        $courseIds = $this->currentCourseIds($user);
 
        return Announcement::where('active', true)
            ->where('created_at', '>', $floor)
            ->where(function ($query) use ($user, $courseIds) {
                $query->where('audience', 'all')
                    ->orWhere(function ($inner) use ($user) {
                        $inner->where('audience', 'year')
                            ->where('audience_year', $user->year);
                    })
                    ->orWhere(function ($inner) use ($user) {
                        $inner->where('audience', 'year_semester')
                            ->where('audience_year', $user->year)
                            ->where('audience_semester', $user->semester);
                    })
                    ->orWhere(function ($inner) use ($courseIds) {
                        $inner->where('audience', 'course')
                            ->whereIn('course_id', $courseIds);
                    });
            });
    }
 
    /*
     * يجلب كل العناصر المرشَّحة لدور المستخدم، ويحسب لكل عنصر: هل هو
     * "unread" (يُحتسب بالعدّاد) وهل هو "visible" (يظهر بالقائمة أصلًا).
     * نقطة واحدة يستخدمها summary() و recent() معًا، فلا يفترقان أبدًا.
     */
    private function collectItems($user)
    {
        $checkpoint = $this->checkpointFor($user);
        $contentFloor = now()->subDays(self::CONTENT_WINDOW_DAYS);
        $announcementFloor = now()->subDays(self::ANNOUNCEMENT_WINDOW_DAYS);
 
        $items = collect();
 
        if ($user->isStaff()) {
            $tips = CourseTip::with(['course:id,key,code,name_ar', 'user:id,first_name,father_name,last_name'])
                ->latest()->limit(15)->get()
                ->map(fn ($tip) => [
                    'type' => 'tip',
                    'created_at' => $tip->created_at,
                    'unread' => $tip->created_at->gt($checkpoint),
                    'visible' => $this->withinGrace($tip->created_at, $checkpoint, self::CONTENT_GRACE_HOURS),
                    'payload' => $tip,
                ]);
 
            // البلاغ لا يخضع لا لنافذة زمنية ولا لفترة سماح: معالجته وحدها تُسقطه.
            // لو وصل هنا أصلًا فهو معلّق بالتعريف (whereNull أعلاه)، فهو غير مقروء دومًا.
            $reports = ContentReport::whereNull('resolved_at')
                ->with(['course:id,key,code,name_ar', 'courseFile:id,course_id,title', 'user:id,first_name,father_name,last_name'])
                ->latest()->limit(15)->get()
                ->map(fn ($report) => [
                    'type' => 'report',
                    'created_at' => $report->created_at,
                    'unread' => true,
                    'visible' => true,
                    'payload' => array_merge(
                        $report->toArray(),
                        ['reason_label' => ContentReport::REASONS[$report->reason] ?? $report->reason]
                    ),
                ]);
 
            $contentQuery = CourseFile::where('is_published', true)
                ->where('created_at', '>', $contentFloor);
 
            if ($user->role !== 'admin') {
                $contentQuery->whereIn('course_id', $this->currentCourseIds($user));
            }
 
            $content = $contentQuery
                ->with(['course:id,key,code,name_ar', 'creator:id,first_name,father_name,last_name'])
                ->latest()->limit(15)->get()
                ->map(fn ($file) => [
                    'type' => 'content',
                    'created_at' => $file->created_at,
                    'unread' => $file->created_at->gt($checkpoint),
                    'visible' => $this->withinGrace($file->created_at, $checkpoint, self::CONTENT_GRACE_HOURS),
                    'payload' => $file,
                ]);
 
            $items = $tips->concat($reports)->concat($content);
 
            if ($user->role !== 'admin') {
                $announcements = $this->relevantAnnouncements($user, $announcementFloor)
                    ->with('course:id,key,code,name_ar')
                    ->latest()->limit(15)->get()
                    ->map(fn ($a) => [
                        'type' => 'announcement',
                        'created_at' => $a->created_at,
                        'unread' => $a->created_at->gt($checkpoint),
                        'visible' => $this->withinGrace($a->created_at, $checkpoint, self::ANNOUNCEMENT_GRACE_HOURS),
                        'payload' => $a,
                    ]);
 
                $items = $items->concat($announcements);
            }
 
            return $items;
        }
 
        // طالب
        $courseIds = $this->currentCourseIds($user);
 
        $content = CourseFile::where('is_published', true)
            ->where('created_at', '>', $contentFloor)
            ->whereIn('course_id', $courseIds)
            ->with(['course:id,key,code,name_ar'])
            ->latest()->limit(15)->get()
            ->map(fn ($file) => [
                'type' => 'content',
                'created_at' => $file->created_at,
                'unread' => $file->created_at->gt($checkpoint),
                'visible' => $this->withinGrace($file->created_at, $checkpoint, self::CONTENT_GRACE_HOURS),
                'payload' => $file,
            ]);
 
        $announcements = $this->relevantAnnouncements($user, $announcementFloor)
            ->with('course:id,key,code,name_ar')
            ->latest()->limit(15)->get()
            ->map(fn ($a) => [
                'type' => 'announcement',
                'created_at' => $a->created_at,
                'unread' => $a->created_at->gt($checkpoint),
                'visible' => $this->withinGrace($a->created_at, $checkpoint, self::ANNOUNCEMENT_GRACE_HOURS),
                'payload' => $a,
            ]);
 
        return $content->concat($announcements);
    }
 
    public function recent(Request $request)
    {
        $user = $request->user();
 
        $items = $this->collectItems($user)
            ->where('visible', true)
            ->sortByDesc('created_at')
            ->take(20)
            ->values()
            ->map(fn ($item) => [
                'type' => $item['type'],
                'created_at' => $item['created_at'],
                'unread' => $item['unread'],
                'payload' => $item['payload'],
            ]);
 
        return response()->json([
            'data' => $items,
            'last_seen_at' => $this->checkpointFor($user)->toIso8601String(),
        ]);
    }
 
    public function markSeen(Request $request)
    {
        NotificationCheckpoint::updateOrCreate(
            ['user_id' => $request->user()->id],
            ['last_seen_at' => now()]
        );
 
        /*
         * البلاغات وحدها مستثناة من "تعليم الكل" عمدًا: هي مهام معلّقة
         * تُحلّ بمعالجتها صراحةً من لوحة التحكم (زر «تمّت المعالجة»)،
         * لا بمجرد فتح الجرس أو تصفّح الإشعارات. الـcheckpoint هنا
         * يكفي وحده لكل الأنواع الأخرى (نصيحة، محتوى، إعلان).
         */
        return response()->json(['message' => 'تم تحديث حالة الإشعارات.']);
    }
}
 
