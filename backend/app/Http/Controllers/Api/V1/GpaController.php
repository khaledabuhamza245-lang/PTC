<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\GpaEntry;
use Illuminate\Http\Request;

/*
 * حاسبة المعدل التراكمي — أداة تقدير شخصية للطالب، مستقلة تمامًا عن
 * my_courses (راجع تعليق هجرة gpa_entries لسبب الفصل). كل الحسابات
 * الفعلية (المعدل التراكمي، الفصلي، السنوي، "ماذا لو"، "شو لازم
 * أحصل عليه؟") تتم في المتصفح فور كل تعديل — هذا الكنترولر يخزّن
 * ويرجّع العلامات الخام فقط، بلا أي حساب هنا، لأن إعادة الحساب على
 * كل ضغطة مفتاح يجب أن تكون فورية بلا رحلة شبكة.
 */
class GpaController extends Controller
{
    /** كل علامات الطالب المدخَلة، بمفتاح المساق (key) لا رقمه الداخلي. */
    public function index(Request $request)
    {
        $entries = GpaEntry::query()
            ->where('user_id', $request->user()->id)
            ->with('course:id,key')
            ->get();

        $grades = [];
        foreach ($entries as $entry) {
            if ($entry->course) {
                $grades[$entry->course->key] = $entry->grade;
            }
        }

        return response()->json(['data' => ['grades' => $grades]]);
    }

    /** إدخال أو تحديث علامة مساق واحد. */
    public function upsert(Request $request, Course $course)
    {
        $data = $request->validate([
            'grade' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        $entry = GpaEntry::updateOrCreate(
            ['user_id' => $request->user()->id, 'course_id' => $course->id],
            ['grade' => round((float) $data['grade'], 2)],
        );

        return response()->json([
            'message' => 'تم حفظ العلامة.',
            'data' => ['key' => $course->key, 'grade' => $entry->grade],
        ]);
    }

    /** مسح علامة مساق واحد (تفريغ الخانة). */
    public function destroy(Request $request, Course $course)
    {
        GpaEntry::where('user_id', $request->user()->id)
            ->where('course_id', $course->id)
            ->delete();

        return response()->json(['message' => 'تم مسح العلامة.']);
    }

    /** تفريغ الخطة بالكامل — زر "تفريغ الخطة". */
    public function clear(Request $request)
    {
        GpaEntry::where('user_id', $request->user()->id)->delete();

        return response()->json(['message' => 'تم تفريغ كل العلامات.']);
    }
}
