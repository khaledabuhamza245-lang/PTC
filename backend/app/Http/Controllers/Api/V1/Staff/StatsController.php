<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class StatsController extends Controller
{
    /** أقصى مدى مسموح — يمنع طلبًا بـ ?days=100000 يمسح الجدول كله. */
    private const MAX_DAYS = 90;

    /**
     * سلسلة الانضمامات اليومية — تغذّي رسم «انضمام الطلاب».
     *
     * السلسلة تُعاد **كثيفة**: كل يوم في المدى حاضر ولو بصفر. التكثيف
     * هنا لا في المتصفح، لأن الرسم الشريطي بلا أيام صفرية يكذب — يرسم
     * الأسبوع الخالي جنب الممتلئ بعرض واحد فيبدو التسجيل متصلًا.
     *
     * والتجميع بـ DATE() يعمل على SQLite وMySQL معًا، وتوقيت التطبيق
     * Asia/Gaza فاليوم يُحسب محليًّا لا بتوقيت غرينتش.
     */
    public function signups(Request $request)
    {
        $days = max(1, min($request->integer('days', 30), self::MAX_DAYS));

        $from = now()->startOfDay()->subDays($days - 1);

        $counts = User::query()
            ->where('role', 'student')
            ->where('created_at', '>=', $from)
            ->groupBy('day')
            ->selectRaw('DATE(created_at) as day, COUNT(*) as total')
            ->get()
            ->mapWithKeys(fn ($row) => [
                /* MySQL يعيد سلسلة و SQLite كذلك، لكن الوسم قد يحمل وقتًا. */
                substr((string) $row->day, 0, 10) => (int) $row->total,
            ]);

        $series = [];

        for ($offset = 0; $offset < $days; $offset++) {
            $date = $from->copy()->addDays($offset)->toDateString();

            $series[] = [
                'date' => $date,
                'total' => $counts[$date] ?? 0,
            ];
        }

        return response()->json([
            'data' => [
                'days' => $days,
                'total' => array_sum(array_column($series, 'total')),
                'series' => $series,
            ],
        ]);
    }
}
