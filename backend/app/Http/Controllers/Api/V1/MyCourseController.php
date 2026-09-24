<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MyCourseController extends Controller
{
    public function index(Request $request)
    {
        return response()->json([
            'data' => $request
                ->user()
                ->myCourses()
                ->orderBy('semester')
                ->get(),
        ]);
    }

    /**
     * إضافة مساق يدويًا إلى مساقات الطالب الحالية.
     */
    public function store(Request $request, Course $course)
    {
        abort_unless($course->is_active, 404);

        $user = $request->user();

        /*
         * الاختياري لا يُختار إلا وهو داخل الفصل المُعلَن حاليًا:
         * لا معنى لأن يسجّل طالب في سنة ٢ فصل ١ مادةً اختياريةً
         * مكانها الحقيقي في خطة سنة ٣ فصل ٢ لم يصلها بعد. القيد هنا
         * لا في الواجهة وحدها كي لا يلتفّ عليه طلبٌ مباشر للـ API.
         */
        if ($course->course_type === 'elective') {
            $planSemester = $this->currentPlanSemester($user);

            $hasOpenSlot = $planSemester && Course::query()
                ->where('is_active', true)
                ->where('course_type', 'placeholder')
                ->where('year', (int) $user->year)
                ->where('semester', $planSemester)
                ->exists();

            if (! $hasOpenSlot) {
                return response()->json([
                    'message' => 'ما في خانة اختيارية متاحة إلك بفصلك الدراسي الحالي.',
                ], 422);
            }
        }

        $existing = DB::table('my_courses')
            ->where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->first();

        if (! $existing) {
            DB::table('my_courses')->insert([
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
            $update = [
                'source' => 'manual',
                'updated_at' => now(),
            ];

            if (($existing->status ?? 'registered') === 'dropped') {
                $update['status'] = 'registered';
                $update['term_id'] = $user->current_term_id;
                $update['completed_at'] = null;
            } elseif (($existing->status ?? 'registered') === 'registered') {
                if ($user->current_term_id) {
                    $update['term_id'] = $user->current_term_id;
                }
            }

            DB::table('my_courses')
                ->where('user_id', $user->id)
                ->where('course_id', $course->id)
                ->update($update);
        }

        return response()->json([
            'message' => 'تمت إضافة المساق.',
        ], 201);
    }

    /**
     * تحديث حالة المساق.
     */
    public function update(Request $request, Course $course)
    {
        $user = $request->user();

        $existing = DB::table('my_courses')
            ->where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->first();

        abort_unless($existing, 404);

        $data = $request->validate([
            'status' => [
                'sometimes',
                Rule::in([
                    'registered',
                    'completed',
                    'dropped',
                ]),
            ],

            'term_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('terms', 'id'),
            ],

            'grade' => [
                'sometimes',
                'nullable',
                'string',
                'max:5',
            ],
        ]);

        $pivot = [];

        foreach (['term_id', 'grade'] as $field) {
            if (array_key_exists($field, $data)) {
                $pivot[$field] = $data[$field];
            }
        }

        if (array_key_exists('status', $data)) {
            $pivot['status'] = $data['status'];

            if ($data['status'] === 'completed') {
                $pivot['completed_at'] =
                    $existing->completed_at ?: now();
            } else {
                $pivot['completed_at'] = null;
            }
        }

        if ($pivot) {
            /*
             * أي تغيير يدوي من الطالب نحفظه manual
             * حتى لا تأتي المزامنة وتغير قراره لاحقًا.
             */
            $pivot['source'] = 'manual';
            $pivot['updated_at'] = now();

            DB::table('my_courses')
                ->where('user_id', $user->id)
                ->where('course_id', $course->id)
                ->update($pivot);
        }

        $updated = DB::table('my_courses')
            ->where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->first();

        return response()->json([
            'message' => 'تم تحديث حالة المساق.',

            'data' => [
                'key' => $course->key,
                'status' => $updated->status,
                'term_id' => $updated->term_id,
                'grade' => $updated->grade,
                'completed_at' => $updated->completed_at,
            ],
        ]);
    }

    /**
     * حذف مساق.
     *
     * مساق الفصل الحالي لا نحذفه نهائيًا من DB،
     * لأن المزامنة ستعيد إضافته.
     *
     * نسجل أن الطالب استبعده يدويًا.
     */
    public function destroy(Request $request, Course $course)
    {
        $user = $request->user();

        $existing = DB::table('my_courses')
            ->where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->first();

        if (! $existing) {
            return response()->json([
                'message' => 'تم حذف المساق.',
            ]);
        }

        $isAutomatic =
            ($existing->source ?? 'manual') === 'automatic';

        if (
            $isAutomatic
            || $this->isCurrentSuggestedCourse($user, $course)
        ) {
            DB::table('my_courses')
                ->where('user_id', $user->id)
                ->where('course_id', $course->id)
                ->update([
                    'status' => 'dropped',
                    'source' => 'manual',
                    'completed_at' => null,
                    'updated_at' => now(),
                ]);
        } else {
            DB::table('my_courses')
                ->where('user_id', $user->id)
                ->where('course_id', $course->id)
                ->delete();
        }

        return response()->json([
            'message' => 'تم حذف المساق.',
        ]);
    }

    /**
     * رقم الفصل العالمي (١..٨) بحسب سنة الطالب المعلَنة وفصل تقويمه
     * الحالي. null إن كانت السنة أو الفصل غير محدَّدين.
     */
    private function currentPlanSemester(User $user): ?int
    {
        $user->loadMissing('currentTerm');

        $term = $user->currentTerm;
        $year = (int) $user->year;

        if (
            ! $year
            || ! $term
            || ! in_array((int) $term->semester, [1, 2], true)
        ) {
            return null;
        }

        return (($year - 1) * 2) + (int) $term->semester;
    }

    /**
     * هل هذا المساق من مواد السنة والفصل
     * المحددين حاليًا في الملف الشخصي؟
     */
    private function isCurrentSuggestedCourse(
        User $user,
        Course $course
    ): bool {
        $user->loadMissing('currentTerm');

        $term = $user->currentTerm;
        $year = (int) $user->year;

        if (
            ! $year
            || ! $term
            || ! in_array(
                (int) $term->semester,
                [1, 2],
                true
            )
        ) {
            return false;
        }

        /*
         * Course.semester:
         *
         * السنة الأولى  = 1,2
         * السنة الثانية = 3,4
         * السنة الثالثة = 5,6
         * السنة الرابعة = 7,8
         */
        $planSemester =
            (($year - 1) * 2)
            + (int) $term->semester;

        return
            (bool) $course->is_active
            && (int) $course->year === $year
            && (int) $course->semester === $planSemester
            && in_array(
                $course->course_type,
                ['required', 'placeholder'],
                true
            );
    }
}