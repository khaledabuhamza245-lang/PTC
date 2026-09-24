<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseFile;
use App\Models\Tool;
use Illuminate\Http\Request;

/*
 * بحث موحّد عبر ثلاث فئات مختلفة تمامًا (مواد، محتوى، أدوات) بطلب
 * واحد — لا حاجة لأي حراسة، فكل ما يُرجعه عام أصلًا.
 *
 * الدقّة مقصودة قبل الشمول: البحث بالاسم (والرمز والكلمات المفتاحية
 * للمساق تحديدًا، فهي مُعَدَّة أصلًا لهذا الغرض) — لا بنص الوصف الطويل،
 * الذي أثبت أنه يُخرج نتائج غير متوقَّعة (كلمة عابرة داخل جملة وصف
 * تُطابق بحثًا لا علاقة له بالأداة فعليًا).
 */
class SearchController extends Controller
{
    private const MIN_LENGTH = 1;

    public function index(Request $request)
    {
        $term = trim((string) $request->string('q'));

        if (mb_strlen($term) < self::MIN_LENGTH) {
            return response()->json([
                'data' => ['courses' => [], 'content' => [], 'tools' => []],
            ]);
        }

        $escaped = str_replace(['%', '_'], ['\%', '\_'], $term);
        $contains = '%'.$escaped.'%';
        $prefix = $escaped.'%';

        /*
         * الترتيب حسب الصلة لا حسب ما يعيده الجدول اعتباطًا: مطابقة
         * أول الاسم أهمّ من مطابقة تقع في وسطه، فتصعد لأعلى النتائج.
         */
        $relevance = fn ($column) => "(CASE WHEN {$column} LIKE ? THEN 0 ELSE 1 END)";

        $courses = Course::query()
            ->where('is_active', true)
            ->where(function ($query) use ($contains) {
                $query->where('name_ar', 'like', $contains)
                    ->orWhere('name_en', 'like', $contains)
                    ->orWhere('code', 'like', $contains)
                    ->orWhere('keywords', 'like', $contains);
            })
            ->orderByRaw($relevance('name_ar'), [$prefix])
            ->limit(6)
            ->get(['id', 'key', 'code', 'name_ar', 'name_en', 'page']);

        $content = CourseFile::query()
            ->where('is_published', true)
            ->where('title', 'like', $contains)
            ->orderByRaw($relevance('title'), [$prefix])
            ->with('course:id,key,code,name_ar,name_en,page')
            ->limit(8)
            ->get(['id', 'course_id', 'title', 'kind']);

        $tools = Tool::query()
            ->where('is_active', true)
            ->where('name', 'like', $contains)
            ->orderByRaw($relevance('name'), [$prefix])
            ->limit(6)
            ->get(['id', 'name', 'type', 'description']);

        return response()->json([
            'data' => [
                'courses' => $courses,
                'content' => $content,
                'tools' => $tools,
            ],
        ]);
    }
}
