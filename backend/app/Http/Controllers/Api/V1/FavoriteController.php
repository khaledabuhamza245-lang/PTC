<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CourseFile;
use App\Models\Favorite;
use Illuminate\Http\Request;

class FavoriteController extends Controller
{
    /**
     * قائمة "مكتبتي" — كل المحتوى الذي حفظه المستخدم، مع سياق المادة
     * الذي يخصّه (اسمها ومفتاحها)، الأحدث حفظًا أولًا.
     */
    public function index(Request $request)
    {
        $favorites = Favorite::query()
            ->where('user_id', $request->user()->id)
            ->with([
                'courseFile:id,course_id,title,kind',
                'courseFile.course:id,key,code,name_ar,name_en',
            ])
            ->latest()
            ->get()
            ->pluck('courseFile')
            ->filter();

        return response()->json(['data' => $favorites->values()]);
    }

    /**
     * أرقام الملفات المفضَّلة فقط — بلا أي تفاصيل أخرى. تُستخدم بصفحة
     * المساق لتحديد أي عناصر تُعرض نجمتها معبّأة عند التحميل، دون
     * تحميل قائمة "مكتبتي" الكاملة بكل صفحة مساق يفتحها الطالب.
     */
    public function ids(Request $request)
    {
        $ids = Favorite::query()
            ->where('user_id', $request->user()->id)
            ->pluck('course_file_id');

        return response()->json(['data' => $ids]);
    }

    public function store(Request $request, CourseFile $courseFile)
    {
        Favorite::firstOrCreate([
            'user_id' => $request->user()->id,
            'course_file_id' => $courseFile->id,
        ]);

        return response()->json(['message' => 'أُضيف للمفضّلة.'], 201);
    }

    public function destroy(Request $request, CourseFile $courseFile)
    {
        Favorite::query()
            ->where('user_id', $request->user()->id)
            ->where('course_file_id', $courseFile->id)
            ->delete();

        return response()->json(['message' => 'أُزيل من المفضّلة.']);
    }
}
