<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseFile;
use App\Models\User;

class DashboardController extends Controller
{
    public function __invoke()
    {
        return response()->json([
            'data' => [
                'users' => User::count(),
                'students' => User::where('role', 'student')->count(),
                'courses' => Course::where('is_active', true)->count(),
                'files' => CourseFile::where('status', 'ready')->count(),

                /*
                 * «كم طالب انضم في الساعات الأخيرة» — طلب صريح من العميل.
                 *
                 * الحدّ زمني لا يومي: subDay() يعني «آخر ٢٤ ساعة» لا «منذ
                 * منتصف الليل». الثاني يعطي رقمًا يتصاغر كلما بكّر النظر
                 * إليه، فيقرأه الطاقم صباحًا صفرًا ويظنّ التسجيل متوقفًا.
                 *
                 * والقيد على الطلاب وحدهم — كما في سلسلة StatsController
                 * تمامًا. لولاه لعدّ العددان حسابات الطاقم فاختلف الرقمان
                 * فوق الرسم عن الرسم نفسه، وهما جنب بعض في الشاشة.
                 */
                'signups_24h' => User::where('role', 'student')
                    ->where('created_at', '>=', now()->subDay())->count(),

                'signups_7d' => User::where('role', 'student')
                    ->where('created_at', '>=', now()->subDays(7))->count(),

                'students_by_year' => $this->studentsByYear(),
            ],
        ]);
    }

    /**
     * توزيع الطلاب على السنوات — بشكل ثابت لا يتبع البيانات.
     *
     * السنوات الأربع تُعاد دائمًا ولو بصفر، ومعها خانة «غير محدَّدة»
     * لمن سجّل بلا سنة. لو أُعيد ما تعطيه القاعدة وحدها لاختفت السنة
     * الفارغة من الرسم البياني، فيقرأ الطاقم ثلاثة أعمدة على أنها كل
     * السنوات — وهو غياب بيانات يُقرأ كبيانات.
     *
     * @return list<array{year:int|null,total:int}>
     */
    private function studentsByYear(): array
    {
        $counts = User::query()
            ->where('role', 'student')
            ->groupBy('year')
            ->selectRaw('year, COUNT(*) as total')
            ->get()
            ->mapWithKeys(fn ($row) => [
                (string) ($row->year ?? 'none') => (int) $row->total,
            ]);

        $rows = [];

        foreach ([1, 2, 3, 4] as $year) {
            $rows[] = [
                'year' => $year,
                'total' => $counts[(string) $year] ?? 0,
            ];
        }

        $rows[] = [
            'year' => null,
            'total' => $counts['none'] ?? 0,
        ];

        return $rows;
    }
}
