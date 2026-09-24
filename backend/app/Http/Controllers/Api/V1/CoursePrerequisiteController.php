<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CoursePrerequisite;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CoursePrerequisiteController extends Controller
{
    /** متطلبات المساق، و«مطلوب لـ» — الاتجاهان في طلب واحد. */
    public function show(Course $course)
    {
        abort_unless($course->is_active, 404);

        return response()->json([
            'data' => [
                'prerequisites' => $this->present($course->prerequisites()->with('prerequisite')->get()),
                'required_for' => $course->requiredFor()
                    ->with('course')
                    ->get()
                    ->map(fn (CoursePrerequisite $row) => $this->courseBrief($row->course))
                    ->filter()
                    ->values(),
            ],
        ]);
    }

    /**
     * إضافة متطلب — للطاقم.
     *
     * ثلاثة رفض قبل الحفظ: الدور الذاتي، والمسار الدائري، والتكرار.
     * الدائري ليس احتمالًا نظريًا: أي واجهة تعرض شجرة المتطلبات
     * تدخل حلقة لا نهائية عند أول دورة، والقاعدة لا تمنعها.
     */
    public function store(Request $request, Course $course)
    {
        $this->authorize('update', $course);

        $data = $request->validate([
            'prerequisite_key' => ['nullable', 'string', Rule::exists('courses', 'key')],
            'prerequisite_code_raw' => ['nullable', 'string', 'max:50'],
        ]);

        $prerequisite = ! empty($data['prerequisite_key'])
            ? Course::where('key', $data['prerequisite_key'])->first()
            : null;

        if (! $prerequisite && empty($data['prerequisite_code_raw'])) {
            return response()->json([
                'message' => 'حدّد مساقًا موجودًا أو اكتب رمز المتطلب نصًّا.',
            ], 422);
        }

        if ($prerequisite && $prerequisite->id === $course->id) {
            return response()->json(['message' => 'المساق لا يكون متطلبًا لنفسه.'], 422);
        }

        if ($prerequisite && $this->createsCycle($course, $prerequisite)) {
            return response()->json([
                'message' => 'هذا المتطلب يصنع مسارًا دائريًا — المساق مطلوب لهذا المتطلب مباشرة أو عبر سلسلة.',
            ], 422);
        }

        if ($prerequisite) {
            $row = CoursePrerequisite::updateOrCreate(
                ['course_id' => $course->id, 'prerequisite_course_id' => $prerequisite->id],
                ['prerequisite_code_raw' => $prerequisite->code, 'needs_review' => false],
            );
        } else {
            /* الرمز النصّي لا يحرسه القيد الفريد (NULL في MySQL) — firstOrCreate يحرسه. */
            $row = CoursePrerequisite::firstOrCreate(
                [
                    'course_id' => $course->id,
                    'prerequisite_course_id' => null,
                    'prerequisite_code_raw' => $data['prerequisite_code_raw'],
                ],
                ['needs_review' => true],
            );
        }

        return response()->json([
            'message' => 'تمت إضافة المتطلب.',
            'data' => $row->fresh('prerequisite'),
        ], 201);
    }

    public function destroy(Course $course, CoursePrerequisite $prerequisite)
    {
        $this->authorize('update', $course);

        /* المتطلب يُحذف من مساقه وحده — معرّف من مساق آخر لا يُقبل. */
        abort_unless($prerequisite->course_id === $course->id, 404);

        $prerequisite->delete();

        return response()->json(['message' => 'تم حذف المتطلب.']);
    }

    /**
     * هل يصنع الربط دورة؟ بحث بالعرض من المتطلب المقترح صعودًا.
     *
     * العمق ≤٥ لأن أطول سلسلة متطلبات واقعية في خطة من أربع سنوات
     * أقصر من ذلك بكثير، والحدّ يمنع أي التفاف على بيانات معطوبة
     * من أن يعلّق الطلب.
     */
    private function createsCycle(Course $course, Course $prerequisite): bool
    {
        $frontier = [$prerequisite->id];
        $seen = [$prerequisite->id => true];

        for ($depth = 0; $depth < 5 && $frontier; $depth++) {
            if (in_array($course->id, $frontier, true)) {
                return true;
            }

            $next = CoursePrerequisite::whereIn('course_id', $frontier)
                ->whereNotNull('prerequisite_course_id')
                ->pluck('prerequisite_course_id')
                ->all();

            $frontier = [];

            foreach ($next as $id) {
                if (! isset($seen[$id])) {
                    $seen[$id] = true;
                    $frontier[] = $id;
                }
            }
        }

        return in_array($course->id, array_keys($seen), true);
    }

    private function present($rows)
    {
        return $rows->map(fn (CoursePrerequisite $row) => [
            'id' => $row->id,
            'needs_review' => $row->needs_review,
            'code' => $row->prerequisite?->code ?? $row->prerequisite_code_raw,
            'course' => $this->courseBrief($row->prerequisite),
        ])->values();
    }

    private function courseBrief(?Course $course): ?array
    {
        return $course ? [
            'key' => $course->key,
            'code' => $course->code,
            'name_ar' => $course->name_ar,
            'name_en' => $course->name_en,
            'year' => $course->year,
            'semester' => $course->semester,
            'credit_hours' => $course->credit_hours,
        ] : null;
    }
}
