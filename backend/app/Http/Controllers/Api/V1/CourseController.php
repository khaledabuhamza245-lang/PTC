<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Course;
use Illuminate\Http\Request;

class CourseController extends Controller
{
    /*
     * حمولة هذا المسار عامة ومخزَّنة في متصفح الطالب خمس دقائق
     * (auth.js: ptc_courses_cache_v1). لذلك تحمل بيانات الخطة —
     * الساعات والصنف والمتطلبات — ولا تحمل **حالة الطالب** إطلاقًا:
     * حشو حالة شخصية في ذاكرة مشتركة كان سيجعل جهازًا واحدًا يعرض
     * حالة آخر مستخدم سجّل عليه. الحالات تأتي من /me/plan-summary
     * وتُدمج في الواجهة.
     *
     * والساعات والصنف يدخلان تلقائيًا بلا سطر هنا: لا $hidden على
     * الموديل ولا API Resource، فالأعمدة الجديدة تظهر بمجرّد الهجرة.
     */
    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));

        $courses = Course::query()
            ->with(['prerequisites.prerequisite:id,key,code,name_ar'])
            ->where('is_active', true)
            ->when($request->integer('year'), fn ($q, $year) => $q->where('year', $year))
            ->when($request->integer('semester'), fn ($q, $semester) => $q->where('semester', $semester))
            ->when($search, function ($q, $search) {
                $value = '%'.$search.'%';
                $q->where(function ($q) use ($value) {
                    $q->where('code', 'like', $value)
                        ->orWhere('name_ar', 'like', $value)
                        ->orWhere('name_en', 'like', $value)
                        ->orWhere('keywords', 'like', $value);
                });
            })
            /*
             * sort_order قبل id: الترتيب الذي يضبطه الطاقم بالسحب في
             * تبويب الخطة يجب أن يصل الطالب، وإلا صار السحب تحريكًا في
             * لوحة التحكم وحدها. و id يبقى فاصلًا لما لم يُرتَّب بعد —
             * كل المواد تبدأ بـ 0 حتى تُسحب أول مرة.
             */
            ->orderBy('semester')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $courses->each(function (Course $course) {
            /*
             * المتطلبات تُسطَّح إلى رموز: الواجهة تعرضها وسمًا نصّيًا،
             * وشجرة العلاقة كاملة تضاعف حجم حمولة الـ٧٥ مساقًا بلا
             * أن تُستعمل. والرمز النصّي يحلّ محلّ المربوط حين لا يوجد.
             */
            $course->setAttribute(
                'prerequisite_codes',
                $course->prerequisites
                    ->map(fn ($row) => $row->prerequisite?->code ?? $row->prerequisite_code_raw)
                    ->filter()
                    ->values(),
            );

            /*
             * والاسم مع الرمز: جدول الخطة يعرض «إلكترونيات» لا
             * «EEE1 3259» — الرمز يُبحث به في الورقة ولا يُقرأ في صف.
             * يبقى في الحمولة تلميحًا للخانة، ويبقى prerequisite_codes
             * فوقه لأن حمولة قديمة مخزَّنة في المتصفح خمس دقائق لا
             * تعرف الحقل الجديد، والخانة تعرض ما تعرفه لا فراغًا.
             *
             * والرمز المعطوب — ستّة مراجع لا تقابل مساقًا — لا اسم له،
             * فيُعرض نصّه كما ورد لا فراغًا يوهم بأن لا متطلب.
             */
            $course->setAttribute(
                'prerequisites_brief',
                $course->prerequisites
                    ->map(fn ($row) => [
                        'code' => $row->prerequisite?->code ?? $row->prerequisite_code_raw,
                        'name' => $row->prerequisite?->name_ar ?? $row->prerequisite_code_raw,
                    ])
                    ->filter(fn (array $row) => (bool) $row['code'])
                    ->values(),
            );

            $course->unsetRelation('prerequisites');
        });

        return response()->json(['data' => $courses]);
    }

    public function show(Course $course)
    {
        abort_unless($course->is_active, 404);

        return response()->json(['data' => $course]);
    }
}
