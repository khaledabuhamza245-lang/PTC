<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Course;
use Illuminate\Http\Request;

class CourseTipController extends Controller
{
    /*
     * صندوق الخبرة يُعرض مجهولًا تمامًا: لا نرسل user_id ولا أي أثر
     * لهوية كاتب النصيحة إلى الواجهة — المساءلة موجودة داخليًا فقط
     * (تظهر لموظفي الإدارة عبر لوحة التحكم عند الحاجة للمراجعة).
     */
    public function index(Course $course)
    {
        $tips = $course->tips()
            ->select(['id', 'message', 'created_at'])
            ->get();

        return response()->json([
            'data' => $tips,
        ]);
    }

    public function store(Request $request, Course $course)
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        $tip = $course->tips()->create([
            'user_id' => $request->user()->id,
            'message' => $data['message'],
        ]);

        return response()->json([
            'message' => 'تم حفظ نصيحتك، شكرًا لمشاركتها مع زملائك.',
            'data' => $tip->only(['id', 'message', 'created_at']),
        ], 201);
    }
}
