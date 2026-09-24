<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ContentReport;
use App\Models\Course;
use App\Models\CourseFile;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ContentReportController extends Controller
{
    /*
     * البلاغ إداري صرف: يصل للطاقم فقط، ولا واجهة لعرضه لأي طالب —
     * فلا حاجة لدالة index هنا إطلاقًا.
     */
    public function store(Request $request, CourseFile $courseFile)
    {
        $data = $request->validate([
            'reason' => ['required', Rule::in(array_keys(ContentReport::REASONS))],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $report = $courseFile->reports()->create([
            'course_id' => $courseFile->course_id,
            'user_id' => $request->user()->id,
            'reason' => $data['reason'],
            'note' => $data['note'] ?? null,
        ]);

        return response()->json([
            'message' => 'تم إرسال البلاغ، شكرًا لمساعدتك بتحسين المحتوى.',
            'data' => $report->only(['id', 'reason', 'created_at']),
        ], 201);
    }

    /*
     * زر واحد أعلى صفحة المساق كلها لا زر لكل عنصر — الطالب يحدد من
     * الحوار نفسه إن كان البلاغ عن عنصر بعينه أو عن المساق عمومًا
     * (اسم غير صحيح، تصنيف خاطئ...)، فcourse_file_id هنا اختياري.
     */
    public function storeForCourse(Request $request, Course $course)
    {
        $data = $request->validate([
            'course_file_id' => [
                'nullable',
                Rule::exists('course_files', 'id')
                    ->where('course_id', $course->id),
            ],
            'reason' => ['required', Rule::in(array_keys(ContentReport::REASONS))],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $report = ContentReport::create([
            'course_id' => $course->id,
            'course_file_id' => $data['course_file_id'] ?? null,
            'user_id' => $request->user()->id,
            'reason' => $data['reason'],
            'note' => $data['note'] ?? null,
        ]);

        return response()->json([
            'message' => 'تم إرسال البلاغ، شكرًا لمساعدتك بتحسين المحتوى.',
            'data' => $report->only(['id', 'reason', 'created_at']),
        ], 201);
    }
}
