<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Term;
use App\Support\PublicCache;

class TermController extends Controller
{
    /**
     * (خطوة ١٠٣) بلا أي تخصيص شخصي — تُخزَّن مؤقتًا عبر PublicCache
     * لتخفيف التزاحم وقت الذروة (راجع تعليل الملف نفسه).
     */
    public function index()
    {
        $data = PublicCache::rememberTerms(fn () => [
            'data' => Term::ordered()->get(),
            'current' => Term::current(),
        ]);

        return response()->json($data);
    }
}
