<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Tool;
use App\Support\PublicCache;

class ToolController extends Controller
{
    /**
     * دليل الأدوات كاملًا لصفحة الأدوات.
     *
     * بلا ترقيم صفحات: القائمة تُصنَّف وتُرشَّح في المتصفح، وترقيمها
     * يعني طلبًا جديدًا مع كل نقرة مرشِّح. وهي مئة صفّ نحيف لا أكثر.
     *
     * (خطوة ١٠٣) بلا أي تخصيص شخصي — تُخزَّن مؤقتًا عبر PublicCache
     * لتخفيف التزاحم وقت الذروة (راجع تعليل الملف نفسه).
     */
    public function index()
    {
        $tools = PublicCache::rememberTools(fn () => Tool::query()
            ->where('is_active', true)
            ->with(['courses' => fn ($query) => $query
                ->where('is_active', true)
                ->select('courses.id', 'key', 'code', 'name_ar', 'year'),
            ])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (Tool $tool) => [
                'id' => $tool->id,
                'name' => $tool->name,
                'type' => $tool->type,
                'description' => $tool->description,
                'official_url' => $tool->official_url,
                'video_url' => $tool->video_url,
                'explanation' => $tool->explanation,
                'courses' => $tool->courses->map(fn ($course) => [
                    'key' => $course->key,
                    'code' => $course->code,
                    'name_ar' => $course->name_ar,
                    'year' => $course->year,
                ])->values(),
            ])
            ->toArray());

        return response()->json(['data' => $tools]);
    }
}
