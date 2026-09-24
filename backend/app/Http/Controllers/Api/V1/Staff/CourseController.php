<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Http\Controllers\Controller;
use App\Models\Course;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CourseController extends Controller
{
    public function store(Request $request)
    {
        $this->authorize('create', Course::class);

        $data = $this->validatedData($request);
        $data['key'] = $data['key'] ?? $this->makeKey($data['code']);
        $data['page'] = $data['page'] ?? $this->pageFor($data);

        /*
         * withTrashed ضرورية: الحذف ناعم فيبقى الصفّ وفهرسه الفريد في
         * القاعدة، بينما نطاق SoftDeletes العام يخفيه عن هذا الفحص. فإعادة
         * إضافة مادة محذوفة بالرمز نفسه كانت تعبر الشرط ثم تصطدم بالفهرس
         * الفريد — خطأ 23000 يخرج للطاقم بـ500 بلا رسالة يفهمها، بدل 422
         * تقول له إن المادة في سلة المحذوفات.
         *
         * والقاعدة لا تُصلحها وحدها: `key` مُتحقَّق منها `nullable` عند
         * الإنشاء، فقاعدة unique تُتخطّى كلما لم ترسل الواجهة مفتاحًا —
         * وهي لا ترسله أبدًا، فـmakeKey يولّده من الرمز نفسه.
         */
        $existing = Course::withTrashed()
            ->where('key', $data['key'])
            ->first();

        if ($existing) {
            return response()->json([
                'message' => $existing->trashed()
                    ? 'هذه المادة موجودة في سلة المحذوفات — استعِدها بدل إضافتها من جديد.'
                    : 'يوجد مساق آخر بنفس الرمز أو المفتاح.',
                'trashed' => $existing->trashed(),
                'key' => $existing->key,
            ], 422);
        }

        $data['sort_order'] = $data['sort_order'] ?? $this->nextSortOrder($data);

        $course = Course::create($data);

        return response()->json([
            'message' => 'تمت إضافة المادة.',
            'data' => $course,
        ], 201);
    }

    public function update(Request $request, Course $course)
    {
        $this->authorize('update', $course);

        $data = $this->validatedData($request, true, $course);

        /*
         * الصفحة تتبع السنة والنوع دائمًا.
         *
         * نقل مادة من السنة الثانية إلى الثالثة يترك `page` على
         * `year2.html`، و dynamic-courses.js يرشّح بالصفحة حرفيًّا —
         * فتظلّ المادة معروضة في سنتها القديمة بعد أن انتقلت. التحديث
         * التلقائي هنا يغلق الباب على تناقض لا يظهر إلا للطالب.
         */
        if (array_key_exists('year', $data) || array_key_exists('course_type', $data)) {
            $data['page'] = $this->pageFor($data + [
                'year' => $course->year,
                'course_type' => $course->course_type,
            ]);
        }

        $course->update($data);

        return response()->json([
            'message' => 'تم تحديث المادة.',
            'data' => $course->fresh(),
        ]);
    }

    /**
     * إعادة ترتيب مواد فصل واحد — طلب واحد لا طلبًا لكل مادة.
     *
     * السحبة الواحدة تحرّك موضع كل ما بين المصدر والهدف، فإرسال طلب
     * لكل صفّ يعني عشرة طلبات متتابعة على استضافة مشتركة بطابور
     * متزامن. والحفظ داخل transaction: ترتيب نُصِّف نصفه أسوأ من
     * ترتيب لم يُحفظ، لأنه يبدو محفوظًا.
     */
    public function reorder(Request $request)
    {
        $this->authorize('create', Course::class);

        $data = $request->validate([
            'keys' => ['required', 'array', 'min:1', 'max:200'],
            'keys.*' => ['required', 'string', 'exists:courses,key'],
        ]);

        DB::transaction(function () use ($data) {
            foreach ($data['keys'] as $index => $key) {
                Course::where('key', $key)->update(['sort_order' => $index]);
            }
        });

        return response()->json([
            'message' => 'تم حفظ الترتيب.',
            'data' => ['count' => count($data['keys'])],
        ]);
    }

    /**
     * حذف ناعم — للمدير وحده.
     *
     * الحذف الفعلي كان يُطلق سلسلة cascade تمحو my_courses
     * و course_progress و course_files، أي تسجيلات كل الطلاب في المساق
     * وتقدّمهم فيه بلا استرجاع. الآن يُوسم بـ deleted_at فقط: يختفي من
     * كل الاستعلامات ولا تُطلق السلسلة، ويبقى استرجاعه ممكنًا.
     */
    public function destroy(Course $course)
    {
        $this->authorize('delete', $course);

        $course->delete();

        return response()->json(['message' => 'تم حذف المادة.']);
    }

    /** استرجاع مساق محذوف — للمدير وحده. */
    public function restore(string $key)
    {
        $course = Course::onlyTrashed()->where('key', $key)->firstOrFail();

        $this->authorize('restore', $course);

        $course->restore();

        return response()->json([
            'message' => 'تم استرجاع المادة.',
            'data' => $course->fresh(),
        ]);
    }

    /** المساقات المحذوفة — للمدير وحده، ليعرف ما يمكن استرجاعه. */
    public function trashed()
    {
        $this->authorize('viewTrashed', Course::class);

        return response()->json([
            'data' => Course::onlyTrashed()->latest('deleted_at')->get(),
        ]);
    }

    private function validatedData(Request $request, bool $partial = false, ?Course $course = null): array
    {
        return $request->validate([
            'key' => [
                $partial ? 'sometimes' : 'nullable',
                'string',
                'max:190',
                Rule::unique('courses', 'key')->ignore($course?->id),
            ],
            'code' => [$partial ? 'sometimes' : 'required', 'string', 'max:50'],
            'name_ar' => [$partial ? 'sometimes' : 'required', 'string', 'max:190'],
            'name_en' => ['sometimes', 'nullable', 'string', 'max:190'],
            'year' => [$partial ? 'sometimes' : 'required', 'integer', 'between:1,4'],
            /*
             * ١..٨ لا ١..٣: الفصل رقمٌ عالميّ عبر السنوات الأربع —
             * فصلا السنة N هما 2N-1 و 2N — لا رقمٌ داخل السنة. الحدّ
             * القديم كان يرفض كل مادة خارج السنة الأولى، ولم يظهر لأن
             * نموذج الإضافة كان معطَّلًا بالتعليق منذ كُتب.
             */
            'semester' => [$partial ? 'sometimes' : 'required', 'integer', 'between:1,8'],
            'page' => ['sometimes', 'nullable', 'string', 'max:100'],
            'keywords' => ['sometimes', 'nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],

            /*
             * الساعات nullable لا default 0: مساق أُنشئ بلا ساعات يجب
             * أن يظهر في عدّاد النقص لا أن يدخل الحساب صفرًا صامتًا.
             */
            'credit_hours' => ['sometimes', 'nullable', 'integer', 'between:0,20'],
            'course_type' => ['sometimes', Rule::in(['required', 'elective', 'placeholder'])],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'objectives' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);
    }

    private function makeKey(string $code): string
    {
        $clean = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '', $code) ?: Str::random(8));

        return 'c_'.$clean;
    }

    /**
     * الصفحة تُشتقّ ولا تُدخَل — وهذا يغلق أسوأ فشل في هذا المسار.
     *
     * dynamic-courses.js يحقن المواد المضافة في صفحات السنوات، لكنه
     * يرشّحها بـ `page === اسم الصفحة الحالية` حرفيًّا. فمادة تُحفظ
     * بـ page فارغة **تنجح في الحفظ وتختفي عن كل طالب**: لا رسالة خطأ،
     * ولا صفّ ناقص، ولا شيء يُرى في اللوحة. حقلٌ يُملأ يدويًّا هنا كان
     * سيُنسى مرة، ومرة واحدة تكفي.
     */
    private function pageFor(array $data): string
    {
        if (($data['course_type'] ?? 'required') === 'elective') {
            return 'electives.html';
        }

        $year = (int) ($data['year'] ?? 1);

        return 'year'.min(4, max(1, $year)).'.html';
    }

    /** المادة الجديدة تقع آخر فصلها — لا في مقدّمته ولا بترتيب الإدخال. */
    private function nextSortOrder(array $data): int
    {
        return ((int) Course::query()
            ->where('year', $data['year'] ?? 1)
            ->where('semester', $data['semester'] ?? 1)
            ->max('sort_order')) + 1;
    }
}
