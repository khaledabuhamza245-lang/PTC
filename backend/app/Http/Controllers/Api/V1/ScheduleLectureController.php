<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ScheduleLecture;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/*
 * كل عملية هنا مقيَّدة بصاحب الجدول نفسه — where('user_id', ...) قبل
 * أي قراءة أو كتابة، ومحاولة الوصول لمحاضرة تخصّ طالبًا آخر تُرفَض
 * كأنها غير موجودة أصلًا (404) لا "ممنوع" (403)، فلا يُكشف حتى عن
 * وجودها.
 */
class ScheduleLectureController extends Controller
{
    private function validated(Request $request): array
    {
        return $request->validate([
            'course_key' => ['nullable', 'string', 'max:60'],
            'name' => ['required', 'string', 'max:150'],
            'instructor' => ['nullable', 'string', 'max:150'],
            'type' => ['required', Rule::in(['in_person', 'online'])],
            'days' => ['required', 'array', 'min:1'],
            'days.*' => ['integer', 'between:0,6'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'color' => ['nullable', 'string', 'max:20'],
        ]);
    }

    public function index(Request $request)
    {
        $lectures = ScheduleLecture::where('user_id', $request->user()->id)
            ->orderBy('start_time')
            ->get();

        return response()->json(['data' => $lectures]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['user_id'] = $request->user()->id;

        $lecture = ScheduleLecture::create($data);

        return response()->json(['data' => $lecture], 201);
    }

    public function update(Request $request, ScheduleLecture $scheduleLecture)
    {
        if ($scheduleLecture->user_id !== $request->user()->id) {
            abort(404);
        }

        $scheduleLecture->update($this->validated($request));

        return response()->json(['data' => $scheduleLecture->fresh()]);
    }

    public function destroy(Request $request, ScheduleLecture $scheduleLecture)
    {
        if ($scheduleLecture->user_id !== $request->user()->id) {
            abort(404);
        }

        $scheduleLecture->delete();

        return response()->json(['message' => 'تم حذف المحاضرة.']);
    }

    public function clear(Request $request)
    {
        ScheduleLecture::where('user_id', $request->user()->id)->delete();

        return response()->json(['message' => 'تم مسح الجدول بالكامل.']);
    }
}
