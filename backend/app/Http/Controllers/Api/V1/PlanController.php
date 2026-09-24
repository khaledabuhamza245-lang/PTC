<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Term;
use App\Services\PlanCalculator;
use Illuminate\Http\Request;

class PlanController extends Controller
{
    public function __construct(private PlanCalculator $calculator)
    {
    }

    /**
     * الجامع الرئيسي للمرحلة ٤.
     *
     * يحمل الأرقام المجمّعة **وخريطة حالة كل مساق** معًا: جدول الخطة
     * الكامل يحتاج الاثنين في رسمة واحدة، وطلبان منفصلان كانا سيرسمان
     * الجدول مرتين — مرة بلا حالات ومرة بها.
     */
    public function summary(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'data' => $this->calculator->summarize($user) + [
                'statuses' => $this->calculator->statusMap($user),
                'current_term' => $user->currentTerm ?? Term::current(),
            ],
        ]);
    }

    /** تسجيلات الطالب. ?term=current يقصر النتيجة على الفصل الجاري. */
    public function enrollments(Request $request)
    {
        $term = $request->query('term');

        $query = $request->user()->myCourses()
            ->orderBy('courses.year')
            ->orderBy('courses.semester')
            ->orderBy('courses.id');

        if ($term === 'current') {
            $current = $request->user()->currentTerm ?? Term::current();

            /*
             * فصل حالي غير مضبوط يعني قائمة فارغة لا القائمة كاملة:
             * إرجاع الكل عند غياب الفلتر يجعل «فصلي الحالي» يعرض خطة
             * أربع سنوات بلا أن يقول إن الفلتر لم يُطبَّق.
             */
            $query->wherePivot('term_id', $current?->id);
        } elseif (is_numeric($term)) {
            $query->wherePivot('term_id', (int) $term);
        }

        return response()->json([
            'data' => $query->get()->map(fn (Course $course) => [
                'key' => $course->key,
                'code' => $course->code,
                'name_ar' => $course->name_ar,
                'name_en' => $course->name_en,
                'year' => $course->year,
                'semester' => $course->semester,
                'credit_hours' => $course->credit_hours,
                'course_type' => $course->course_type,
                'status' => $course->pivot->status ?? 'registered',
                'term_id' => $course->pivot->term_id,
                'grade' => $course->pivot->grade,
                'completed_at' => $course->pivot->completed_at,
            ]),
        ]);
    }
}
