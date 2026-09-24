<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Course;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CoursePrepTopicController extends Controller
{
    public function index(Course $course)
    {
        abort_unless($course->is_active, 404);

        return response()->json([
            'data' => $course->prepTopics()->get(['id', 'topic', 'link', 'sort_order']),
        ]);
    }

    /**
     * استبدال كامل داخل transaction.
     *
     * الاستبدال لا الترقيع: القائمة تُحرَّر كوحدة في اللوحة، وحذفٌ ثم
     * إدراجٌ خارج transaction يترك المساق بلا مواضيع إن فشل الإدراج.
     */
    public function replace(Request $request, Course $course)
    {
        $this->authorize('update', $course);

        $data = $request->validate([
            'topics' => ['present', 'array', 'max:100'],
            'topics.*.topic' => ['required', 'string', 'max:200'],
            'topics.*.link' => ['nullable', 'url', 'max:500'],
        ]);

        DB::transaction(function () use ($course, $data) {
            $course->prepTopics()->delete();

            foreach (array_values($data['topics']) as $index => $topic) {
                $course->prepTopics()->create([
                    'topic' => $topic['topic'],
                    'link' => $topic['link'] ?? null,
                    'sort_order' => $index + 1,
                ]);
            }
        });

        return response()->json([
            'message' => 'تم تحديث مواضيع المراجعة.',
            'data' => $course->prepTopics()->get(['id', 'topic', 'link', 'sort_order']),
        ]);
    }
}
