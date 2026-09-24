<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseProgress;
use Illuminate\Http\Request;

class CourseProgressController extends Controller
{
    public function index(Request $request)
    {
        $items = $request->user()->progress()->with('course:id,key,code,name_ar,name_en')->get();

        return response()->json(['data' => $items]);
    }

    public function show(Request $request, Course $course)
    {
        $progress = $request->user()->progress()->where('course_id', $course->id)->first();

        return response()->json(['data' => $progress?->data]);
    }

    public function update(Request $request, Course $course)
    {
        $data = $request->validate([
            'data' => ['required', 'array'],
        ]);

        $jsonSize = strlen(json_encode($data['data'], JSON_UNESCAPED_UNICODE));
        $maxSize = config('files.progress_max_kb') * 1024;

        if ($jsonSize > $maxSize) {
            return response()->json([
                'message' => 'بيانات التقدم كبيرة جدًا. ارفع الصور والملفات بشكل منفصل.',
            ], 422);
        }

        $progress = CourseProgress::updateOrCreate(
            ['user_id' => $request->user()->id, 'course_id' => $course->id],
            ['data' => $data['data']],
        );

        return response()->json([
            'message' => 'تم حفظ التقدم.',
            'data' => $progress,
        ]);
    }
}
